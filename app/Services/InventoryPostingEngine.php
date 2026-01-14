<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryPostingEngine
{
    public static function post(array $p): string
    {
        self::validate($p);

        return DB::transaction(function () use ($p) {

            $postingId = (string) Str::uuid();

            // Lock inventory item
            $item = DB::table('inventory_items')
                ->where('id', $p['inventory_item_id'])
                ->where('branch_id', $p['branch_id'])
                ->lockForUpdate()
                ->first();

            if (!$item) abort(404, 'Inventory item not found in branch');

            if ($p['direction'] === 'IN') {
                self::postIn($postingId, $p);
            } else {
                self::postOut($postingId, $p);
            }

            return $postingId;
        });
    }

    private static function postIn(string $postingId, array $p): void
    {
        $lotId = (string) Str::uuid();

        DB::table('inventory_lots')->insert([
            'id' => $lotId,
            'branch_id' => $p['branch_id'],
            'inventory_item_id' => $p['inventory_item_id'],
            'goods_receipt_id' => $p['goods_receipt_id'] ?? null,
            'goods_receipt_item_id' => $p['goods_receipt_item_id'] ?? null,
            'lot_code' => $p['lot_code'],
            'expiry_date' => $p['expiry_date'] ?? null,
            'received_qty' => $p['qty'],
            'remaining_qty' => $p['qty'],
            'unit_cost' => $p['unit_cost'] ?? '0',
            'currency' => $p['currency'] ?? 'IDR',
            'received_at' => $p['received_at'] ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_items')
            ->where('id', $p['inventory_item_id'])
            ->update([
                'on_hand' => DB::raw("on_hand + {$p['qty']}"),
                'updated_at' => now(),
            ]);

        DB::table('inventory_movements')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $p['company_id'],
            'branch_id' => $p['branch_id'],
            'inventory_item_id' => $p['inventory_item_id'],
            'inventory_lot_id' => $lotId,
            'direction' => 'IN',
            'qty' => $p['qty'],
            'source_type' => $p['source_type'] ?? 'GOODS_RECEIPT',
            'source_id' => $p['source_id'] ?? $p['goods_receipt_id'] ?? null,
            'notes' => $p['notes'] ?? null,
            'actor_id' => $p['actor_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function postOut(string $postingId, array $p): void
    {
        $remaining = (float) $p['qty'];

        $lots = DB::table('inventory_lots')
            ->where('inventory_item_id', $p['inventory_item_id'])
            ->where('branch_id', $p['branch_id'])
            ->where('remaining_qty', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('received_at')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        $movements = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) break;

            $consume = min($remaining, (float) $lot->remaining_qty);
            $remaining -= $consume;

            DB::table('inventory_lots')->where('id', $lot->id)->update([
                'remaining_qty' => DB::raw("remaining_qty - {$consume}"),
                'updated_at' => now(),
            ]);

            $movements[] = [
                'id' => (string) Str::uuid(),
                'company_id' => $p['company_id'],
                'branch_id' => $p['branch_id'],
                'inventory_item_id' => $p['inventory_item_id'],
                'inventory_lot_id' => $lot->id,
                'direction' => 'OUT',
                'qty' => -$consume,
                'source_type' => $p['source_type'] ?? null,
                'source_id' => $p['source_id'] ?? null,
                'notes' => $p['notes'] ?? null,
                'actor_id' => $p['actor_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($remaining > 0) abort(409, 'Insufficient stock');

        DB::table('inventory_items')
            ->where('id', $p['inventory_item_id'])
            ->update([
                'on_hand' => DB::raw("on_hand - {$p['qty']}"),
                'updated_at' => now(),
            ]);

        DB::table('inventory_movements')->insert($movements);
    }

    private static function validate(array $p): void
    {
        foreach (['company_id','branch_id','inventory_item_id','direction','qty'] as $k) {
            if (!isset($p[$k])) throw new InvalidArgumentException("Missing {$k}");
        }

        if (!in_array($p['direction'], ['IN','OUT'], true)) {
            throw new InvalidArgumentException("Invalid direction");
        }

        if ((float)$p['qty'] <= 0) {
            throw new InvalidArgumentException("qty must be > 0");
        }

        if ($p['direction'] === 'IN' && empty($p['lot_code'])) {
            throw new InvalidArgumentException("lot_code required for IN");
        }
    }
}
