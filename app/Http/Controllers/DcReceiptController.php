<?php

namespace App\Http\Controllers;

use App\Support\AuthUser;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use App\Models\PurchaseOrder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DcReceiptController extends Controller
{
    public function createFromPo(Request $request, string $poId)
    {
        $companyId = AuthUser::requireCompanyContext($request);
        $branchId  = AuthUser::requireBranchAccess($request);

        if (!Str::isUuid($poId)) {
            return response()->json([
                'error' => ['code' => 'invalid_po_id', 'message' => 'Invalid PO id']
            ], 422);
        }

        $po = DB::table('purchase_orders as po')
            ->join('branches as b', 'b.id', '=', 'po.branch_id')
            ->where('po.id', $poId)
            ->where('b.company_id', $companyId)
            ->select('po.*', 'b.company_id')
            ->first();

        if (!$po) {
            return response()->json([
                'error' => ['code' => 'po_not_found', 'message' => 'Purchase order not found']
            ], 404);
        }

        if ((string) $po->branch_id !== (string) $branchId) {
            return response()->json([
                'error' => ['code' => 'branch_mismatch', 'message' => 'PO branch not accessible']
            ], 403);
        }

        // If GR already exists -> return it (no 500 ever)
        $existing = DB::table('goods_receipts')->where('purchase_order_id', $poId)->first();
        if ($existing) {
            return response()->json($existing, 200);
        }

        $authUser = $request->attributes->get('auth_user');
        $actorId  = $authUser ? (string) $authUser->id : null;

        try {
            $result = DB::transaction(function () use ($poId, $po, $actorId) {
                // Double-check inside transaction
                $existingTx = DB::table('goods_receipts')->where('purchase_order_id', $poId)->first();
                if ($existingTx) {
                    return ['created' => false, 'gr' => $existingTx];
                }

                $grId = (string) Str::uuid();
                $now  = now();
                $grNumber = 'GR-' . $now->format('Ymd') . '-' . strtoupper(Str::random(6));

                DB::table('goods_receipts')->insert([
                    'id'               => $grId,
                    'branch_id'        => $po->branch_id,
                    'purchase_order_id'=> $poId,
                    'gr_number'        => $grNumber,
                    'status'           => 'DRAFT',
                    'created_by'       => $actorId,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                $items = DB::table('purchase_order_items')
                    ->where('purchase_order_id', $poId)
                    ->get();

                foreach ($items as $it) {
                    DB::table('goods_receipt_items')->insert([
                        'id'                   => (string) Str::uuid(),
                        'goods_receipt_id'     => $grId,
                        'purchase_order_item_id'=> $it->id,
                        'item_name'            => $it->item_name,
                        'unit'                 => $it->unit,
                        'ordered_qty'          => $it->qty,
                        'received_qty'         => 0,
                        'rejected_qty'         => 0,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ]);
                }

                $gr = DB::table('goods_receipts')->where('id', $grId)->first();
                return ['created' => true, 'gr' => $gr];
            });

            return response()->json($result['gr'], $result['created'] ? 201 : 200);

        } catch (QueryException $e) {
            // Handle race unique violation gracefully
            $sqlState = $e->errorInfo[0] ?? null;
            if ($sqlState === '23505') {
                $existing = DB::table('goods_receipts')->where('purchase_order_id', $poId)->first();
                if ($existing) return response()->json($existing, 200);
            }

            return response()->json([
                'error' => ['code' => 'server_error', 'message' => 'Database error']
            ], 500);
        }
    }

    public function update(Request $request, string $gr)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) abort(403, 'No branch access');

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|uuid',
            'items.*.received_qty' => 'required|numeric|min:0',
            'items.*.rejected_qty' => 'required|numeric|min:0',
            'items.*.discrepancy_reason' => 'nullable|string|max:255',
            'items.*.remarks' => 'nullable|string|max:2000',
        ]);

        $grModel = GoodsReceipt::query()
            ->with('items')
            ->where('goods_receipts.id', $gr)
            ->join('branches as b', 'b.id', '=', 'goods_receipts.branch_id')
            ->where('b.company_id', $companyId)
            ->select('goods_receipts.*')
            ->firstOrFail();

        if (!in_array($grModel->branch_id, $allowedBranchIds, true)) abort(403, 'Forbidden (no branch access)');
        if ($grModel->status !== 'DRAFT') {
            return response()->json(['error' => ['code'=>'gr_not_editable','message'=>'Only DRAFT GR can be updated']], 409);
        }

        $map = collect($request->input('items'))->keyBy('id');

        return DB::transaction(function () use ($grModel, $map) {
            foreach ($grModel->items as $item) {
                if (!$map->has($item->id)) continue;
                $payload = $map->get($item->id);

                $received = (float) $payload['received_qty'];
                $rejected = (float) $payload['rejected_qty'];

                if (($received + $rejected) - (float) $item->ordered_qty > 0.000001) {
                    return response()->json(['error'=>['code'=>'qty_invalid','message'=>'received+rejected exceeds ordered']], 422);
                }

                $item->received_qty = $received;
                $item->rejected_qty = $rejected;
                $item->discrepancy_reason = $payload['discrepancy_reason'] ?? null;
                $item->remarks = $payload['remarks'] ?? null;
                $item->save();
            }

            return response()->json($grModel->fresh()->load('items'));
        });
    }

    public function submit(Request $request, string $gr)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) abort(403, 'No branch access');

        $grModel = GoodsReceipt::query()
            ->with('items')
            ->where('goods_receipts.id', $gr)
            ->join('branches as b', 'b.id', '=', 'goods_receipts.branch_id')
            ->where('b.company_id', $companyId)
            ->select('goods_receipts.*')
            ->firstOrFail();

        if (!in_array($grModel->branch_id, $allowedBranchIds, true)) abort(403, 'Forbidden (no branch access)');

        if ($grModel->status !== 'DRAFT') {
            return response()->json(['error'=>['code'=>'gr_invalid_state','message'=>'GR must be DRAFT']], 409);
        }

        $any = $grModel->items->sum(fn($i) => (float) $i->received_qty) > 0;
        if (!$any) {
            return response()->json(['error'=>['code'=>'gr_empty','message'=>'No received quantities set']], 422);
        }

        $grModel->status = 'SUBMITTED';
        $grModel->submitted_by = $u->id;
        $grModel->submitted_at = now();
        $grModel->save();

        return response()->json($grModel->fresh()->load('items'));
    }

    public function receive(Request $request, string $gr, InventoryService $inv)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) abort(403, 'No branch access');

        return DB::transaction(function () use ($gr, $u, $companyId, $allowedBranchIds, $inv) {

            $grModel = GoodsReceipt::query()
                ->with(['items','purchaseOrder','purchaseOrder.items'])
                ->where('goods_receipts.id', $gr)
                ->join('branches as b', 'b.id', '=', 'goods_receipts.branch_id')
                ->where('b.company_id', $companyId)
                ->select('goods_receipts.*')
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($grModel->branch_id, $allowedBranchIds, true)) abort(403, 'Forbidden (no branch access)');

            if ($grModel->status !== 'SUBMITTED') {
                return response()->json(['error'=>['code'=>'gr_invalid_state','message'=>'GR must be SUBMITTED']], 409);
            }

            $po = $grModel->purchaseOrder;
            $poItems = $po->items->keyBy('id');

            // discrepancy detection (optional but useful)
            $hasDiscrepancy = false;
            foreach ($grModel->items as $it) {
                $ordered = (float)$it->ordered_qty;
                $recv = (float)$it->received_qty;
                $rej  = (float)$it->rejected_qty;
                if (($recv + $rej) < $ordered - 0.0001) $hasDiscrepancy = true;
                if ($rej > 0.0001) $hasDiscrepancy = true;
            }

            foreach ($grModel->items as $it) {
                $recv = (float) $it->received_qty;
                if ($recv <= 0) continue;

                $poItem = $poItems->get($it->purchase_order_item_id);
                $unitCost = $poItem ? (float) $poItem->unit_price : 0;

                $invItem = $inv->ensureItem($grModel->branch_id, $it->item_name, $it->unit);

                $lot = $inv->receiveIntoLot([
                    'branch_id' => $grModel->branch_id,
                    'inventory_item_id' => $invItem->id,
                    'goods_receipt_id' => $grModel->id,
                    'goods_receipt_item_id' => $it->id,
                    'qty' => $recv,
                    'unit_cost' => $unitCost,
                    'currency' => $po->currency ?? 'IDR',
                    'received_at' => now(),
                ]);

                InventoryMovement::create([
                    'id' => (string) Str::uuid(),
                    'branch_id' => $grModel->branch_id,
                    'inventory_item_id' => $invItem->id,
                    'inventory_lot_id' => $lot->id,

                    'type' => 'GR_IN',
                    'qty'  => $recv, // positive IN

                    'source_type' => 'GR',
                    'source_id'   => $grModel->id,

                    'ref_type' => 'goods_receipts',
                    'ref_id'   => $grModel->id,

                    'actor_id' => $u->id,
                    'note' => 'Goods receipt ' . $grModel->gr_number,
                ]);
            }

            $grModel->status = $hasDiscrepancy ? 'DISCREPANCY' : 'RECEIVED';
            $grModel->received_by = $u->id;
            $grModel->received_at = now();
            $grModel->save();

            return response()->json($grModel->fresh()->load('items'));
        });
    }

    public function show(Request $request, string $gr)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) abort(403, 'No branch access');

        $grModel = GoodsReceipt::query()
            ->with('items')
            ->where('goods_receipts.id', $gr)
            ->join('branches as b', 'b.id', '=', 'goods_receipts.branch_id')
            ->where('b.company_id', $companyId)
            ->select('goods_receipts.*')
            ->firstOrFail();

        if (!in_array($grModel->branch_id, $allowedBranchIds, true)) {
            abort(403, 'Forbidden (no branch access)');
        }

        return response()->json($grModel);
    }
}
