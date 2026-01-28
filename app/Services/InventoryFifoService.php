<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Exception;

class InventoryFifoService
{
    public static function consume(
        string $companyId,
        string $branchId,
        string $itemId,
        float $quantity,
        string $sourceType,
        string $sourceId,
        ?string $actorId = null
    ): void {
        DB::transaction(function () use (
            $companyId,
            $branchId,
            $itemId,
            $quantity,
            $sourceType,
            $sourceId,
            $actorId
        ) {
            DB::statement(
                'SELECT fifo_consume_inventory(?, ?, ?, ?, ?, ?, ?)',
                [
                    $companyId,
                    $branchId,
                    $itemId,
                    $quantity,
                    $sourceType,
                    $sourceId,
                    $actorId
                ]
            );
        }, 3); // Auto retry on deadlock
    }
}
