<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DcReceiptController extends Controller
{
    public function createFromPo(Request $request, string $po)
    {
        $user = $request->attributes->get('auth_user');
        $poModel = PurchaseOrder::with('items')->findOrFail($po);

        return DB::transaction(function () use ($poModel, $user) {
            $gr = GoodsReceipt::create([
                'id' => Str::uuid(),
                'branch_id' => $poModel->branch_id,
                'purchase_order_id' => $poModel->id,
                'gr_number' => 'GR-'.now()->format('Ymd').'-'.random_int(100000, 999999),
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]);

            foreach ($poModel->items as $it) {
                GoodsReceiptItem::create([
                    'id' => Str::uuid(),
                    'goods_receipt_id' => $gr->id,
                    'purchase_order_item_id' => $it->id,
                    'item_name' => $it->item_name,
                    'unit' => $it->unit,
                    'ordered_qty' => $it->qty,
                    'received_qty' => 0,
                    'rejected_qty' => 0,
                ]);
            }

            $gr->load('items');
            return response()->json($gr, 201);
        });
    }

    public function update(Request $request, string $gr)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|uuid',
            'items.*.received_qty' => 'required|numeric|min:0',
            'items.*.rejected_qty' => 'required|numeric|min:0',
            'items.*.discrepancy_reason' => 'nullable|string|max:255',
            'items.*.remarks' => 'nullable|string|max:2000',
        ]);

        $grModel = GoodsReceipt::with('items')->findOrFail($gr);
        if ($grModel->status !== 'DRAFT') {
            return response()->json(['error' => ['code'=>'gr_not_editable','message'=>'Only DRAFT GR can be updated']], 409);
        }

        $map = collect($request->input('items'))->keyBy('id');

        return DB::transaction(function () use ($grModel, $map) {
            foreach ($grModel->items as $item) {
                if (!$map->has($item->id)) continue;
                $payload = $map->get($item->id);

                $received = (float)$payload['received_qty'];
                $rejected = (float)$payload['rejected_qty'];

                if (($received + $rejected) - (float)$item->ordered_qty > 0.000001) {
                    return response()->json(['error'=>['code'=>'qty_invalid','message'=>'received+rejected exceeds ordered']], 422);
                }

                $item->received_qty = $received;
                $item->rejected_qty = $rejected;
                $item->discrepancy_reason = $payload['discrepancy_reason'] ?? null;
                $item->remarks = $payload['remarks'] ?? null;
                $item->save();
            }

            $grModel->load('items');
            return response()->json($grModel);
        });
    }

    public function submit(Request $request, string $gr)
    {
        $user = $request->attributes->get('auth_user');
        $grModel = GoodsReceipt::with('items')->findOrFail($gr);

        if ($grModel->status !== 'DRAFT') {
            return response()->json(['error'=>['code'=>'gr_invalid_state','message'=>'GR must be DRAFT']], 409);
        }

        // require at least one received qty > 0
        $any = $grModel->items->sum(fn($i) => (float)$i->received_qty) > 0;
        if (!$any) {
            return response()->json(['error'=>['code'=>'gr_empty','message'=>'No received quantities set']], 422);
        }

        $grModel->status = 'SUBMITTED';
        $grModel->submitted_by = $user->id;
        $grModel->submitted_at = now();
        $grModel->save();

        $grModel->load('items');
        return response()->json($grModel);
    }

    public function receive(Request $request, string $gr, InventoryService $inv)
    {
        $user = $request->attributes->get('auth_user');

        return DB::transaction(function () use ($gr, $user, $inv) {
            $grModel = GoodsReceipt::with(['items','purchaseOrder','purchaseOrder.items'])
                ->lockForUpdate()
                ->findOrFail($gr);

            if ($grModel->status !== 'SUBMITTED') {
                return response()->json(['error'=>['code'=>'gr_invalid_state','message'=>'GR must be SUBMITTED']], 409);
            }

            // Build PO item price lookup
            $po = $grModel->purchaseOrder;
            $poItems = $po->items->keyBy('id');

            foreach ($grModel->items as $it) {
                $recv = (float)$it->received_qty;
                if ($recv <= 0) continue;

                $poItem = $poItems->get($it->purchase_order_item_id);
                $unitCost = $poItem ? (float)$poItem->unit_price : 0;

                $invItem = $inv->ensureItem($grModel->branch_id, $it->item_name, $it->unit);

                // Create lot
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

                // On hand + movement ledger
                $inv->addOnHand($invItem, $recv);

                InventoryMovement::create([
                    'id' => Str::uuid(),
                    'branch_id' => $grModel->branch_id,
                    'inventory_item_id' => $invItem->id,
                    'inventory_lot_id' => $lot->id,
                    'direction' => 'IN',
                    'qty' => $recv,
                    'unit' => $it->unit,
                    'source_type' => 'GR',
                    'source_id' => $grModel->id,
                    'notes' => 'Goods receipt '.$grModel->gr_number,
                    'actor_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $grModel->status = 'RECEIVED';
            $grModel->received_by = $user->id;
            $grModel->received_at = now();
            $grModel->save();

            $grModel->load('items');
            return response()->json($grModel);
        });
    }
}
