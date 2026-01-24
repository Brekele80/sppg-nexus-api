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
     * Posts a SUBMITTED kitchen_out into:
     * - FIFO depletion of inventory_lots.remaining_qty (truth)
     * - inventory_movements (canonical ledger: type IN|OUT, signed qty)
     * - inventory_items.on_hand recomputed from lots INSIDE the same TX
     *
     * Canonical ledger invariant (enforced by DB constraints):
     * - type: IN|OUT
     * - qty:  IN => >= 0, OUT => <= 0
     *
     * Idempotency:
     * - If already POSTED, returns deterministic response without re-posting.
     *
     * IMPORTANT:
     * - Inside TX, never "return error"; always THROW to rollback.
     */
    public function post(string $kitchenOutId, Request $request): array
    {
        $u = AuthUser::get($request);
        // Roles: CHEF (optionally allow DC_ADMIN)
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string)$request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($kitchenOutId, $request, $u, $companyId, $idempotencyKey) {

            // 1) Lock kitchen_out header
            $ko = DB::table('kitchen_outs')
                ->where('id', (string)$kitchenOutId)
                ->lockForUpdate()
                ->first();

            if (!$ko) {
                throw new HttpException(404, 'Kitchen out not found');
            }

            // 2) Company boundary (kitchen_outs has no company_id; enforce via branch)
            $branchOk = DB::table('branches')
                ->where('id', (string)$ko->branch_id)
                ->where('company_id', (string)$companyId)
                ->exists();

            if (!$branchOk) {
                throw new HttpException(404, 'Not found');
            }

            // 3) Branch access
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string)$ko->branch_id, $allowed, true)) {
                throw new HttpException(403, 'Forbidden (no branch access)');
            }

            $status = (string)($ko->status ?? 'DRAFT');

            // Idempotent replay
            if ($status === 'POSTED') {
                return $this->buildResponse((string)$ko->id, (string)$companyId, $request);
            }

            // Gate: only SUBMITTED can be posted
            if ($status !== 'SUBMITTED') {
                throw new HttpException(409, 'Only SUBMITTED kitchen outs can be posted');
            }

            // 4) Load lines (stable) and lock them
            $lines = DB::table('kitchen_out_lines')
                ->where('kitchen_out_id', (string)$ko->id)
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
            $lotUpdates = [];
            $consumption = [];
            $touchedItemIds = [];

            // 5) Post FIFO consumption per line
            foreach ($lines as $idx => $ln) {
                $lineIdx = (int)$idx;

                $inventoryItemId = (string)$ln->inventory_item_id;
                $qtyNeed = $this->dec3((string)$ln->qty);

                if (bccomp($qtyNeed, '0.000', 3) <= 0) {
                    throw ValidationException::withMessages([
                        "lines.$lineIdx.qty" => ['qty must be > 0'],
                    ]);
                }

                // Lock inventory item row (branch guard + stable read)
                $invItem = DB::table('inventory_items')
                    ->where('id', $inventoryItemId)
                    ->where('branch_id', (string)$ko->branch_id)
                    ->lockForUpdate()
                    ->first();

                if (!$invItem) {
                    throw ValidationException::withMessages([
                        "lines.$lineIdx.inventory_item_id" => ['inventory_item_id not found in this branch'],
                    ]);
                }

                $touchedItemIds[] = $inventoryItemId;

                // FIFO lots: oldest first. Lock lots.
                $lots = DB::table('inventory_lots')
                    ->where('branch_id', (string)$ko->branch_id)
                    ->where('inventory_item_id', $inventoryItemId)
                    ->where('remaining_qty', '>', 0)
                    ->orderBy('received_at')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $available = '0.000';
                foreach ($lots as $lot) {
                    $available = bcadd($available, $this->dec3((string)$lot->remaining_qty), 3);
                }

                if (bccomp($available, $qtyNeed, 3) < 0) {
                    $name = (string)($invItem->item_name ?? '');
                    $unit = (string)($invItem->unit ?? '');
                    throw ValidationException::withMessages([
                        "lines.$lineIdx.qty" => [
                            "Insufficient stock for {$name} {$unit}. Need {$qtyNeed}, available {$available}",
                        ],
                    ]);
                }

                $remainingNeed = $qtyNeed;

                foreach ($lots as $lot) {
                    if (bccomp($remainingNeed, '0.000', 3) <= 0) {
                        break;
                    }

                    $lotRemaining = $this->dec3((string)$lot->remaining_qty);

                    if (bccomp($lotRemaining, '0.000', 3) <= 0) {
                        continue;
                    }

                    // consume = min(remainingNeed, lotRemaining)
                    $consume = (bccomp($remainingNeed, $lotRemaining, 3) <= 0) ? $remainingNeed : $lotRemaining;

                    // Update lot remaining_qty
                    $newRemaining = bcsub($lotRemaining, $consume, 3);

                    DB::table('inventory_lots')
                        ->where('id', (string)$lot->id)
                        ->update([
                            'remaining_qty' => $newRemaining,
                            'updated_at' => $now,
                        ]);

                    $lotUpdates[] = [
                        'inventory_lot_id' => (string)$lot->id,
                        'lot_code' => (string)($lot->lot_code ?? ''),
                        'before_remaining_qty' => $lotRemaining,
                        'after_remaining_qty' => $newRemaining,
                        'consumed_qty' => $consume,
                    ];

                    // Insert movement OUT with signed negative qty (DB sign constraint)
                    $moveId = (string)Str::uuid();
                    $signedOutQty = $this->neg3($consume);

                    DB::table('inventory_movements')->insert([
                        'id' => $moveId,
                        'branch_id' => (string)$ko->branch_id,
                        'inventory_item_id' => $inventoryItemId,
                        'type' => 'OUT',
                        'qty' => $signedOutQty, // negative

                        'inventory_lot_id' => (string)$lot->id,

                        'source_type' => 'KITCHEN_OUT',
                        'source_id' => (string)$ko->id,

                        'ref_type' => 'kitchen_outs',
                        'ref_id' => (string)$ko->id,

                        'actor_id' => (string)$u->id,
                        'note' => $this->buildMovementNote($ko, $ln),

                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $movementIds[] = $moveId;

                    $consumption[] = [
                        'kitchen_out_line_id' => (string)$ln->id,
                        'inventory_item_id' => $inventoryItemId,
                        'item_name' => (string)($invItem->item_name ?? ''),
                        'unit' => (string)($invItem->unit ?? ''),
                        'inventory_lot_id' => (string)$lot->id,
                        'lot_code' => (string)($lot->lot_code ?? ''),
                        'consumed_qty' => $consume,
                        'movement_id' => $moveId,
                    ];

                    $remainingNeed = bcsub($remainingNeed, $consume, 3);
                }

                // Safety: should be fully consumed due to availability check
                if (bccomp($remainingNeed, '0.000', 3) > 0) {
                    throw new HttpException(500, 'FIFO consumption incomplete (unexpected)');
                }
            }

            // 6) Recompute on_hand from lots INSIDE the same TX (truth = lots)
            $touchedItemIds = array_values(array_unique($touchedItemIds));
            $onHandRecomputed = [];

            foreach ($touchedItemIds as $inventoryItemId) {
                $sum = $this->sumLots((string)$ko->branch_id, (string)$inventoryItemId);

                DB::table('inventory_items')
                    ->where('id', (string)$inventoryItemId)
                    ->where('branch_id', (string)$ko->branch_id)
                    ->update([
                        'on_hand' => $sum,
                        'updated_at' => $now,
                    ]);

                $onHandRecomputed[] = [
                    'inventory_item_id' => (string)$inventoryItemId,
                    'on_hand' => $sum,
                ];
            }

            // 7) Mark POSTED (terminal)
            DB::table('kitchen_outs')
                ->where('id', (string)$ko->id)
                ->update([
                    'status' => 'POSTED',
                    'posted_at' => $now,
                    'posted_by' => (string)$u->id,
                    'updated_at' => $now,
                ]);

            // 8) Audit
            Audit::log($request, 'post', 'kitchen_outs', (string)$ko->id, [
                'from' => 'SUBMITTED',
                'to' => 'POSTED',
                'branch_id' => (string)$ko->branch_id,
                'out_number' => (string)($ko->out_number ?? ''),
                'out_at' => (string)($ko->out_at ?? ''),
                'movement_ids' => $movementIds,
                'fifo_lot_updates' => $lotUpdates,
                'consumption' => $consumption,
                'on_hand_recomputed' => $onHandRecomputed,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $this->buildResponse((string)$ko->id, (string)$companyId, $request);
        });
    }

    private function buildResponse(string $kitchenOutId, string $companyId, Request $request): array
    {
        $ko = DB::table('kitchen_outs')->where('id', $kitchenOutId)->first();
        if (!$ko) {
            throw new HttpException(404, 'Kitchen out not found');
        }

        // company boundary via branch
        $branchOk = DB::table('branches')
            ->where('id', (string)$ko->branch_id)
            ->where('company_id', (string)$companyId)
            ->exists();

        if (!$branchOk) {
            throw new HttpException(404, 'Not found');
        }

        // branch access
        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array((string)$ko->branch_id, $allowed, true)) {
            throw new HttpException(403, 'Forbidden (no branch access)');
        }

        $lines = DB::table('kitchen_out_lines')
            ->where('kitchen_out_id', $kitchenOutId)
            ->orderBy('created_at')
            ->get();

        $moves = DB::table('inventory_movements')
            ->where('source_type', 'KITCHEN_OUT')
            ->where('source_id', $kitchenOutId)
            ->orderBy('created_at')
            ->get();

        return [
            'id' => (string)$ko->id,
            'branch_id' => (string)$ko->branch_id,
            'out_number' => (string)($ko->out_number ?? ''),
            'status' => (string)($ko->status ?? ''),
            'out_at' => $ko->out_at ?? null,

            'created_by' => $ko->created_by ?? null,
            'submitted_at' => $ko->submitted_at ?? null,
            'submitted_by' => $ko->submitted_by ?? null,
            'posted_at' => $ko->posted_at ?? null,
            'posted_by' => $ko->posted_by ?? null,

            'notes' => $ko->notes ?? null,
            'meta' => $ko->meta ?? null,

            'created_at' => $ko->created_at ?? null,
            'updated_at' => $ko->updated_at ?? null,

            'lines' => $lines,
            'movements' => $moves,
        ];
    }

    private function buildMovementNote(object $ko, object $line): ?string
    {
        $base = 'Kitchen OUT: ' . (string)($ko->out_number ?? '');
        $remarks = $line->remarks ?? null;
        if ($remarks === null || trim((string)$remarks) === '') {
            return $base;
        }
        return $base . ' | ' . trim((string)$remarks);
    }

    private function sumLots(string $branchId, string $inventoryItemId): string
    {
        $row = DB::selectOne(
            "select coalesce(sum(remaining_qty), 0) as lots_sum
             from inventory_lots
             where branch_id = ? and inventory_item_id = ?",
            [$branchId, $inventoryItemId]
        );

        return $this->dec3((string)($row->lots_sum ?? '0'));
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

    /**
     * Force a scale(3) string to negative (unless already -0.000).
     */
    private function neg3(string $n): string
    {
        $n = $this->dec3($n);
        if ($n === '0.000') return '0.000';
        return str_starts_with($n, '-') ? $n : ('-' . $n);
    }
}
