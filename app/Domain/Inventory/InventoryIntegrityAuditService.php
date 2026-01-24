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
     * Inventory Integrity Audit (Step 1)
     *
     * Audits:
     *  A) inventory_items.on_hand (cache) vs sum(inventory_lots.remaining_qty) (truth)
     *  B) per-lot conservation: remaining_qty == received_qty + sum(movements.qty)
     *  C) invalid states: negative remaining, etc.
     *
     * Fix (optional, safe):
     *  - recompute inventory_items.on_hand from lots (truth) inside same TX
     *
     * Rules:
     *  - Company boundary enforced via branches.company_id
     *  - Branch access via AuthUser::allowedBranchIds
     *  - MUST throw to rollback inside TX
     *  - Audit::log($request, $action, $entity, $entity_id, $payload) exact
     */
    public function run(Request $request, string $branchId, ?string $inventoryItemId, bool $fix): array
    {
        $u = AuthUser::get($request);

        // Read-only audit can be broader; fix must be DC_ADMIN.
        if ($fix) {
            AuthUser::requireRole($u, ['DC_ADMIN']);
        } else {
            AuthUser::requireRole($u, ['DC_ADMIN', 'ACCOUNTING', 'KA_SPPG']);
        }

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($request, $u, $companyId, $branchId, $inventoryItemId, $fix, $idempotencyKey) {

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

            // deterministic
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

            // 4) Precompute truth sums from lots (per item)
            $itemIds = $items->pluck('id')->map(fn ($x) => (string) $x)->all();

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

            // 5) Lot conservation audit (per-lot)
            // For lots in scope:
            // expected_remaining = received_qty + sum(movements.qty)
            // compare with remaining_qty
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

            // deterministic lot ordering
            $lotRowsQ->orderBy('received_at')->orderBy('created_at')->orderBy('id');

            if ($fix) {
                $lotRowsQ->lockForUpdate();
            }

            $lots = $lotRowsQ->get();
            $lotIds = $lots->pluck('id')->map(fn ($x) => (string) $x)->all();

            $mvSumByLot = [];
            if (!empty($lotIds)) {
                $mvRows = DB::table('inventory_movements')
                    ->selectRaw('inventory_lot_id, coalesce(sum(qty), 0) as mv_sum')
                    ->where('branch_id', $branchId)
                    ->whereIn('inventory_lot_id', $lotIds)
                    ->groupBy('inventory_lot_id')
                    ->get();

                foreach ($mvRows as $r) {
                    $mvSumByLot[(string) $r->inventory_lot_id] = $this->dec3((string) ($r->mv_sum ?? '0'));
                }
            }

            $now = now();

            // 6) Build results
            $itemAudit = [];
            $lotAudit = [];

            $mismatchItemCount = 0;
            $fixedItemCount = 0;

            $mismatchLotCount = 0;
            $invalidLotCount = 0;

            foreach ($items as $it) {
                $iid = (string) $it->id;

                $cached = $this->dec3((string) ($it->on_hand ?? '0'));
                $truth = $lotsSumByItem[$iid] ?? '0.000';
                $delta = $this->dec3(bcsub($cached, $truth, 3));
                $match = (bccomp($cached, $truth, 3) === 0);

                if (!$match) $mismatchItemCount++;

                $after = $cached;
                $fixed = false;

                if ($fix && !$match) {
                    DB::table('inventory_items')
                        ->where('id', $iid)
                        ->where('branch_id', $branchId)
                        ->update([
                            'on_hand' => $truth,
                            'updated_at' => $now,
                        ]);

                    $after = $truth;
                    $fixed = true;
                    $fixedItemCount++;
                }

                $itemAudit[] = [
                    'inventory_item_id' => $iid,
                    'item_name' => (string) ($it->item_name ?? ''),
                    'unit' => $it->unit !== null ? (string) $it->unit : null,

                    'cached_on_hand' => $cached,
                    'lots_remaining_sum' => $truth,
                    'delta_cached_minus_truth' => $delta,

                    'match' => $match,
                    'fixed' => $fixed,
                    'new_cached_on_hand' => $after,
                ];
            }

            foreach ($lots as $lot) {
                $lid = (string) $lot->id;
                $iid = (string) $lot->inventory_item_id;

                $received = $this->dec3((string) ($lot->received_qty ?? '0'));
                $remaining = $this->dec3((string) ($lot->remaining_qty ?? '0'));
                $mvSum = $mvSumByLot[$lid] ?? '0.000';

                // expected = received + mvSum (mvSum negative when consumed)
                $expected = $this->dec3(bcadd($received, $mvSum, 3));
                $delta = $this->dec3(bcsub($remaining, $expected, 3));

                $match = (bccomp($remaining, $expected, 3) === 0);
                if (!$match) $mismatchLotCount++;

                $invalid = false;
                $flags = [];

                if (bccomp($remaining, '0.000', 3) < 0) {
                    $invalid = true;
                    $flags[] = 'remaining_negative';
                }

                // Soft checks (informational)
                if (bccomp($received, '0.000', 3) === 0 && bccomp($remaining, '0.000', 3) > 0) {
                    $flags[] = 'remaining_without_received_qty';
                }

                if ($invalid) $invalidLotCount++;

                $lotAudit[] = [
                    'inventory_lot_id' => $lid,
                    'inventory_item_id' => $iid,
                    'lot_code' => (string) ($lot->lot_code ?? ''),

                    'received_qty' => $received,
                    'movements_sum_qty' => $mvSum,
                    'expected_remaining' => $expected,
                    'actual_remaining' => $remaining,
                    'delta_actual_minus_expected' => $delta,

                    'match' => $match,
                    'invalid' => $invalid,
                    'flags' => $flags,
                ];
            }

            $summary = [
                'branch_id' => $branchId,
                'inventory_item_id' => $inventoryItemId,
                'fix' => $fix,

                'item_mismatch_count' => $mismatchItemCount,
                'item_fixed_count' => $fixedItemCount,

                'lot_mismatch_count' => $mismatchLotCount,
                'lot_invalid_count' => $invalidLotCount,

                'idempotency_key' => $idempotencyKey,
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
                'items' => $itemAudit,
                'lots' => $lotAudit,
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
