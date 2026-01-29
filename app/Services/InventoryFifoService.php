<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class InventoryFifoService
{
    /**
     * Consume inventory using DB-enforced FIFO.
     * Audit laws:
     * - Actor MUST be UUID
     * - Idempotent
     * - Tenant + branch scoped
     * - Projection recomputed from lots inside same transaction
     */
    public static function consume(
        string $companyId,
        string $branchId,
        string $itemId,
        float $quantity,
        string $sourceType,
        string $sourceId,
        string $actorUuid,       // MUST be UUID
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
             * This function:
             * - Locks FIFO lots
             * - Writes immutable ledger rows
             * - Enforces oversell protection
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
             * on_hand MUST be derived from FIFO lots — never from ledger
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
                    'on_hand'    => $onHand,
                    'updated_at'=> now(),
                ]);

            DB::statement(
                'SELECT journal_from_inventory_out(?, ?, ?, ?, ?)',
                [
                    $companyId,
                    $branchId,
                    $sourceType,
                    $sourceId,
                ]);
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
