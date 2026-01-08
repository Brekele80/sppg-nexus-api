<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryLot;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function ensureItem(string $branchId, string $itemName, ?string $unit): InventoryItem
    {
        return InventoryItem::firstOrCreate(
            ['branch_id' => $branchId, 'item_name' => $itemName, 'unit' => $unit],
            ['on_hand_qty' => 0, 'reserved_qty' => 0, 'meta' => []]
        );
    }

    public function receiveIntoLot(array $payload): InventoryLot
    {
        // required keys:
        // branch_id, inventory_item_id, goods_receipt_id, goods_receipt_item_id,
        // qty, unit_cost, currency, received_at, expiry_date?
        return InventoryLot::create([
            'branch_id' => $payload['branch_id'],
            'inventory_item_id' => $payload['inventory_item_id'],
            'goods_receipt_id' => $payload['goods_receipt_id'] ?? null,
            'goods_receipt_item_id' => $payload['goods_receipt_item_id'] ?? null,
            'lot_code' => $payload['lot_code'] ?? $this->makeLotCode(),
            'expiry_date' => $payload['expiry_date'] ?? null,
            'received_qty' => $payload['qty'],
            'remaining_qty' => $payload['qty'],
            'unit_cost' => $payload['unit_cost'] ?? 0,
            'currency' => $payload['currency'] ?? 'IDR',
            'received_at' => $payload['received_at'] ?? now(),
        ]);
    }

    public function fifoConsume(string $branchId, string $inventoryItemId, float $qty): array
    {
        // Returns allocations: [ ['lot'=>InventoryLot, 'qty'=>x], ... ]
        $remaining = $qty;
        $allocations = [];

        /** @var \Illuminate\Support\Collection<int, InventoryLot> $lots */
        $lots = InventoryLot::where('branch_id', $branchId)
            ->where('inventory_item_id', $inventoryItemId)
            ->where('remaining_qty', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date ASC') // expiring first
            ->orderBy('received_at')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0) break;

            $take = min((float)$lot->remaining_qty, $remaining);
            if ($take <= 0) continue;

            $lot->remaining_qty = (float)$lot->remaining_qty - $take;
            $lot->save();

            $allocations[] = ['lot' => $lot, 'qty' => $take];
            $remaining -= $take;
        }

        if ($remaining > 0.000001) {
            throw new \RuntimeException('Insufficient stock (FIFO lots exhausted).');
        }

        return $allocations;
    }

    public function makeLotCode(): string
    {
        return 'LOT-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
    }

    public function addOnHand(InventoryItem $item, float $qty): void
    {
        $item->on_hand_qty = (float)$item->on_hand_qty + $qty;
        $item->save();
    }

    public function subOnHand(InventoryItem $item, float $qty): void
    {
        if ((float)$item->on_hand_qty < $qty) {
            throw new \RuntimeException('Insufficient on-hand stock.');
        }
        $item->on_hand_qty = (float)$item->on_hand_qty - $qty;
        $item->save();
    }
}
