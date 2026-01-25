<?php

namespace App\Domain\Inventory;

use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventoryIntegrityAuditService
{
    /**
     * Inventory Integrity Audit
     *
     * Audits:
     *  A) inventory_items.on_hand (cache) vs sum(inventory_lots.remaining_qty) (truth)
     *  B) per-lot conservation with double-count-safe formula:
     *     expected_remaining = received_qty + (sum_all_movements - first_nonvoid_in_qty)
     *     where first_nonvoid_in_qty is the earliest IN movement whose source_type does NOT end with '_VOID'
     *
     *  C) invalid states & illegal movements:
     *     - remaining_qty < 0
     *     - KITCHEN_OUT movements with type='IN'
     *     - OUT with qty > 0
     *     - IN with qty < 0
     *
     * Fix (optional, safe):
     *  - recompute inventory_items.on_hand from lots (truth) inside same TX
     *
     * Rules:
     *  - Company boundary via branches.company_id
     *  - Branch access via AuthUser::allowedBranchIds
     *  - MUST throw to rollback inside TX
     *  - Audit::log($request, $action, $entity, $entity_id, $payload) exact
     */
    public function run(Request $request, string $branchId, ?string $inventoryItemId, bool $fix): array
    {
        $u = AuthUser::get($request);

        if ($fix) {
            AuthUser::requireRole($u, ['DC_ADMIN']);
        } else {
            AuthUser::requireRole($u, ['DC_ADMIN', 'ACCOUNTING', 'KA_SPPG']);
        }

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($request, $companyId, $branchId, $inventoryItemId, $fix, $idempotencyKey) {

            // 1) Company boundary: branch must belong to company
            $branchOk = DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', (string) $companyId)
                ->exists();

            if (!$branchOk) {
                throw new HttpException(404, 'Not found');
            }

            // 2) Branch access
            $allowed = AuthUser::allowedBranchIds($request);
            if (empty($allowed) || !in_array($branchId, $allowed, true)) {
                throw new HttpException(403, 'Forbidden (no branch access)');
            }

            // 3) Load items in scope
            $itemsQ = DB::table('inventory_items')
                ->where('branch_id', $branchId);

            if ($inventoryItemId) {
                $itemsQ->where('id', $inventoryItemId);
            }

            $itemsQ->orderBy('item_name')->orderBy('id');

            if ($fix) {
                $itemsQ->lockForUpdate();
            }

            $items = $itemsQ->get();

            if ($inventoryItemId && $items->isEmpty()) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => ['inventory item not found in this branch'],
                ]);
            }

            $itemIds = $items->pluck('id')->map(fn ($x) => (string) $x)->all();

            // 4) Truth sums from lots (per item)
            $lotsSumByItem = [];
            if (!empty($itemIds)) {
                $rows = DB::table('inventory_lots')
                    ->selectRaw('inventory_item_id, coalesce(sum(remaining_qty), 0) as lots_sum')
                    ->where('branch_id', $branchId)
                    ->whereIn('inventory_item_id', $itemIds)
                    ->groupBy('inventory_item_id')
                    ->get();

                foreach ($rows as $r) {
                    $lotsSumByItem[(string) $r->inventory_item_id] = $this->dec3((string) ($r->lots_sum ?? '0'));
                }
            }

            // 5) Load lots in scope
            $lotRowsQ = DB::table('inventory_lots')
                ->select([
                    'id',
                    'inventory_item_id',
                    'lot_code',
                    'received_qty',
                    'remaining_qty',
                    'received_at',
                    'created_at',
                ])
                ->where('branch_id', $branchId);

            if (!empty($itemIds)) {
                $lotRowsQ->whereIn('inventory_item_id', $itemIds);
            }

            $lotRowsQ->orderBy('received_at')->orderBy('created_at')->orderBy('id');

            if ($fix) {
                $lotRowsQ->lockForUpdate();
            }

            $lots = $lotRowsQ->get();
            $lotIds = $lots->pluck('id')->map(fn ($x) => (string) $x)->all();

            // 6) Movement aggregates per lot (NO LIKE/ESCAPE — use RIGHT(...)= '_VOID')
            $mvAggByLot = [];
            $firstInNonVoidByLot = [];

            if (!empty($lotIds)) {

                // 6A) Aggregate sums + illegal counters
                $mvRows = DB::table('inventory_movements')
                    ->where('branch_id', $branchId)
                    ->whereIn('inventory_lot_id', $lotIds)
                    ->groupBy('inventory_lot_id')
                    ->selectRaw("
                        inventory_lot_id,

                        coalesce(sum(qty), 0) as sum_all,
                        coalesce(sum(case when type = 'IN'  then qty else 0 end), 0) as sum_in,
                        coalesce(sum(case when type = 'OUT' then qty else 0 end), 0) as sum_out,

                        coalesce(sum(case when type = 'IN' and right(coalesce(source_type::text,''), 5) = '_VOID' then qty else 0 end), 0) as sum_in_void,

                        coalesce(sum(case when source_type = 'KITCHEN_OUT' and type = 'IN' then 1 else 0 end), 0) as cnt_kitchen_out_in,
                        coalesce(sum(case when type = 'OUT' and qty > 0 then 1 else 0 end), 0) as cnt_out_positive,
                        coalesce(sum(case when type = 'IN'  and qty < 0 then 1 else 0 end), 0) as cnt_in_negative
                    ")
                    ->get();

                foreach ($mvRows as $r) {
                    $mvAggByLot[(string) $r->inventory_lot_id] = [
                        'sum_all'            => $this->dec3((string) ($r->sum_all ?? '0')),
                        'sum_in'             => $this->dec3((string) ($r->sum_in ?? '0')),
                        'sum_out'            => $this->dec3((string) ($r->sum_out ?? '0')),
                        'sum_in_void'        => $this->dec3((string) ($r->sum_in_void ?? '0')),
                        'cnt_kitchen_out_in' => (int) ($r->cnt_kitchen_out_in ?? 0),
                        'cnt_out_positive'   => (int) ($r->cnt_out_positive ?? 0),
                        'cnt_in_negative'    => (int) ($r->cnt_in_negative ?? 0),
                    ];
                }

                // 6B) First non-void IN per lot (Postgres-safe)
                $firstInRows = DB::table('inventory_movements')
                    ->where('branch_id', $branchId)
                    ->whereIn('inventory_lot_id', $lotIds)
                    ->where('type', 'IN')
                    ->whereRaw("right(coalesce(source_type::text,''), 5) <> '_VOID'")
                    ->orderBy('inventory_lot_id')   // MUST come first
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->selectRaw("distinct on (inventory_lot_id) inventory_lot_id, qty")
                    ->get();


                foreach ($firstInRows as $r) {
                    $firstInNonVoidByLot[(string) $r->inventory_lot_id] = $this->dec3((string) ($r->qty ?? '0'));
                }
            }

            $now = now();

            // 7) Build results
            $itemAudit = [];
            $lotAudit = [];

            $mismatchItemCount = 0;
            $fixedItemCount = 0;

            $mismatchLotCount = 0;
            $invalidLotCount = 0;

            foreach ($items as $it) {
                $iid = (string) $it->id;

                $cached = $this->dec3((string) ($it->on_hand ?? '0'));
                $truth  = $lotsSumByItem[$iid] ?? '0.000';
                $delta  = $this->dec3(bcsub($cached, $truth, 3));
                $match  = (bccomp($cached, $truth, 3) === 0);

                if (!$match) $mismatchItemCount++;

                $after = $cached;
                $fixedRow = false;

                if ($fix && !$match) {
                    DB::table('inventory_items')
                        ->where('id', $iid)
                        ->where('branch_id', $branchId)
                        ->update([
                            'on_hand'    => $truth,
                            'updated_at' => $now,
                        ]);

                    $after = $truth;
                    $fixedRow = true;
                    $fixedItemCount++;
                }

                $itemAudit[] = [
                    'inventory_item_id'        => $iid,
                    'item_name'                => (string) ($it->item_name ?? ''),
                    'unit'                     => $it->unit !== null ? (string) $it->unit : null,

                    'cached_on_hand'           => $cached,
                    'lots_remaining_sum'       => $truth,
                    'delta_cached_minus_truth' => $delta,

                    'match'                    => $match,
                    'fixed'                    => $fixedRow,
                    'new_cached_on_hand'       => $after,
                ];
            }

            foreach ($lots as $lot) {
                $lid = (string) $lot->id;
                $iid = (string) $lot->inventory_item_id;

                $received  = $this->dec3((string) ($lot->received_qty ?? '0'));
                $remaining = $this->dec3((string) ($lot->remaining_qty ?? '0'));

                $agg = $mvAggByLot[$lid] ?? [
                    'sum_all'            => '0.000',
                    'sum_in'             => '0.000',
                    'sum_out'            => '0.000',
                    'sum_in_void'        => '0.000',
                    'cnt_kitchen_out_in' => 0,
                    'cnt_out_positive'   => 0,
                    'cnt_in_negative'    => 0,
                ];

                $firstInNonVoid = $firstInNonVoidByLot[$lid] ?? '0.000';

                // Double-count-safe:
                // net_delta = sum_all - first_in_nonvoid_qty
                $netDelta = $this->dec3(bcsub($agg['sum_all'], $firstInNonVoid, 3));
                $expected = $this->dec3(bcadd($received, $netDelta, 3));
                $delta    = $this->dec3(bcsub($remaining, $expected, 3));

                $match = (bccomp($remaining, $expected, 3) === 0);
                if (!$match) $mismatchLotCount++;

                $invalid = false;
                $flags = [];

                if (bccomp($remaining, '0.000', 3) < 0) {
                    $invalid = true;
                    $flags[] = 'remaining_negative';
                }

                if (($agg['cnt_kitchen_out_in'] ?? 0) > 0) {
                    $invalid = true;
                    $flags[] = 'illegal_kitchen_out_in_movement';
                }

                if (($agg['cnt_out_positive'] ?? 0) > 0) {
                    $invalid = true;
                    $flags[] = 'illegal_out_positive_qty';
                }

                if (($agg['cnt_in_negative'] ?? 0) > 0) {
                    $invalid = true;
                    $flags[] = 'illegal_in_negative_qty';
                }

                if (bccomp($received, '0.000', 3) === 0 && bccomp($remaining, '0.000', 3) > 0) {
                    $flags[] = 'remaining_without_received_qty';
                }

                if ($invalid) $invalidLotCount++;

                $lotAudit[] = [
                    'inventory_lot_id' => $lid,
                    'inventory_item_id'=> $iid,
                    'lot_code'         => (string) ($lot->lot_code ?? ''),

                    'received_qty'     => $received,
                    'remaining_qty'    => $remaining,

                    // debug breakdown
                    'sum_all'          => $agg['sum_all'],
                    'sum_in'           => $agg['sum_in'],
                    'sum_out'          => $agg['sum_out'],
                    'sum_in_void'      => $agg['sum_in_void'],
                    'first_in_nonvoid_qty' => $firstInNonVoid,

                    'net_delta'        => $netDelta,
                    'expected_remaining'=> $expected,
                    'delta_actual_minus_expected' => $delta,

                    'match'            => $match,
                    'invalid'          => $invalid,
                    'flags'            => $flags,
                ];
            }

            $summary = [
                'branch_id'           => $branchId,
                'inventory_item_id'   => $inventoryItemId,
                'fix'                 => $fix,

                'item_mismatch_count' => $mismatchItemCount,
                'item_fixed_count'    => $fixedItemCount,

                'lot_mismatch_count'  => $mismatchLotCount,
                'lot_invalid_count'   => $invalidLotCount,

                'idempotency_key'     => $idempotencyKey,
            ];

            Audit::log(
                $request,
                $fix ? 'inventory_integrity_fix' : 'inventory_integrity_audit',
                'inventory',
                $inventoryItemId ?: $branchId,
                $summary
            );

            return [
                'summary' => $summary,
                'items'   => $itemAudit,
                'lots'    => $lotAudit,
            ];
        });
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
}
