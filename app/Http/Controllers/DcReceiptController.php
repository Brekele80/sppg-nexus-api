<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\GoodsReceiptPostingService;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\AuthUser;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DcReceiptController extends Controller
{
    /**
     * POST /api/dc/pos/{po}/receipts
     * Create GR from PO
     *
     * Deterministic behavior:
     * - If GR already exists (unique purchase_order_id), return it.
     * - Otherwise create header + copy items.
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

            /** @var PurchaseOrder|null $poModel */
            $poModel = PurchaseOrder::query()
                ->where('purchase_orders.id', $po)
                ->join('branches as b', 'b.id', '=', 'purchase_orders.branch_id')
                ->where('b.company_id', (string)$companyId)
                ->select('purchase_orders.*')
                ->lockForUpdate()
                ->first();

            if (!$poModel) {
                throw new HttpException(404, 'Purchase order not found');
            }

            if (!in_array((string)$poModel->branch_id, $allowedBranches, true)) {
                throw new HttpException(403, 'Forbidden (no branch access)');
            }

            // Fast-path: if GR already exists for PO, return it (no double audit)
            $existing = GoodsReceipt::query()
                ->where('purchase_order_id', (string)$poModel->id)
                ->first();

            if ($existing) {
                return response()->json($this->loadGrGuarded($request, (string)$companyId, (string)$existing->id), 200);
            }

            $grId = (string)Str::uuid();
            $grNumber = $this->generateGrNumber();

            try {
                GoodsReceipt::query()->create([
                    'id'               => $grId,
                    'branch_id'        => (string)$poModel->branch_id,
                    'purchase_order_id'=> (string)$poModel->id,
                    'gr_number'        => $grNumber,
                    'status'           => 'DRAFT',
                    'created_by'       => (string)$u->id,
                    'notes'            => null,
                    'meta'             => null,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                    'inventory_posted' => false,
                    'inventory_posted_at' => null,
                ]);
            } catch (QueryException $e) {
                // Deterministic concurrency handling: unique(purchase_order_id)
                $sqlState = $e->errorInfo[0] ?? null;
                if ($sqlState === '23505') {
                    $existing2 = GoodsReceipt::query()
                        ->where('purchase_order_id', (string)$poModel->id)
                        ->first();
                    if ($existing2) {
                        return response()->json($this->loadGrGuarded($request, (string)$companyId, (string)$existing2->id), 200);
                    }
                }
                throw $e;
            }

            // Copy PO items into GR items (stable order if you have line numbers; otherwise by created_at then id)
            $poItems = PurchaseOrderItem::query()
                ->where('purchase_order_id', (string)$poModel->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($poItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'purchase_order_items' => ['Purchase order has no items'],
                ]);
            }

            foreach ($poItems as $poi) {
                GoodsReceiptItem::query()->create([
                    'id'                   => (string)Str::uuid(),
                    'goods_receipt_id'     => $grId,
                    'purchase_order_item_id' => (string)$poi->id,
                    'item_name'            => (string)$poi->item_name,
                    'unit'                 => $poi->unit !== null ? (string)$poi->unit : null,
                    'ordered_qty'          => $poi->qty, // numeric(12,3) in DB
                    'received_qty'         => '0.000',
                    'rejected_qty'         => '0.000',
                    'remarks'              => null,
                    'discrepancy_reason'   => null,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }

            Audit::log($request, 'create', 'goods_receipts', $grId, [
                'purchase_order_id' => (string)$poModel->id,
                'branch_id'         => (string)$poModel->branch_id,
                'gr_number'         => $grNumber,
                'items_count'       => (int)$poItems->count(),
                'idempotency_key'   => (string)$request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadGrGuarded($request, (string)$companyId, $grId), 200);
        });
    }

    /**
     * PATCH /api/dc/receipts/{gr}
     * Update GR line quantities while DRAFT only.
     *
     * Deterministic invariants:
     * - 0 <= received_qty, rejected_qty
     * - received_qty + rejected_qty <= ordered_qty
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
            'items.*.discrepancy_reason' => 'nullable|string|max:255',
            'items.*.remarks' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $u, $companyId, $gr, $data) {

            /** @var GoodsReceipt|null $grModel */
            $grModel = GoodsReceipt::query()->lockForUpdate()->find($gr);
            if (!$grModel) {
                throw new HttpException(404, 'Not found');
            }

            $this->assertBranchInCompany((string)$grModel->branch_id, (string)$companyId);
            $this->assertBranchAccess($request, (string)$grModel->branch_id);

            if ((string)$grModel->status !== 'DRAFT') {
                return response()->json([
                    'error' => ['code' => 'gr_not_editable', 'message' => 'Only DRAFT GR can be updated']
                ], 409);
            }

            $items = GoodsReceiptItem::query()
                ->where('goods_receipt_id', (string)$grModel->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $changes = [];

            foreach ($data['items'] as $row) {
                $itemId = (string)$row['id'];
                if (!isset($items[$itemId])) {
                    throw ValidationException::withMessages([
                        'items' => ["Invalid goods_receipt_item_id: {$itemId}"],
                    ]);
                }

                /** @var GoodsReceiptItem $it */
                $it = $items[$itemId];

                $received = $this->dec3((string)$row['received_qty']);
                $rejected = $this->dec3((string)$row['rejected_qty']);
                $ordered  = $this->dec3((string)$it->ordered_qty);

                // Invariant: received + rejected <= ordered
                $sum = bcadd($received, $rejected, 3);
                if (bccomp($sum, $ordered, 3) === 1) {
                    throw ValidationException::withMessages([
                        "items.{$itemId}" => ["received_qty + rejected_qty must be <= ordered_qty (ordered={$ordered})"],
                    ]);
                }

                $prevReceived = $this->dec3((string)$it->received_qty);
                $prevRejected = $this->dec3((string)$it->rejected_qty);
                $prevReason   = $it->discrepancy_reason;
                $prevRemarks  = $it->remarks;

                $it->received_qty = $received;
                $it->rejected_qty = $rejected;
                $it->discrepancy_reason = $row['discrepancy_reason'] ?? null;
                $it->remarks = $row['remarks'] ?? null;
                $it->updated_at = now();
                $it->save();

                $changed = ($prevReceived !== $received)
                    || ($prevRejected !== $rejected)
                    || ((string)$prevReason !== (string)$it->discrepancy_reason)
                    || ((string)$prevRemarks !== (string)$it->remarks);

                if ($changed) {
                    $changes[] = [
                        'goods_receipt_item_id' => (string)$it->id,
                        'purchase_order_item_id' => (string)$it->purchase_order_item_id,
                        'item_name' => (string)$it->item_name,
                        'unit' => $it->unit !== null ? (string)$it->unit : null,
                        'ordered_qty' => $ordered,
                        'before' => [
                            'received_qty' => $prevReceived,
                            'rejected_qty' => $prevRejected,
                            'discrepancy_reason' => $prevReason,
                            'remarks' => $prevRemarks,
                        ],
                        'after' => [
                            'received_qty' => $received,
                            'rejected_qty' => $rejected,
                            'discrepancy_reason' => $it->discrepancy_reason,
                            'remarks' => $it->remarks,
                        ],
                    ];
                }
            }

            if (!empty($changes)) {
                Audit::log($request, 'update', 'goods_receipts', (string)$grModel->id, [
                    'branch_id' => (string)$grModel->branch_id,
                    'changes' => $changes,
                    'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
                ]);
            }

            return response()->json($this->loadGrGuarded($request, (string)$companyId, (string)$grModel->id), 200);
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

            /** @var GoodsReceipt|null $grModel */
            $grModel = GoodsReceipt::query()->lockForUpdate()->find($gr);
            if (!$grModel) {
                throw new HttpException(404, 'Not found');
            }

            $this->assertBranchInCompany((string)$grModel->branch_id, (string)$companyId);
            $this->assertBranchAccess($request, (string)$grModel->branch_id);

            if ((string)$grModel->status === 'SUBMITTED') {
                return response()->json(['id' => (string)$grModel->id, 'status' => 'SUBMITTED'], 200);
            }

            if ((string)$grModel->status !== 'DRAFT') {
                return response()->json([
                    'error' => ['code' => 'gr_not_submittable', 'message' => 'Only DRAFT GR can be submitted']
                ], 409);
            }

            // Hard rule: cannot submit with all lines received=0
            $sumReceived = DB::table('goods_receipt_items')
                ->where('goods_receipt_id', (string)$grModel->id)
                ->selectRaw('coalesce(sum(received_qty), 0) as s')
                ->value('s');

            if (bccomp($this->dec3((string)$sumReceived), '0.000', 3) <= 0) {
                throw ValidationException::withMessages([
                    'items' => ['Cannot submit: total received_qty must be > 0'],
                ]);
            }

            $grModel->status = 'SUBMITTED';
            $grModel->submitted_at = now();
            $grModel->submitted_by = (string)$u->id;
            $grModel->updated_at = now();
            $grModel->save();

            Audit::log($request, 'submit', 'goods_receipts', (string)$grModel->id, [
                'from' => 'DRAFT',
                'to' => 'SUBMITTED',
                'branch_id' => (string)$grModel->branch_id,
                'submitted_at' => (string)$grModel->submitted_at,
                'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
            ]);

            return response()->json(['id' => (string)$grModel->id, 'status' => 'SUBMITTED'], 200);
        });
    }

    /**
     * POST /api/dc/receipts/{gr}/receive
     * Finalizes GR and posts lots + movements (FIFO truth) via GoodsReceiptPostingService.
     *
     * IMPORTANT:
     * - No outer transaction here.
     * - Service is transactional and locks deterministically.
     */
    public function receive(Request $request, string $gr, GoodsReceiptPostingService $svc)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        AuthUser::requireCompanyContext($request);

        $result = $svc->receive($gr, $request);

        return response()->json(['data' => $result], 200);
    }

    /**
     * GET /api/dc/receipts/{gr}
     */
    public function show(Request $request, string $gr)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        return response()->json($this->loadGrGuarded($request, (string)$companyId, $gr), 200);
    }

    /**
     * Deterministic tenant + branch enforcement for reads.
     */
    private function loadGrGuarded(Request $request, string $companyId, string $id)
    {
        /** @var GoodsReceipt|null $gr */
        $gr = GoodsReceipt::query()->with(['items'])->find($id);
        if (!$gr) {
            throw new HttpException(404, 'Not found');
        }

        $this->assertBranchInCompany((string)$gr->branch_id, $companyId);
        $this->assertBranchAccess($request, (string)$gr->branch_id);

        return $gr;
    }

    private function assertBranchInCompany(string $branchId, string $companyId): void
    {
        $ok = DB::table('branches')
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$ok) {
            // Do not leak existence across tenants
            throw new HttpException(404, 'Not found');
        }
    }

    private function assertBranchAccess(Request $request, string $branchId): void
    {
        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array($branchId, $allowed, true)) {
            throw new HttpException(403, 'Forbidden (no branch access)');
        }
    }

    private function generateGrNumber(): string
    {
        // Deterministic enough + protected by unique constraint goods_receipts_gr_number_unique
        // Example: GR-20260124-123456
        return 'GR-' . now()->format('Ymd') . '-' . random_int(100000, 999999);
    }

    /**
     * Normalize decimal to scale(3) string (BC-friendly).
     */
    private function dec3(string $n): string
    {
        $n = trim($n);
        if ($n === '' || !is_numeric($n)) return '0.000';

        $neg = false;
        if (str_starts_with($n, '-')) {
            $neg = true;
            $n = substr($n, 1);
        }

        $parts = explode('.', $n, 2);
        $int = preg_replace('/[^0-9]/', '', $parts[0] ?? '0');
        if ($int === '') $int = '0';

        $dec = preg_replace('/[^0-9]/', '', $parts[1] ?? '0');
        $dec = substr(str_pad($dec, 3, '0'), 0, 3);

        $out = $int . '.' . $dec;
        return $neg && $out !== '0.000' ? '-' . $out : $out;
    }
}
