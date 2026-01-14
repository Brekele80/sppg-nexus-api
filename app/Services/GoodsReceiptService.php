<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoodsReceiptService
{
    public function createFromPo($po, string $actorId): GoodsReceipt
    {
        return DB::transaction(function () use ($po, $actorId) {
            $gr = GoodsReceipt::create([
                'branch_id' => $po->branch_id,
                'purchase_order_id' => $po->id,
                'gr_number' => $this->generateGrNumber(),
                'status' => 'DRAFT',
                'created_by' => $actorId,
            ]);

            foreach ($po->items as $poi) {
                GoodsReceiptItem::create([
                    'goods_receipt_id' => $gr->id,
                    'purchase_order_item_id' => $poi->id,
                    'item_name' => $poi->item_name,
                    'unit' => $poi->unit,
                    'ordered_qty' => $poi->qty,
                    'received_qty' => 0,
                    'rejected_qty' => 0,
                ]);
            }

            $this->event($gr->id, $actorId, 'CREATED', 'Goods receipt created from PO');
            return $gr->load('items');
        });
    }

    public function submit(GoodsReceipt $gr, string $actorId): GoodsReceipt
    {
        if ($gr->status !== 'DRAFT') {
            throw new \RuntimeException('Only DRAFT receipts can be submitted');
        }

        $gr->update([
            'status' => 'SUBMITTED',
            'submitted_by' => $actorId,
            'submitted_at' => now(),
        ]);

        $this->event($gr->id, $actorId, 'SUBMITTED', 'Goods receipt submitted');
        return $gr->fresh()->load('items');
    }

    /**
     * FIFO canonical receive:
     * - creates lots
     * - updates inventory_items.on_hand
     * - creates inventory_movements with signed qty and schema columns
     */
    public function receive(GoodsReceipt $gr, string $actorId)
    {
        return DB::transaction(function () use ($gr, $actorId) {

            if ($gr->status !== 'SUBMITTED') {
                abort(409, 'Goods receipt must be SUBMITTED before receiving');
            }

            $gr->update([
                'status' => 'RECEIVED',
                'received_by' => $actorId,
                'received_at' => now(),
            ]);

            DB::table('goods_receipt_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'goods_receipt_id' => $gr->id,
                'actor_id' => $actorId,
                'event' => 'RECEIVED',
                'message' => 'Goods receipt received',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 🔥 AUTO POST TO INVENTORY
            $posting = \App\Services\GoodsReceiptPostingService::postReceipt($gr->id, $actorId);

            return [
                'goods_receipt' => $gr->fresh('items','events'),
                'inventory_posting' => $posting,
            ];
        });
    }

    public function updateItems(GoodsReceipt $gr, array $itemsPayload, string $actorId): GoodsReceipt
    {
        if (!in_array($gr->status, ['DRAFT', 'SUBMITTED'], true)) {
            throw new \RuntimeException('Cannot edit items after receipt is finalized');
        }

        return DB::transaction(function () use ($gr, $itemsPayload, $actorId) {
            foreach ($itemsPayload as $row) {
                $id = $row['id'] ?? null;
                if (!$id) continue;

                $it = $gr->items()->where('id', $id)->first();
                if (!$it) continue;

                $it->update([
                    'received_qty' => $row['received_qty'] ?? $it->received_qty,
                    'rejected_qty' => $row['rejected_qty'] ?? $it->rejected_qty,
                    'discrepancy_reason' => $row['discrepancy_reason'] ?? $it->discrepancy_reason,
                    'remarks' => $row['remarks'] ?? $it->remarks,
                ]);
            }

            $this->event($gr->id, $actorId, 'UPDATED', 'Receipt items updated');
            return $gr->fresh()->load('items');
        });
    }

    private function generateGrNumber(): string
    {
        $rand = strtoupper(substr(Str::uuid()->toString(), 0, 6));
        return 'GR-' . now()->format('Ymd') . '-' . $rand;
    }

    private function event(string $grId, ?string $actorId, string $event, ?string $message): void
    {
        DB::table('goods_receipt_events')->insert([
            'id' => (string) Str::uuid(),
            'goods_receipt_id' => $grId,
            'actor_id' => $actorId,
            'event' => $event,
            'message' => $message,
            'meta' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
