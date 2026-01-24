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
     * Audit (and optionally fix) inventory_items.on_hand by recomputing from inventory_lots.remaining_qty.
     *
     * Rules:
     * - Tenant boundary: branch must belong to company_id
     * - Access boundary: user must have branch access
     * - Truth: on_hand = SUM(inventory_lots.remaining_qty) per (branch_id, inventory_item_id)
     * - If $fix=true, update on_hand inside the same transaction and lock rows for update.
     *
     * Input:
     * - $branchId (required)
     * - $inventoryItemId (optional) audits one item
     * - $fix (bool) whether to persist correction
     */
    public function auditOnHand(Request $request, string $branchId, ?string $inventoryItemId, bool $fix): array
    {
        $u = AuthUser::get($request);
        // Auditor is an operational control. Keep tight.
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowedBranches = AuthUser::allowedBranchIds($request);

        if (empty($allowedBranches) || !in_array($branchId, $allowedBranches, true)) {
            throw new HttpException(403, 'Forbidden (no branch access)');
        }

        return DB::transaction(function () use ($request, $u, $companyId, $branchId, $inventoryItemId, $fix) {

            // Defense-in-depth: branch belongs to company.
            $branchOk = DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', (string)$companyId)
                ->exists();

            if (!$branchOk) {
                throw new HttpException(404, 'Branch not found');
            }

            // Choose lock strategy:
            // - fix=false: no lockForUpdate needed (read-only)
            // - fix=true : lock inventory_items rows to prevent concurrent posts drifting results mid-fix
            $itemsQ = DB::table('inventory_items')
                ->where('branch_id', $branchId)
                ->select(['id', 'item_name', 'unit', 'on_hand']);

            if ($inventoryItemId) {
                $itemsQ->where('id', $inventoryItemId);
            }

            if ($fix) {
                $itemsQ->lockForUpdate();
            }

            $items = $itemsQ->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => [$inventoryItemId ? 'Inventory item not found in branch' : 'No inventory items found in branch'],
                ]);
            }

            $now = now();

            $checked = 0;
            $mismatched = 0;
            $fixed = 0;

            $rows = [];

            foreach ($items as $it) {
                $checked++;

                $truth = $this->sumLotsDec3($branchId, (string)$it->id);
                $cached = $this->dec3((string)($it->on_hand ?? '0'));

                $isMismatch = (bccomp($truth, $cached, 3) !== 0);
                if ($isMismatch) $mismatched++;

                $didFix = false;

                if ($fix && $isMismatch) {
                    // Persist correction (still inside TX)
                    DB::table('inventory_items')
                        ->where('id', (string)$it->id)
                        ->where('branch_id', $branchId)
                        ->update([
                            'on_hand' => $truth,
                            'updated_at' => $now,
                        ]);

                    $didFix = true;
                    $fixed++;

                    // Per-item audit (optional but very useful when investigating drift)
                    Audit::log($request, 'fix', 'inventory_items', (string)$it->id, [
                        'branch_id' => $branchId,
                        'item_name' => (string)($it->item_name ?? ''),
                        'unit' => $it->unit !== null ? (string)$it->unit : null,
                        'on_hand_before' => $cached,
                        'on_hand_after' => $truth,
                        'source' => 'InventoryAuditorService.auditOnHand',
                        'fixed_at' => (string)$now,
                        'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
                    ]);
                }

                $rows[] = [
                    'inventory_item_id' => (string)$it->id,
                    'item_name' => (string)($it->item_name ?? ''),
                    'unit' => $it->unit !== null ? (string)$it->unit : null,
                    'on_hand_cached' => $cached,
                    'on_hand_truth' => $truth,
                    'mismatch' => $isMismatch,
                    'fixed' => $didFix,
                ];
            }

            // Summary audit at branch scope (always log if fix=true; else log only if mismatches exist)
            if ($fix || $mismatched > 0) {
                Audit::log($request, $fix ? 'fix' : 'audit', 'branches', $branchId, [
                    'branch_id' => $branchId,
                    'company_id' => (string)$companyId,
                    'inventory_item_id' => $inventoryItemId,
                    'checked' => $checked,
                    'mismatched' => $mismatched,
                    'fixed' => $fixed,
                    'source' => 'InventoryAuditorService.auditOnHand',
                    'ran_at' => (string)$now,
                    'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
                ]);
            }

            return [
                'branch_id' => $branchId,
                'inventory_item_id' => $inventoryItemId,
                'fix' => $fix,
                'checked' => $checked,
                'mismatched' => $mismatched,
                'fixed' => $fixed,
                'rows' => $rows,
                'ran_at' => (string)$now,
            ];
        });
    }

    /**
     * Truth: SUM(remaining_qty) from lots, normalized to scale(3) decimal string.
     */
    private function sumLotsDec3(string $branchId, string $inventoryItemId): string
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
     * Normalize numeric to a scale(3) decimal string.
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
