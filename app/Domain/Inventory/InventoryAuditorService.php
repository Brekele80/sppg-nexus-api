<?php

namespace App\Domain\Inventory;

use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventoryAuditorService
{
    /**
     * Audit inventory_items.on_hand (cached) vs sum(inventory_lots.remaining_qty) (truth).
     *
     * Params:
     * - $branchId required (scope)
     * - $inventoryItemId optional (single item)
     * - $fix: if true => recompute+update inventory_items.on_hand from lots INSIDE same TX
     *
     * Invariants:
     * - company boundary enforced via branches.company_id
     * - branch access enforced via AuthUser::allowedBranchIds
     * - mutations must THROW to rollback (idempotency zone)
     * - audit logging uses Audit::log($request, $action, $entity, $entity_id, $payload)
     */
    public function auditOnHand(Request $request, string $branchId, ?string $inventoryItemId, bool $fix): array
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']); // keep strict for now
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

            // 3) Load inventory items in scope (lock if fix=true to serialize updates)
            $itemsQ = DB::table('inventory_items')
                ->where('branch_id', $branchId);

            if ($inventoryItemId) {
                $itemsQ->where('id', $inventoryItemId);
            }

            // Deterministic order
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

            $results = [];
            $mismatchCount = 0;
            $fixedCount = 0;

            foreach ($items as $it) {
                $itemId = (string) $it->id;

                $lotsSum = $this->sumLots($branchId, $itemId); // truth
                $cached = $this->dec3((string) ($it->on_hand ?? '0'));

                $delta = $this->dec3(bcsub($cached, $lotsSum, 3)); // cached - truth

                $isMatch = (bccomp($cached, $lotsSum, 3) === 0);

                if (!$isMatch) {
                    $mismatchCount++;
                }

                $before = $cached;
                $after = $cached;

                if ($fix && !$isMatch) {
                    DB::table('inventory_items')
                        ->where('id', $itemId)
                        ->where('branch_id', $branchId)
                        ->update([
                            'on_hand' => $lotsSum,
                            'updated_at' => now(),
                        ]);

                    $after = $lotsSum;
                    $fixedCount++;
                }

                $results[] = [
                    'inventory_item_id' => $itemId,
                    'item_name' => (string) ($it->item_name ?? ''),
                    'unit' => $it->unit !== null ? (string) $it->unit : null,

                    'cached_on_hand' => $before,
                    'lots_remaining_sum' => $lotsSum,
                    'delta_cached_minus_truth' => $delta,

                    'match' => $isMatch,
                    'fixed' => ($fix && !$isMatch),
                    'new_cached_on_hand' => $after,
                ];
            }

            $payload = [
                'branch_id' => $branchId,
                'inventory_item_id' => $inventoryItemId,
                'fix' => $fix,
                'mismatch_count' => $mismatchCount,
                'fixed_count' => $fixedCount,
                'idempotency_key' => $idempotencyKey,
            ];

            Audit::log(
                $request,
                $fix ? 'audit_on_hand_fix' : 'audit_on_hand',
                'inventory_items',
                $inventoryItemId ?: $branchId,
                $payload
            );

            return [
                'branch_id' => $branchId,
                'inventory_item_id' => $inventoryItemId,
                'fix' => $fix,
                'mismatch_count' => $mismatchCount,
                'fixed_count' => $fixedCount,
                'items' => $results,
            ];
        });
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
