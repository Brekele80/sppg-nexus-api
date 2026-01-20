<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\AuthUser;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class DcReceiptController extends Controller
{
    /**
     * POST /api/dc/pos/{po}/receipts
     * Create GR from PO (idempotent via middleware + DB unique if you have it).
     */
    public function createFromPo(Request $request, string $po)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowedBranches = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranches)) {
            return response()->json(['error' => ['code' => 'no_branch_access', 'message' => 'No branch access']], 403);
        }

        return DB::transaction(function () use ($request, $u, $companyId, $allowedBranches, $po) {

            /** @var PurchaseOrder $poModel */
            $poModel = PurchaseOrder::query()
                ->where('purchase_orders.id', $po)
                ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
                ->where('b.company_id', $companyId)
                ->select('purchase_orders.*')
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($poModel->branch_id, $allowedBranches, true)) {
                abort(403, 'Forbidden (no branch access)');
            }

            // If GR already exists for PO, return it WITHOUT auditing (avoid double-audit)
            $existing = GoodsReceipt::where('purchase_order_id', $poModel->id)->first();
            if ($existing) {
                return response()->json($this->loadGrGuarded($request, $companyId, $existing->id), 200);
            }

            $grNumber = 'GR-' . now()->format('Ymd') . '-' . random_int(100000, 999999);
            $grId = (string) Str::uuid();

            $gr = GoodsReceipt::create([
                'id' => $grId,
                'branch_id' => $poModel->branch_id,
                'purchase_order_id' => $poModel->id,
                'gr_number' => $grNumber,
                'status' => 'DRAFT',
                'created_by' => $u->id,
                'notes' => null,
                'meta' => null,
            ]);

            // Copy PO items into GR items
            $poItems = PurchaseOrderItem::where('purchase_order_id', $poModel->id)->get();

            foreach ($poItems as $poi) {
                GoodsReceiptItem::create([
                    'id' => (string) Str::uuid(),
                    'goods_receipt_id' => $gr->id,
                    'purchase_order_item_id' => $poi->id,
                    'item_name' => $poi->item_name,
                    'unit' => $poi->unit,
                    'ordered_qty' => $poi->qty,
                    'received_qty' => 0,
                    'rejected_qty' => 0,
                    // NOTE: schema uses remarks/discrepancy_reason; do not write unknown columns.
                    'remarks' => null,
                    'discrepancy_reason' => null,
                ]);
            }

            Audit::log($request, 'create', 'goods_receipts', $gr->id, [
                'purchase_order_id' => $poModel->id,
                'branch_id' => $poModel->branch_id,
                'gr_number' => $grNumber,
                'items_count' => (int) $poItems->count(),
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadGrGuarded($request, $companyId, $gr->id), 200);
        });
    }

    /**
     * PATCH /api/dc/receipts/{gr}
     * Update GR line quantities while DRAFT only.
     */
    public function update(Request $request, string $gr)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|uuid',
            'items.*.received_qty' => 'required|numeric|min:0',
            'items.*.rejected_qty' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $u, $companyId, $gr, $data) {

            /** @var GoodsReceipt $grModel */
            $grModel = GoodsReceipt::query()->lockForUpdate()->findOrFail($gr);

            $ok = DB::table('branches')
                ->where('id', $grModel->branch_id)
                ->where('company_id', $companyId)
                ->exists();
            if (!$ok) abort(404, 'Not found');

            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array($grModel->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            if ($grModel->status !== 'DRAFT') {
                return response()->json(['error' => ['code' => 'gr_not_editable', 'message' => 'Only DRAFT GR can be updated']], 409);
            }

            $items = GoodsReceiptItem::where('goods_receipt_id', $grModel->id)->get()->keyBy('id');
            $changes = [];

            foreach ($data['items'] as $row) {
                $itemId = (string) $row['id'];
                if (!isset($items[$itemId])) abort(422, 'Invalid GR item');

                /** @var GoodsReceiptItem $it */
                $it = $items[$itemId];

                $prev = [
                    'received_qty' => (float) $it->received_qty,
                    'rejected_qty' => (float) $it->rejected_qty,
                ];

                $it->received_qty = (float) $row['received_qty'];
                $it->rejected_qty = (float) $row['rejected_qty'];
                $it->save();

                if ($prev['received_qty'] !== (float) $it->received_qty || $prev['rejected_qty'] !== (float) $it->rejected_qty) {
                    $changes[] = [
                        'goods_receipt_item_id' => (string) $it->id,
                        'purchase_order_item_id' => (string) $it->purchase_order_item_id,
                        'item_name' => (string) $it->item_name,
                        'unit' => (string) $it->unit,
                        'ordered_qty' => (float) $it->ordered_qty,
                        'before' => $prev,
                        'after' => [
                            'received_qty' => (float) $it->received_qty,
                            'rejected_qty' => (float) $it->rejected_qty,
                        ],
                    ];
                }
            }

            if (!empty($changes)) {
                Audit::log($request, 'update', 'goods_receipts', $grModel->id, [
                    'branch_id' => (string) $grModel->branch_id,
                    'changes' => $changes,
                    'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
                ]);
            }

            return response()->json($this->loadGrGuarded($request, $companyId, $grModel->id), 200);
        });
    }

    /**
     * POST /api/dc/receipts/{gr}/submit
     */
    public function submit(Request $request, string $gr)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        return DB::transaction(function () use ($request, $u, $companyId, $gr) {

            /** @var GoodsReceipt $grModel */
            $grModel = GoodsReceipt::query()->lockForUpdate()->findOrFail($gr);

            $ok = DB::table('branches')
                ->where('id', $grModel->branch_id)
                ->where('company_id', $companyId)
                ->exists();
            if (!$ok) abort(404, 'Not found');

            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array($grModel->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            if ($grModel->status !== 'DRAFT') {
                return response()->json(['error' => ['code' => 'gr_not_submittable', 'message' => 'Only DRAFT GR can be submitted']], 409);
            }

            $grModel->status = 'SUBMITTED';
            $grModel->submitted_at = now();
            $grModel->submitted_by = $u->id;
            $grModel->save();

            Audit::log($request, 'submit', 'goods_receipts', $grModel->id, [
                'from' => 'DRAFT',
                'to' => 'SUBMITTED',
                'branch_id' => (string) $grModel->branch_id,
                'submitted_at' => (string) $grModel->submitted_at,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadGrGuarded($request, $companyId, $grModel->id), 200);
        });
    }

    /**
     * POST /api/dc/receipts/{gr}/receive
     * Finalizes GR and POSTS inventory lots + movements (idempotent).
     */
    public function receive(Request $request, string $gr)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        return DB::transaction(function () use ($request, $u, $companyId, $gr) {

            /** @var GoodsReceipt $grModel */
            $grModel = GoodsReceipt::query()->lockForUpdate()->findOrFail($gr);

            $ok = DB::table('branches')
                ->where('id', $grModel->branch_id)
                ->where('company_id', $companyId)
                ->exists();
            if (!$ok) abort(404, 'Not found');

            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array($grModel->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            if ($grModel->status !== 'SUBMITTED') {
                return response()->json(['error' => ['code' => 'gr_not_receivable', 'message' => 'Only SUBMITTED GR can be received']], 409);
            }

            $items = GoodsReceiptItem::where('goods_receipt_id', $grModel->id)->get();

            // Discrepancy rule: rejected > 0 OR received != ordered
            $hasDiscrepancy = false;
            foreach ($items as $it) {
                if ((float) $it->rejected_qty > 0) $hasDiscrepancy = true;
                if ((float) $it->received_qty !== (float) $it->ordered_qty) $hasDiscrepancy = true;
            }

            $grModel->status = $hasDiscrepancy ? 'DISCREPANCY' : 'RECEIVED';
            $grModel->received_at = now();
            $grModel->received_by = $u->id;
            $grModel->save();

            // Post inventory once (idempotent gate + row lock already held)
            $postedResult = null;
            if (!$this->truthy($grModel->inventory_posted ?? false)) {
                $postedResult = $this->postInventoryFromGr($request, $u->id, $grModel, $items);
            }

            Audit::log($request, 'receive', 'goods_receipts', $grModel->id, [
                'from' => 'SUBMITTED',
                'to' => $grModel->status,
                'branch_id' => (string) $grModel->branch_id,
                'received_at' => (string) $grModel->received_at,
                'has_discrepancy' => $hasDiscrepancy,
                'inventory_posted' => (bool) ($grModel->inventory_posted ?? false),
                'inventory_posted_summary' => $postedResult,
                'lines' => $items->map(fn ($it) => [
                    'goods_receipt_item_id' => (string) $it->id,
                    'purchase_order_item_id' => (string) $it->purchase_order_item_id,
                    'item_name' => (string) $it->item_name,
                    'unit' => (string) $it->unit,
                    'ordered_qty' => (float) $it->ordered_qty,
                    'received_qty' => (float) $it->received_qty,
                    'rejected_qty' => (float) $it->rejected_qty,
                ])->values()->all(),
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadGrGuarded($request, $companyId, $grModel->id), 200);
        });
    }

    /**
     * GET /api/dc/receipts/{gr}
     */
    public function show(Request $request, string $gr)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        return response()->json($this->loadGrGuarded($request, $companyId, $gr), 200);
    }

    /**
     * Inventory posting core:
     * - Ensures inventory_item exists (branch-scoped unique on item_name+unit)
     * - Creates inventory_lots (FIFO lots) with remaining_qty=received_qty
     * - Writes inventory_movements rows (type=IN) linked to lot + GR
     * - Updates inventory_items.on_hand
     * - Marks goods_receipts.inventory_posted=true (+ timestamp)
     */
    private function postInventoryFromGr(Request $request, string $actorId, GoodsReceipt $gr, $items): array
    {
        // Lock PO to read currency + unit prices safely (no mutation needed, but stable read)
        $po = DB::table('purchase_orders')
            ->where('id', $gr->purchase_order_id)
            ->select(['id', 'currency'])
            ->first();

        $currency = $po?->currency ?: 'IDR';

        // Load PO items for unit_price lookup (avoid N queries)
        $poItemPrices = DB::table('purchase_order_items')
            ->where('purchase_order_id', $gr->purchase_order_id)
            ->select(['id', 'unit_price'])
            ->get()
            ->keyBy('id');

        $now = now();

        $createdLots = 0;
        $createdMovements = 0;
        $touchedInventoryItems = 0;

        // Deterministic per-GR lot codes: LOT-{GR_NUMBER}-{NN}
        $seq = 1;

        foreach ($items as $it) {
            $receivedQty = (float) $it->received_qty;

            // Do not create lots for zero receipts
            if ($receivedQty <= 0) {
                $seq++;
                continue;
            }

            // 1) Ensure inventory_item exists for (branch_id, item_name, unit)
            $inventoryItemId = $this->getOrCreateInventoryItemId(
                (string) $gr->branch_id,
                (string) $it->item_name,
                (string) ($it->unit ?? '')
            );

            // 2) Create lot
            $lotId = (string) Str::uuid();

            $lotCode = 'LOT-' . (string) $gr->gr_number . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);

            // Best effort: unique(branch_id, lot_code) — if collision, add random suffix once.
            try {
                DB::table('inventory_lots')->insert([
                    'id' => $lotId,
                    'branch_id' => (string) $gr->branch_id,
                    'inventory_item_id' => $inventoryItemId,
                    'goods_receipt_id' => (string) $gr->id,
                    'goods_receipt_item_id' => (string) $it->id,
                    'lot_code' => $lotCode,
                    'expiry_date' => null,
                    'received_qty' => $receivedQty,
                    'remaining_qty' => $receivedQty,
                    'unit_cost' => $this->resolveUnitCost($poItemPrices, (string) $it->purchase_order_item_id),
                    'currency' => $currency,
                    'received_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $e) {
                // handle rare collision on unique(branch_id, lot_code)
                $sqlState = $e->errorInfo[0] ?? null;
                if ($sqlState === '23505') {
                    $lotCode2 = $lotCode . '-' . random_int(100, 999);
                    DB::table('inventory_lots')->insert([
                        'id' => $lotId,
                        'branch_id' => (string) $gr->branch_id,
                        'inventory_item_id' => $inventoryItemId,
                        'goods_receipt_id' => (string) $gr->id,
                        'goods_receipt_item_id' => (string) $it->id,
                        'lot_code' => $lotCode2,
                        'expiry_date' => null,
                        'received_qty' => $receivedQty,
                        'remaining_qty' => $receivedQty,
                        'unit_cost' => $this->resolveUnitCost($poItemPrices, (string) $it->purchase_order_item_id),
                        'currency' => $currency,
                        'received_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    throw $e;
                }
            }

            $createdLots++;

            // 3) Write movement IN (append-only behavior enforced by app logic)
            DB::table('inventory_movements')->insert([
                'id' => (string) Str::uuid(),
                'branch_id' => (string) $gr->branch_id,
                'inventory_item_id' => $inventoryItemId,
                'type' => 'IN',
                'qty' => $receivedQty,

                // optional legacy fields
                'ref_type' => 'goods_receipts',
                'ref_id' => (string) $gr->id,

                'actor_id' => $actorId,
                'note' => 'GR receive: ' . (string) $gr->gr_number,

                'inventory_lot_id' => $lotId,
                'source_type' => 'goods_receipts',
                'source_id' => (string) $gr->id,

                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $createdMovements++;

            // 4) Update on_hand (lock row for update)
            DB::table('inventory_items')
                ->where('id', $inventoryItemId)
                ->lockForUpdate()
                ->update([
                    'on_hand' => DB::raw('on_hand + ' . $receivedQty),
                    'updated_at' => $now,
                ]);

            $touchedInventoryItems++;

            $seq++;
        }

        // 5) Mark posted
        DB::table('goods_receipts')
            ->where('id', (string) $gr->id)
            ->update([
                'inventory_posted' => true,
                'inventory_posted_at' => $now,
                'updated_at' => $now,
            ]);

        // refresh model fields if used later in this request
        $gr->inventory_posted = true;
        $gr->inventory_posted_at = $now;

        return [
            'posted_at' => (string) $now,
            'lots_created' => $createdLots,
            'movements_created' => $createdMovements,
            'inventory_items_touched' => $touchedInventoryItems,
        ];
    }

    private function resolveUnitCost($poItemPrices, string $purchaseOrderItemId): float
    {
        $row = $poItemPrices[$purchaseOrderItemId] ?? null;
        if (!$row) return 0.0;
        return (float) ($row->unit_price ?? 0);
    }

    /**
     * Branch-scoped inventory item uniqueness: (branch_id, item_name, unit).
     * Uses insert with conflict handling (23505) then selects existing id.
     */
    private function getOrCreateInventoryItemId(string $branchId, string $itemName, string $unit): string
    {
        $itemName = trim($itemName);
        $unit = trim($unit);

        $existing = DB::table('inventory_items')
            ->where('branch_id', $branchId)
            ->where('item_name', $itemName)
            ->where('unit', $unit)
            ->select(['id'])
            ->first();

        if ($existing?->id) {
            return (string) $existing->id;
        }

        $id = (string) Str::uuid();
        $now = now();

        try {
            DB::table('inventory_items')->insert([
                'id' => $id,
                'branch_id' => $branchId,
                'item_name' => $itemName,
                'unit' => $unit,
                'on_hand' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return $id;
        } catch (QueryException $e) {
            // Unique constraint hit: someone else inserted concurrently
            $sqlState = $e->errorInfo[0] ?? null;
            if ($sqlState === '23505') {
                $existing2 = DB::table('inventory_items')
                    ->where('branch_id', $branchId)
                    ->where('item_name', $itemName)
                    ->where('unit', $unit)
                    ->select(['id'])
                    ->first();

                if ($existing2?->id) return (string) $existing2->id;
            }
            throw $e;
        }
    }

    private function loadGrGuarded(Request $request, string $companyId, string $id)
    {
        $gr = GoodsReceipt::with(['items'])->findOrFail($id);

        $ok = DB::table('branches')
            ->where('id', $gr->branch_id)
            ->where('company_id', $companyId)
            ->exists();
        if (!$ok) abort(404, 'Not found');

        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array($gr->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

        return $gr;
    }

    private function truthy($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return (int) $v === 1;
        if (is_string($v)) return in_array(strtolower($v), ['1', 'true', 'yes', 'y'], true);
        return (bool) $v;
    }
}
