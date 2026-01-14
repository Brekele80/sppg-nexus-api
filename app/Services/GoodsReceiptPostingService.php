<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoodsReceiptPostingService
{
    /**
     * Post a Goods Receipt into inventory:
     * - Creates inventory lots
     * - Inserts inventory movements (IN)
     * - Updates inventory_items.on_hand
     * - Marks goods_receipts.inventory_posted = true
     * - Writes goods_receipt_events audit trail
     */
    public static function postReceipt(string $goodsReceiptId, ?string $actorId = null): array
    {
        return DB::transaction(function () use ($goodsReceiptId, $actorId) {

            // 1) Lock GR row
            $gr = DB::table('goods_receipts')
                ->where('id', $goodsReceiptId)
                ->lockForUpdate()
                ->first();

            if (!$gr) {
                abort(404, 'Goods receipt not found');
            }

            // 2) Idempotency
            if ((bool) $gr->inventory_posted) {
                abort(409, 'Goods receipt already inventory_posted');
            }

            // 3) Derive company_id from branch
            $branch = DB::table('branches')
                ->where('id', $gr->branch_id)
                ->select('id', 'company_id', 'name')
                ->first();

            if (!$branch) {
                abort(409, 'Branch not found for goods receipt');
            }

            $companyId = (string) $branch->company_id;
            if (!$companyId) {
                abort(409, 'Branch company_id is missing');
            }

            // 4) Lock GR items
            $items = DB::table('goods_receipt_items')
                ->where('goods_receipt_id', $goodsReceiptId)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                abort(409, 'Goods receipt has no items');
            }

            $postingIds = [];
            $postedLines = [];

            foreach ($items as $it) {
                // Net received = received_qty - rejected_qty (skip if <= 0)
                $netQty = (float) $it->received_qty - (float) $it->rejected_qty;
                if ($netQty <= 0) {
                    continue;
                }

                // 5) Map PO item -> inventory_item_id
                // REQUIREMENT: purchase_order_items must have inventory_item_id
                $poi = DB::table('purchase_order_items')
                    ->where('id', $it->purchase_order_item_id)
                    ->select('id', 'inventory_item_id', 'unit_cost', 'currency', 'expiry_date')
                    ->first();

                if (!$poi || empty($poi->inventory_item_id)) {
                    abort(409, 'purchase_order_item missing inventory_item_id for GR item: '.$it->id);
                }

                $inventoryItemId = (string) $poi->inventory_item_id;

                // 6) Lot metadata sources:
                // - unit_cost/currency/expiry_date: best from purchase_order_items if present
                // - fallback to 0/IDR/null
                $unitCost = isset($poi->unit_cost) ? (string) $poi->unit_cost : '0';
                $currency = isset($poi->currency) ? (string) $poi->currency : 'IDR';
                $expiryDate = $poi->expiry_date ?? null;

                $lotCode = self::generateLotCode((string) $gr->gr_number);

                $postingId = InventoryPostingEngine::post([
                    'company_id' => $companyId,
                    'branch_id' => (string) $gr->branch_id,
                    'inventory_item_id' => $inventoryItemId,

                    'direction' => 'IN',
                    'qty' => (string) $netQty,

                    'lot_code' => $lotCode,
                    'expiry_date' => $expiryDate,
                    'unit_cost' => $unitCost,
                    'currency' => $currency,
                    'received_at' => $gr->received_at ?? now(),

                    'goods_receipt_id' => (string) $gr->id,
                    'goods_receipt_item_id' => (string) $it->id,

                    'source_type' => 'GOODS_RECEIPT',
                    'source_id' => (string) $gr->id,
                    'notes' => $it->remarks ?? 'Auto-post from Goods Receipt',
                    'actor_id' => $actorId,
                ]);

                $postingIds[] = $postingId;

                $postedLines[] = [
                    'goods_receipt_item_id' => (string) $it->id,
                    'purchase_order_item_id' => (string) $it->purchase_order_item_id,
                    'inventory_item_id' => $inventoryItemId,
                    'item_name' => (string) $it->item_name,
                    'qty_posted' => (string) $netQty,
                    'lot_code' => $lotCode,
                ];
            }

            if (empty($postingIds)) {
                abort(409, 'No postable quantity (all items net <= 0)');
            }

            // 7) Mark GR as posted
            DB::table('goods_receipts')
                ->where('id', $goodsReceiptId)
                ->update([
                    'inventory_posted' => true,
                    'inventory_posted_at' => now(),
                    'updated_at' => now(),
                ]);

            // 8) Audit event
            DB::table('goods_receipt_events')->insert([
                'id' => (string) Str::uuid(),
                'goods_receipt_id' => $goodsReceiptId,
                'actor_id' => $actorId,
                'event' => 'INVENTORY_POSTED',
                'message' => 'Inventory posted successfully',
                'meta' => json_encode([
                    'posting_ids' => $postingIds,
                    'lines' => $postedLines,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'goods_receipt_id' => $goodsReceiptId,
                'inventory_posted' => true,
                'inventory_posted_at' => now()->toISOString(),
                'posting_ids' => $postingIds,
                'lines' => $postedLines,
            ];
        });
    }

    private static function generateLotCode(string $grNumber): string
    {
        // Unique per branch constraint is (branch_id, lot_code).
        // Use GR number to get deterministic traceability.
        $suffix = strtoupper(substr(Str::uuid()->toString(), 0, 4));
        return "GR-{$grNumber}-{$suffix}";
    }
}
