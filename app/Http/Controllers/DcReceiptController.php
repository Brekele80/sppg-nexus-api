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
            return response()->json(['error'=>['code'=>'no_branch_access','message'=>'No branch access']], 403);
        }

        return DB::transaction(function () use ($request, $u, $companyId, $allowedBranches, $po) {

            // Company-safe PO load
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

            // Create GR number
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
                    'note' => null,
                ]);
            }

            // Audit: GR created (only when newly created)
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

            // Company enforcement via branch join
            $ok = DB::table('branches')
                ->where('id', $grModel->branch_id)
                ->where('company_id', $companyId)
                ->exists();
            if (!$ok) abort(404, 'Not found');

            // Branch access
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array($grModel->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            if ($grModel->status !== 'DRAFT') {
                return response()->json(['error'=>['code'=>'gr_not_editable','message'=>'Only DRAFT GR can be updated']], 409);
            }

            // Apply updates
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

                if ($prev['received_qty'] !== (float)$it->received_qty || $prev['rejected_qty'] !== (float)$it->rejected_qty) {
                    $changes[] = [
                        'goods_receipt_item_id' => $it->id,
                        'purchase_order_item_id' => $it->purchase_order_item_id,
                        'item_name' => $it->item_name,
                        'unit' => $it->unit,
                        'ordered_qty' => (float) $it->ordered_qty,
                        'before' => $prev,
                        'after' => [
                            'received_qty' => (float) $it->received_qty,
                            'rejected_qty' => (float) $it->rejected_qty,
                        ],
                    ];
                }
            }

            // Audit only if there are changes
            if (!empty($changes)) {
                Audit::log($request, 'update', 'goods_receipts', $grModel->id, [
                    'branch_id' => $grModel->branch_id,
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
                return response()->json(['error'=>['code'=>'gr_not_submittable','message'=>'Only DRAFT GR can be submitted']], 409);
            }

            $grModel->status = 'SUBMITTED';
            $grModel->submitted_at = now();
            $grModel->submitted_by = $u->id;
            $grModel->save();

            Audit::log($request, 'submit', 'goods_receipts', $grModel->id, [
                'from' => 'DRAFT',
                'to' => 'SUBMITTED',
                'branch_id' => $grModel->branch_id,
                'submitted_at' => (string) $grModel->submitted_at,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadGrGuarded($request, $companyId, $grModel->id), 200);
        });
    }

    /**
     * POST /api/dc/receipts/{gr}/receive
     * This should also be where you post inventory (if your design does it here).
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
                return response()->json(['error'=>['code'=>'gr_not_receivable','message'=>'Only SUBMITTED GR can be received']], 409);
            }

            $items = GoodsReceiptItem::where('goods_receipt_id', $grModel->id)->get();

            // Simple discrepancy rule: if any rejected_qty > 0 OR received != ordered
            $hasDiscrepancy = false;
            foreach ($items as $it) {
                if ((float)$it->rejected_qty > 0) $hasDiscrepancy = true;
                if ((float)$it->received_qty !== (float)$it->ordered_qty) $hasDiscrepancy = true;
            }

            $grModel->status = $hasDiscrepancy ? 'DISCREPANCY' : 'RECEIVED';
            $grModel->received_at = now();
            $grModel->received_by = $u->id;
            $grModel->save();

            Audit::log($request, 'receive', 'goods_receipts', $grModel->id, [
                'from' => 'SUBMITTED',
                'to' => $grModel->status,
                'branch_id' => $grModel->branch_id,
                'received_at' => (string) $grModel->received_at,
                'has_discrepancy' => $hasDiscrepancy,
                'lines' => $items->map(fn($it) => [
                    'goods_receipt_item_id' => (string) $it->id,
                    'purchase_order_item_id'=> (string) $it->purchase_order_item_id,
                    'item_name' => (string) $it->item_name,
                    'unit' => (string) $it->unit,
                    'ordered_qty' => (float) $it->ordered_qty,
                    'received_qty'=> (float) $it->received_qty,
                    'rejected_qty'=> (float) $it->rejected_qty,
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
}
