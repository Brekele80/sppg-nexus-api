<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class InventoryFifoService
{
    /**
     * Consume inventory using DB-enforced FIFO.
     *
     * Audit Laws:
     * - Actor MUST be UUID
     * - Idempotent
     * - Tenant + branch scoped
     * - Projection recomputed from FIFO lots
     * - Journal entry created atomically
     */
    public static function consume(
        string $companyId,
        string $branchId,
        string $itemId,
        float $quantity,
        string $sourceType,
        string $sourceId,
        string $actorUuid,
        string $idempotencyKey
    ): void {
        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be positive');
        }

        if (!Str::isUuid($actorUuid)) {
            throw new RuntimeException('Actor ID must be a valid UUID');
        }

        if (strlen($idempotencyKey) < 16) {
            throw new RuntimeException('Invalid idempotency key');
        }

        DB::transaction(function () use (
            $companyId,
            $branchId,
            $itemId,
            $quantity,
            $sourceType,
            $sourceId,
            $actorUuid,
            $idempotencyKey
        ) {
            /**
             * FIFO Consume
             * DB function:
             * - Locks lots
             * - Enforces FIFO
             * - Prevents oversell
             * - Writes inventory_movements ledger
             * - Enforces idempotency
             */
            DB::statement(
                'SELECT fifo_consume_inventory(?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $companyId,
                    $branchId,
                    $itemId,
                    $quantity,
                    $sourceType,
                    $sourceId,
                    $actorUuid,
                    $idempotencyKey
                ]
            );

            /**
             * Projection Law
             * on_hand MUST be derived from FIFO lots
             */
            $onHand = DB::table('inventory_lots')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('inventory_item_id', $itemId)
                ->sum('remaining_qty');

            DB::table('inventory_items')
                ->where('id', $itemId)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->update([
                    'on_hand'     => $onHand,
                    'updated_at' => now(),
                ]);

            /**
             * Journal Hook
             * Generates double-entry rows from inventory_movements + FIFO lots
             */
            DB::statement(
                'SELECT journal_from_inventory_out(?, ?, ?, ?, ?)',
                [
                    $companyId,
                    $branchId,
                    $sourceType,
                    $sourceId,
                    $actorUuid
                ]
            );
        }, 3); // retry on serialization/deadlock
    }

    /**
     * Generate compliant idempotency keys
     */
    public static function generateKey(string $prefix = 'INV'): string
    {
        return $prefix
            . '-' . now()->format('YmdHis')
            . '-' . Str::uuid();
    }
}
