<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockPreflightService
{
    public static function assertAvailable(
        string $companyId,
        string $branchId,
        string $itemId,
        float $quantity
    ): float {
        $available = (float) DB::table('inventory_lots')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('inventory_item_id', $itemId)
            ->where('remaining_qty', '>', 0)
            ->sum('remaining_qty');

        if ($available < $quantity) {
            throw new RuntimeException(
                "Insufficient stock: requested {$quantity}, available {$available}"
            );
        }

        return $available;
    }
}
