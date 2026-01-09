<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryItem;
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

    public function receive(GoodsReceipt $gr, string $actorId): GoodsReceipt
    {
        if (!in_array($gr->status, ['SUBMITTED'], true)) {
            throw new \RuntimeException('Only SUBMITTED receipts can be received');
        }

        return DB::transaction(function () use ($gr, $actorId) {
            // Determine discrepancy
            $hasDiscrepancy = false;
            foreach ($gr->items as $it) {
                $ordered = (float) $it->ordered_qty;
                $received = (float) $it->received_qty;
                $rejected = (float) $it->rejected_qty;

                // Basic validity
                if ($received < 0 || $rejected < 0) {
                    throw new \RuntimeException('received_qty/rejected_qty must be >= 0');
                }
                if (($received + $rejected) > $ordered + 0.0001) {
                    throw new \RuntimeException('received_qty + rejected_qty cannot exceed ordered_qty');
                }

                if (($received + $rejected) < $ordered - 0.0001) {
                    $hasDiscrepancy = true; // partial / missing
                }
                if ($rejected > 0.0001) {
                    $hasDiscrepancy = true;
                }
            }

            // Post inventory movements for received quantities
            foreach ($gr->items as $it) {
                $qtyIn = (float) $it->received_qty;
                if ($qtyIn <= 0) continue;

                $inv = InventoryItem::firstOrCreate(
                    ['branch_id' => $gr->branch_id, 'item_name' => $it->item_name, 'unit' => $it->unit],
                    ['on_hand' => 0]
                );

                // Update snapshot
                $inv->update(['on_hand' => DB::raw("on_hand + {$qtyIn}")]);

                InventoryMovement::create([
                    'branch_id' => $gr->branch_id,
                    'inventory_item_id' => $inv->id,
                    'type' => 'GR_IN',
                    'qty' => $qtyIn,
                    'ref_id' => $gr->id,
                    'ref_type' => 'goods_receipts',
                    'actor_id' => $actorId,
                    'note' => "Received via {$gr->gr_number}",
                ]);
            }

            $gr->update([
                'status' => $hasDiscrepancy ? 'DISCREPANCY' : 'RECEIVED',
                'received_by' => $actorId,
                'received_at' => now(),
            ]);

            $this->event(
                $gr->id,
                $actorId,
                $hasDiscrepancy ? 'DISCREPANCY' : 'RECEIVED',
                $hasDiscrepancy ? 'Receipt has discrepancies' : 'Receipt received and posted to inventory'
            );

            return $gr->fresh()->load('items');
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
