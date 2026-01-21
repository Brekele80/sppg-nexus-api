<?php

namespace App\Domain\Inventory;

use App\Exceptions\CrossBranchAccessException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStockAdjustmentStatusException;
use App\Exceptions\PreferredLotInvalidException;
use App\Exceptions\StockAdjustmentItemMissingInventoryItemException;
use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockAdjustmentPostingService
{
    /**
     * Post stock adjustment:
     * APPROVED -> POSTED
     *
     * Writes:
     * - inventory_movements (canonical)
     * - inventory_lots (create lots for IN, consume FIFO for OUT)
     * - inventory_items.on_hand recomputed from lots inside TX
     *
     * Must THROW inside transaction to rollback.
     */
    public function post($doc, Request $request): array
    {
        $u = AuthUser::get($request);
        $companyId = AuthUser::requireCompanyContext($request);

        $allowed = AuthUser::allowedBranchIds($request);
        $branchId = (string) $doc->branch_id;

        // pre-TX fast fail ok
        if (!in_array($branchId, $allowed, true)) {
            throw new CrossBranchAccessException($branchId, $companyId, 'Branch access denied');
        }

        $actorId = (string) $u->id;
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($doc, $request, $companyId, $branchId, $actorId, $idempotencyKey) {

            // Lock the doc row
            $hdr = DB::table('stock_adjustments')
                ->where('id', (string)$doc->id)
                ->lockForUpdate()
                ->first();

            if (!$hdr) {
                // Let Handler convert abort(404) to your envelope; or throw your own exception if you prefer.
                abort(404, 'Stock adjustment not found');
            }

            if ((string)$hdr->company_id !== (string)$companyId) {
                abort(403, 'Forbidden');
            }

            // defense-in-depth branch belongs to company
            $branchOk = DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists();
            if (!$branchOk) {
                throw new CrossBranchAccessException($branchId, $companyId, 'Branch does not belong to company');
            }

            $status = (string) $hdr->status;

            // Idempotent: if already POSTED, replay result deterministically from movements
            if ($status === 'POSTED') {
                $alloc = DB::table('inventory_movements')
                    ->where('source_type', 'STOCK_ADJUSTMENT')
                    ->where('source_id', (string)$hdr->id)
                    ->orderBy('created_at')
                    ->get([
                        'inventory_item_id',
                        'inventory_lot_id',
                        'type',
                        'qty',
                        'created_at',
                    ]);

                return [
                    'data' => [
                        'id' => (string)$hdr->id,
                        'adjustment_no' => (string)$hdr->adjustment_no,
                        'status' => 'POSTED',
                        'posted_at' => (string)($hdr->posted_at ?? ''),
                        'allocations' => $alloc,
                        'idempotency_key' => $idempotencyKey,
                        '_replayed' => true,
                    ]
                ];
            }

            if ($status !== 'APPROVED') {
                throw new InvalidStockAdjustmentStatusException((string)$hdr->id, $status, 'Only APPROVED can be posted.');
            }

            // Load items
            $items = DB::table('stock_adjustment_items')
                ->where('stock_adjustment_id', (string)$hdr->id)
                ->orderBy('line_no')
                ->get();

            if ($items->isEmpty()) {
                abort(409, 'Cannot post: no items');
            }

            $allocations = [];

            foreach ($items as $it) {
                $lineNo = (int) $it->line_no;
                $dir = strtoupper((string) $it->direction); // IN/OUT
                $qty = $this->dec3((string) $it->qty);

                $itemId = $it->inventory_item_id ? (string) $it->inventory_item_id : '';
                if ($itemId === '') {
                    throw new StockAdjustmentItemMissingInventoryItemException((string)$hdr->id, $lineNo);
                }

                // Lock inventory_items row (must exist per branch)
                $inv = DB::selectOne(
                    "select id, branch_id, on_hand
                     from inventory_items
                     where id = ? and branch_id = ?
                     for update",
                    [$itemId, $branchId]
                );

                if (!$inv) {
                    // treat as 0 available
                    throw new InsufficientStockException($branchId, $itemId, $qty, '0.000', 'Inventory item not found in branch');
                }

                if ($dir === 'IN') {
                    // Create a new lot
                    $lotId = (string) Str::uuid();
                    $receivedAt = $it->received_at ? $it->received_at : now();

                    // We insert only columns you have evidenced exist (received_qty, remaining_qty, received_at).
                    DB::table('inventory_lots')->insert([
                        'id' => $lotId,
                        'branch_id' => $branchId,
                        'inventory_item_id' => $itemId,
                        'received_qty' => $qty,
                        'remaining_qty' => $qty,
                        'received_at' => $receivedAt,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Movement IN
                    DB::table('inventory_movements')->insert([
                        'id' => (string) Str::uuid(),
                        'branch_id' => $branchId,
                        'inventory_item_id' => $itemId,
                        'type' => 'IN',
                        'qty' => $qty,
                        'inventory_lot_id' => $lotId,

                        'source_type' => 'STOCK_ADJUSTMENT',
                        'source_id' => (string) $hdr->id,
                        'ref_type' => 'STOCK_ADJUSTMENT',
                        'ref_id' => (string) $hdr->id,

                        'actor_id' => $actorId,
                        'note' => $it->remarks ?? $hdr->reason ?? 'Stock adjustment IN',

                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $allocations[] = [
                        'line_no' => $lineNo,
                        'direction' => 'IN',
                        'inventory_item_id' => $itemId,
                        'inventory_lot_id' => $lotId,
                        'qty' => $qty,
                    ];
                } elseif ($dir === 'OUT') {

                    $need = $qty;

                    // Optional: preferred_lot_id (consume from it first)
                    $preferredLotId = $it->preferred_lot_id ? (string)$it->preferred_lot_id : null;
                    if ($preferredLotId) {
                        $prefLot = DB::selectOne(
                            "select id, remaining_qty, received_at
                             from inventory_lots
                             where id = ?
                               and branch_id = ?
                               and inventory_item_id = ?
                               and remaining_qty > 0
                             for update",
                            [$preferredLotId, $branchId, $itemId]
                        );

                        if (!$prefLot) {
                            throw new PreferredLotInvalidException($branchId, $itemId, $preferredLotId);
                        }

                        $prefRemaining = $this->dec3((string)$prefLot->remaining_qty);
                        $take = (bccomp($prefRemaining, $need, 3) <= 0) ? $prefRemaining : $need;

                        if (bccomp($take, '0.000', 3) > 0) {
                            DB::update(
                                "update inventory_lots set remaining_qty = remaining_qty - ? where id = ?",
                                [$take, $prefLot->id]
                            );

                            DB::table('inventory_movements')->insert([
                                'id' => (string) Str::uuid(),
                                'branch_id' => $branchId,
                                'inventory_item_id' => $itemId,
                                'type' => 'OUT',
                                'qty' => $take,
                                'inventory_lot_id' => $prefLot->id,

                                'source_type' => 'STOCK_ADJUSTMENT',
                                'source_id' => (string) $hdr->id,
                                'ref_type' => 'STOCK_ADJUSTMENT',
                                'ref_id' => (string) $hdr->id,

                                'actor_id' => $actorId,
                                'note' => $it->remarks ?? $hdr->reason ?? 'Stock adjustment OUT',

                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            $allocations[] = [
                                'line_no' => $lineNo,
                                'direction' => 'OUT',
                                'inventory_item_id' => $itemId,
                                'inventory_lot_id' => (string)$prefLot->id,
                                'qty' => $take,
                                '_preferred' => true,
                            ];

                            $need = bcsub($need, $take, 3);
                        }
                    }

                    // Lock FIFO lots (excluding preferred lot to avoid double-taking)
                    $lots = DB::select(
                        "select id, remaining_qty, received_at
                         from inventory_lots
                         where branch_id = ?
                           and inventory_item_id = ?
                           and remaining_qty > 0
                           and (? is null or id <> ?)
                         order by received_at asc, id asc
                         for update",
                        [$branchId, $itemId, $preferredLotId, $preferredLotId]
                    );

                    // Compute availability across remaining FIFO lots + (preferred already taken is already reflected in $need)
                    $available = '0.000';
                    foreach ($lots as $lot) {
                        $available = bcadd($available, $this->dec3((string)$lot->remaining_qty), 3);
                    }

                    if (bccomp($available, $need, 3) < 0) {
                        // requested = original qty; available = remaining lots + 0 because preferred was already applied
                        $requested = $qty;
                        $availableTotal = bcadd($available, bcsub($qty, $need, 3), 3); // already took from preferred
                        throw new InsufficientStockException($branchId, $itemId, $requested, $availableTotal, 'Insufficient stock for adjustment OUT');
                    }

                    foreach ($lots as $lot) {
                        if (bccomp($need, '0.000', 3) <= 0) break;

                        $lotRemaining = $this->dec3((string)$lot->remaining_qty);
                        if (bccomp($lotRemaining, '0.000', 3) <= 0) continue;

                        $take = (bccomp($lotRemaining, $need, 3) <= 0) ? $lotRemaining : $need;

                        DB::update(
                            "update inventory_lots set remaining_qty = remaining_qty - ? where id = ?",
                            [$take, $lot->id]
                        );

                        DB::table('inventory_movements')->insert([
                            'id' => (string) Str::uuid(),
                            'branch_id' => $branchId,
                            'inventory_item_id' => $itemId,
                            'type' => 'OUT',
                            'qty' => $take,
                            'inventory_lot_id' => $lot->id,

                            'source_type' => 'STOCK_ADJUSTMENT',
                            'source_id' => (string) $hdr->id,
                            'ref_type' => 'STOCK_ADJUSTMENT',
                            'ref_id' => (string) $hdr->id,

                            'actor_id' => $actorId,
                            'note' => $it->remarks ?? $hdr->reason ?? 'Stock adjustment OUT',

                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $allocations[] = [
                            'line_no' => $lineNo,
                            'direction' => 'OUT',
                            'inventory_item_id' => $itemId,
                            'inventory_lot_id' => (string)$lot->id,
                            'qty' => $take,
                        ];

                        $need = bcsub($need, $take, 3);
                    }
                } else {
                    abort(422, 'Invalid direction');
                }

                // recompute on_hand from lots inside TX
                $lotsSumRow = DB::selectOne(
                    "select coalesce(sum(remaining_qty), 0) as lots_sum
                     from inventory_lots
                     where branch_id = ? and inventory_item_id = ?",
                    [$branchId, $itemId]
                );

                $lotsSum = $this->dec3((string)$lotsSumRow->lots_sum);

                DB::update(
                    "update inventory_items set on_hand = ? where id = ? and branch_id = ?",
                    [$lotsSum, $itemId, $branchId]
                );
            }

            // Mark header POSTED
            DB::table('stock_adjustments')
                ->where('id', (string)$hdr->id)
                ->update([
                    'status' => 'POSTED',
                    'posted_at' => now(),
                    'posted_by' => $actorId,
                    'updated_at' => now(),
                ]);

            // Audit
            Audit::log($request, 'post', 'stock_adjustments', (string)$hdr->id, [
                'adjustment_no' => (string)$hdr->adjustment_no,
                'branch_id' => $branchId,
                'allocations' => $allocations,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'data' => [
                    'id' => (string)$hdr->id,
                    'adjustment_no' => (string)$hdr->adjustment_no,
                    'branch_id' => $branchId,
                    'status' => 'POSTED',
                    'posted_at' => (string) now(),
                    'allocations' => $allocations,
                ]
            ];
        });
    }

    private function dec3(string $n): string
    {
        if (!is_numeric($n)) $n = '0';
        $parts = explode('.', $n, 2);
        $int = preg_replace('/[^0-9\-]/', '', $parts[0] ?: '0');
        $dec = preg_replace('/[^0-9]/', '', $parts[1] ?? '0');
        $dec = substr(str_pad($dec, 3, '0'), 0, 3);
        if ($int === '' || $int === '-') $int = '0';
        return $int . '.' . $dec;
    }
}
