<?php

namespace App\Domain\Inventory;

use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KitchenOutPostingService
{
    /**
     * Post a Kitchen OUT:
     * - FIFO consume from inventory_lots.remaining_qty (truth)
     * - Append-only inventory_movements rows (type=OUT, qty negative)
     * - Recompute inventory_items.on_hand from lots inside same TX
     * - Mark kitchen_outs status POSTED
     *
     * Idempotency model:
     * - Row lock kitchen_outs; if already POSTED => return deterministic replay.
     *
     * Hard invariants:
     * - inventory_movements.type must be IN|OUT
     * - inventory_movements.qty sign must match type (OUT => qty <= 0)
     */
    public function post(string $kitchenOutId, Request $request): array
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($kitchenOutId, $request, $u, $companyId, $idempotencyKey) {

            // 1) Lock header
            $ko = DB::table('kitchen_outs')
                ->where('id', (string) $kitchenOutId)
                ->lockForUpdate()
                ->first();

            if (!$ko) {
                throw new HttpException(404, 'Kitchen out not found');
            }

            // 2) Company boundary (defense-in-depth)
            $branchOk = DB::table('branches')
                ->where('id', (string) $ko->branch_id)
                ->where('company_id', (string) $companyId)
                ->exists();

            if (!$branchOk) {
                throw new HttpException(404, 'Not found');
            }

            // 3) Branch access
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string) $ko->branch_id, $allowed, true)) {
                throw new HttpException(403, 'Forbidden (no branch access)');
            }

            $status = (string) ($ko->status ?? 'DRAFT');

            // 4) Idempotent replay if already POSTED
            if ($status === 'POSTED') {
                return $this->buildResponse((string) $ko->id);
            }

            // 5) Gate: only SUBMITTED can be posted
            if ($status !== 'SUBMITTED') {
                throw new HttpException(409, 'Only SUBMITTED kitchen outs can be posted');
            }

            // 6) Load lines (lock rows to prevent edit while posting)
            $lines = DB::table('kitchen_out_lines')
                ->where('kitchen_out_id', (string) $ko->id)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['No kitchen out lines'],
                ]);
            }

            $now = now();

            $movementIds = [];
            $consumptions = []; // for audit: per line consumption across lots
            $touchedInventoryItemIds = [];

            foreach ($lines as $line) {
                $invItemId = (string) $line->inventory_item_id;

                $qtyRequested = $this->dec3((string) $line->qty);
                if (bccomp($qtyRequested, '0.000', 3) <= 0) {
                    throw ValidationException::withMessages([
                        'qty' => ['qty must be > 0'],
                    ]);
                }

                // Lock inventory item row to serialize projection update
                $invItem = DB::table('inventory_items')
                    ->where('id', $invItemId)
                    ->where('branch_id', (string) $ko->branch_id)
                    ->lockForUpdate()
                    ->first();

                if (!$invItem) {
                    throw ValidationException::withMessages([
                        'inventory_item_id' => ['inventory_item_id not found in this branch'],
                    ]);
                }

                $onHandBefore = $this->sumLots((string) $ko->branch_id, $invItemId);

                // FIFO consume from lots with remaining_qty > 0
                $remainingToConsume = $qtyRequested;
                $lineConsumptions = [];

                while (bccomp($remainingToConsume, '0.000', 3) > 0) {

                    // Lock next FIFO lot
                    $lot = DB::table('inventory_lots')
                        ->where('branch_id', (string) $ko->branch_id)
                        ->where('inventory_item_id', $invItemId)
                        ->where('remaining_qty', '>', 0)
                        ->orderBy('received_at', 'asc')
                        ->orderBy('created_at', 'asc')
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->first();

                    if (!$lot) {
                        throw ValidationException::withMessages([
                            'qty' => ["Insufficient stock for item {$invItem->item_name} (need {$remainingToConsume})"],
                        ]);
                    }

                    $lotRemaining = $this->dec3((string) $lot->remaining_qty);

                    $consume = $this->minDec3($remainingToConsume, $lotRemaining);
                    if (bccomp($consume, '0.000', 3) <= 0) {
                        // Defensive; should never happen given remaining_qty > 0
                        throw new HttpException(500, 'Invalid FIFO lot state');
                    }

                    // Deplete lot
                    DB::table('inventory_lots')
                        ->where('id', (string) $lot->id)
                        ->update([
                            'remaining_qty' => DB::raw('remaining_qty - ' . $consume),
                            'updated_at' => $now,
                        ]);

                    // Movement OUT (signed negative) — satisfies constraints:
                    // - type = OUT
                    // - qty <= 0
                    $moveId = (string) Str::uuid();

                    DB::table('inventory_movements')->insert([
                        'id' => $moveId,
                        'branch_id' => (string) $ko->branch_id,
                        'inventory_item_id' => $invItemId,
                        'type' => 'OUT',
                        'qty' => $this->negDec3($consume), // negative

                        'inventory_lot_id' => (string) $lot->id,

                        'source_type' => 'KITCHEN_OUT',
                        'source_id' => (string) $ko->id,
                        'ref_type' => 'kitchen_outs',
                        'ref_id' => (string) $ko->id,

                        'actor_id' => (string) $u->id,
                        'note' => $this->buildMovementNote($ko, $line),

                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $movementIds[] = $moveId;

                    $lineConsumptions[] = [
                        'inventory_lot_id' => (string) $lot->id,
                        'lot_code' => (string) ($lot->lot_code ?? ''),
                        'consumed_qty' => $consume,
                        'movement_id' => $moveId,
                        'lot_remaining_after' => $this->dec3((string) ((float) $lotRemaining - (float) $consume)), // for display only
                    ];

                    $remainingToConsume = bcsub($remainingToConsume, $consume, 3);
                }

                // Recompute cached on_hand from lots (truth) inside same TX
                $onHandAfter = $this->recomputeOnHandFromLots((string) $ko->branch_id, $invItemId);

                $touchedInventoryItemIds[] = $invItemId;

                $consumptions[] = [
                    'kitchen_out_line_id' => (string) $line->id,
                    'inventory_item_id' => $invItemId,
                    'item_name' => (string) ($invItem->item_name ?? ($line->item_name ?? '')),
                    'unit' => (string) ($invItem->unit ?? ($line->unit ?? '')),
                    'qty_requested' => $qtyRequested,
                    'on_hand_before' => $onHandBefore,
                    'on_hand_after' => $onHandAfter,
                    'fifo' => $lineConsumptions,
                ];
            }

            // Mark POSTED
            DB::table('kitchen_outs')
                ->where('id', (string) $ko->id)
                ->update([
                    'status' => 'POSTED',
                    'posted_at' => $now,
                    'posted_by' => (string) $u->id,
                    'updated_at' => $now,
                ]);

            Audit::log($request, 'post', 'kitchen_outs', (string) $ko->id, [
                'branch_id' => (string) $ko->branch_id,
                'out_number' => (string) ($ko->out_number ?? ''),
                'posted_at' => (string) $now,
                'movement_ids' => $movementIds,
                'touched_inventory_item_ids' => array_values(array_unique($touchedInventoryItemIds)),
                'consumptions' => $consumptions,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $this->buildResponse((string) $ko->id);
        });
    }

    private function buildMovementNote(object $ko, object $line): ?string
    {
        $outNo = (string) ($ko->out_number ?? '');

        $lineRemarks = null;
        if (property_exists($line, 'remarks') && $line->remarks !== null && trim((string) $line->remarks) !== '') {
            $lineRemarks = trim((string) $line->remarks);
        } elseif (property_exists($line, 'notes') && $line->notes !== null && trim((string) $line->notes) !== '') {
            $lineRemarks = trim((string) $line->notes);
        }

        $headerNotes = null;
        if (property_exists($ko, 'notes') && $ko->notes !== null && trim((string) $ko->notes) !== '') {
            $headerNotes = trim((string) $ko->notes);
        }

        $base = $outNo !== '' ? "Kitchen OUT {$outNo}" : "Kitchen OUT";

        // Prefer line remarks; fallback to header notes; else base only
        if ($lineRemarks) return $base . ' — ' . $lineRemarks;
        if ($headerNotes) return $base . ' — ' . $headerNotes;
        return $base;
    }

    private function buildResponse(string $kitchenOutId): array
    {
        $ko = DB::table('kitchen_outs')->where('id', $kitchenOutId)->first();
        if (!$ko) {
            throw new HttpException(404, 'Kitchen out not found');
        }

        $lines = DB::table('kitchen_out_lines')
            ->where('kitchen_out_id', $kitchenOutId)
            ->orderBy('created_at')
            ->get();

        // movements created by this kitchen out (ledger view)
        $moves = DB::table('inventory_movements')
            ->where('source_type', 'KITCHEN_OUT')
            ->where('source_id', $kitchenOutId)
            ->orderBy('created_at')
            ->get();

        return [
            'id' => (string) $ko->id,
            'branch_id' => (string) $ko->branch_id,
            'out_number' => (string) ($ko->out_number ?? ''),
            'status' => (string) ($ko->status ?? ''),
            'out_at' => $ko->out_at ?? null,
            'submitted_at' => $ko->submitted_at ?? null,
            'submitted_by' => $ko->submitted_by ?? null,
            'posted_at' => $ko->posted_at ?? null,
            'posted_by' => $ko->posted_by ?? null,
            'lines' => $lines,
            'movements' => $moves,
        ];
    }

    private function sumLots(string $branchId, string $inventoryItemId): string
    {
        $row = DB::selectOne(
            "select coalesce(sum(remaining_qty), 0) as lots_sum
             from inventory_lots
             where branch_id = ? and inventory_item_id = ?",
            [$branchId, $inventoryItemId]
        );

        return $this->dec3((string) ($row->lots_sum ?? '0'));
    }

    private function recomputeOnHandFromLots(string $branchId, string $inventoryItemId): string
    {
        $sum = $this->sumLots($branchId, $inventoryItemId);

        DB::update(
            "update inventory_items
             set on_hand = ?, updated_at = ?
             where id = ? and branch_id = ?",
            [$sum, now(), $inventoryItemId, $branchId]
        );

        return $sum;
    }

    /**
     * Normalize decimal to scale(3) string.
     */
    private function dec3(string $n): string
    {
        $n = trim($n);
        if ($n === '' || !is_numeric($n)) return '0.000';

        $neg = false;
        if (str_starts_with($n, '-')) {
            $neg = true;
            $n = substr($n, 1);
        }

        $parts = explode('.', $n, 2);
        $int = preg_replace('/[^0-9]/', '', $parts[0] ?? '0');
        if ($int === '') $int = '0';

        $dec = preg_replace('/[^0-9]/', '', $parts[1] ?? '0');
        $dec = substr(str_pad($dec, 3, '0'), 0, 3);

        $out = $int . '.' . $dec;
        return $neg && $out !== '0.000' ? '-' . $out : $out;
    }

    private function negDec3(string $n): string
    {
        $n = $this->dec3($n);
        if ($n === '0.000') return $n;
        return str_starts_with($n, '-') ? $n : ('-' . $n);
    }

    private function minDec3(string $a, string $b): string
    {
        $a = $this->dec3($a);
        $b = $this->dec3($b);
        return bccomp($a, $b, 3) <= 0 ? $a : $b;
    }
}
