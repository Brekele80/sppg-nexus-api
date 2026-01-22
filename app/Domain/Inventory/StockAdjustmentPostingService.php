<?php

namespace App\Domain\Inventory;

use App\Models\StockAdjustment;
use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockAdjustmentPostingService
{
    /**
     * Post an APPROVED stock adjustment into:
     * - inventory_lots (FIFO truth)
     * - inventory_movements (canonical ledger, signed qty)
     * - inventory_items.on_hand (cached projection recomputed from lots INSIDE TX)
     *
     * Multi-tenant rules:
     * - tenant boundary: company_id from request context
     * - branch must belong to company (defense-in-depth)
     * - user must have branch access
     *
     * Transaction rules:
     * - MUST throw inside TX to rollback
     * - lock doc row to prevent double post
     * - lock inventory_items row per SKU
     * - lock inventory_lots rows for FIFO consumption
     */
    public function post(StockAdjustment $doc, Request $request): array
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string)$request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($doc, $request, $u, $companyId, $idempotencyKey) {

            // Lock document row (prevents double-post)
            $docRow = DB::table('stock_adjustments')
                ->where('id', (string)$doc->id)
                ->lockForUpdate()
                ->first();

            if (!$docRow) {
                throw new HttpException(404, 'Stock adjustment not found');
            }
            if ((string)$docRow->company_id !== (string)$companyId) {
                throw new HttpException(403, 'Forbidden');
            }

            // Idempotent
            if ((string)$docRow->status === 'POSTED') {
                return $this->buildResponse((string)$docRow->id);
            }
            if ((string)$docRow->status !== 'APPROVED') {
                throw new HttpException(409, 'Document must be APPROVED before posting');
            }

            // Branch belongs to company (defense-in-depth)
            $branchOk = DB::table('branches')
                ->where('id', (string)$docRow->branch_id)
                ->where('company_id', (string)$companyId)
                ->exists();

            if (!$branchOk) {
                throw ValidationException::withMessages([
                    'branch_id' => ['Branch not found in company'],
                ]);
            }

            // Branch access
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string)$docRow->branch_id, $allowed, true)) {
                throw new HttpException(403, 'No access to this branch');
            }

            // Load items (stable order)
            $items = DB::table('stock_adjustment_items')
                ->where('stock_adjustment_id', (string)$docRow->id)
                ->orderBy('line_no')
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['No line items'],
                ]);
            }

            $movementIds = [];
            $linesForAudit = [];

            foreach ($items as $it) {
                $lineNo = (int)$it->line_no;

                $direction = strtoupper(trim((string)$it->direction)); // IN / OUT
                if (!in_array($direction, ['IN', 'OUT'], true)) {
                    throw ValidationException::withMessages([
                        "items.$lineNo.direction" => ['direction must be IN or OUT'],
                    ]);
                }

                $qty = $this->dec3((string)$it->qty);
                if (bccomp($qty, '0.000', 3) <= 0) {
                    throw ValidationException::withMessages([
                        "items.$lineNo.qty" => ['qty must be > 0'],
                    ]);
                }

                $itemName = trim((string)$it->item_name);
                if ($itemName === '') {
                    throw ValidationException::withMessages([
                        "items.$lineNo.item_name" => ['item_name is required'],
                    ]);
                }

                $unit = $it->unit !== null ? trim((string)$it->unit) : null;
                if ($unit === '') $unit = null;

                // ------------------------------------------------------------
                // 1) Resolve inventory item (PREFER explicit inventory_item_id)
                // ------------------------------------------------------------
                $invItem = null;

                if (!empty($it->inventory_item_id)) {
                    $invItemId = (string)$it->inventory_item_id;

                    // Lock by id + branch (prevents cross-branch spoofing)
                    $invItem = DB::table('inventory_items')
                        ->where('id', $invItemId)
                        ->where('branch_id', (string)$docRow->branch_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$invItem) {
                        throw ValidationException::withMessages([
                            "items.$lineNo.inventory_item_id" => ['inventory_item_id not found in this branch'],
                        ]);
                    }

                    // Commercial-grade guard: prevent silent mismatch between provided id and provided name/unit
                    $expectedName = (string)$invItem->item_name;
                    $expectedUnit = $invItem->unit !== null ? (string)$invItem->unit : null;

                    if ($expectedName !== $itemName) {
                        throw ValidationException::withMessages([
                            "items.$lineNo.item_name" => ["item_name mismatch for inventory_item_id (expected: {$expectedName})"],
                        ]);
                    }
                    if (($expectedUnit ?? null) !== ($unit ?? null)) {
                        $eu = $expectedUnit ?? 'NULL';
                        $uu = $unit ?? 'NULL';
                        throw ValidationException::withMessages([
                            "items.$lineNo.unit" => ["unit mismatch for inventory_item_id (expected: {$eu}, got: {$uu})"],
                        ]);
                    }
                } else {
                    // Resolve by (branch_id, item_name, unit)
                    $invItem = DB::table('inventory_items')
                        ->where('branch_id', (string)$docRow->branch_id)
                        ->where('item_name', $itemName)
                        ->where(function ($q) use ($unit) {
                            if ($unit === null) $q->whereNull('unit');
                            else $q->where('unit', $unit);
                        })
                        ->lockForUpdate()
                        ->first();
                }

                // OUT must have existing inventory item
                if (!$invItem && $direction === 'OUT') {
                    throw ValidationException::withMessages([
                        "items.$lineNo.inventory_item_id" => ['OUT line must reference an existing inventory item (provide inventory_item_id or match item_name+unit)'],
                    ]);
                }

                // IN can create new inventory item only if not found and no inventory_item_id provided
                if (!$invItem && $direction === 'IN') {
                    $invItemId = (string) Str::uuid();

                    DB::table('inventory_items')->insert([
                        'id'         => $invItemId,
                        'branch_id'  => (string)$docRow->branch_id,
                        'item_name'  => $itemName,
                        'unit'       => $unit,
                        'on_hand'    => '0.000',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $invItem = DB::table('inventory_items')
                        ->where('id', $invItemId)
                        ->where('branch_id', (string)$docRow->branch_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$invItem) {
                        throw new HttpException(500, 'Failed to create inventory item');
                    }
                }

                // Persist resolved inventory_item_id back to line (traceability)
                DB::table('stock_adjustment_items')
                    ->where('id', (string)$it->id)
                    ->update([
                        'inventory_item_id' => (string)$invItem->id,
                        'updated_at'        => now(),
                    ]);

                // before = sum(lots.remaining_qty) (truth)
                $before = $this->sumLots((string)$docRow->branch_id, (string)$invItem->id);

                // ------------------------------------------------------------
                // 2) Apply IN / OUT
                // ------------------------------------------------------------
                if ($direction === 'IN') {
                    $lotId = (string) Str::uuid();

                    // lot_code unique by (branch_id, lot_code)
                    $lotCode = (string)$docRow->adjustment_no . '-' . str_pad((string)$lineNo, 3, '0', STR_PAD_LEFT);

                    $receivedAt = $it->received_at ? $it->received_at : now();
                    $currency = $it->currency ? (string)$it->currency : 'IDR';
                    $unitCost = $it->unit_cost !== null ? (string)$it->unit_cost : '0';

                    DB::table('inventory_lots')->insert([
                        'id'                    => $lotId,
                        'branch_id'             => (string)$docRow->branch_id,
                        'inventory_item_id'     => (string)$invItem->id,
                        'goods_receipt_id'      => null,
                        'goods_receipt_item_id' => null,
                        'lot_code'              => $lotCode,
                        'expiry_date'           => $it->expiry_date ?? null,
                        'received_qty'          => $qty,
                        'remaining_qty'         => $qty,
                        'unit_cost'             => $unitCost,
                        'currency'              => $currency,
                        'received_at'           => $receivedAt,
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ]);

                    $moveId = (string) Str::uuid();
                    DB::table('inventory_movements')->insert([
                        'id'                => $moveId,
                        'branch_id'         => (string)$docRow->branch_id,
                        'inventory_item_id' => (string)$invItem->id,
                        'type'              => 'ADJUSTMENT_IN',
                        'qty'               => $qty, // signed ledger: IN positive
                        'inventory_lot_id'  => $lotId,

                        'source_type'       => 'STOCK_ADJUSTMENT',
                        'source_id'         => (string)$docRow->id,
                        'ref_type'          => 'stock_adjustments',
                        'ref_id'            => (string)$docRow->id,

                        'actor_id'          => (string)$u->id,
                        'note'              => $it->remarks ?: $docRow->notes,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);

                    $after = $this->recomputeOnHandFromLots((string)$docRow->branch_id, (string)$invItem->id);

                    $movementIds[] = $moveId;
                    $linesForAudit[] = [
                        'line_no'           => $lineNo,
                        'direction'         => 'IN',
                        'inventory_item_id' => (string)$invItem->id,
                        'item_name'         => $itemName,
                        'unit'              => $unit,
                        'qty'               => $qty,
                        'on_hand_before'    => $before,
                        'on_hand_after'     => $after,
                        'lot_id'            => $lotId,
                        'lot_code'          => $lotCode,
                        'movement_id'       => $moveId,
                    ];

                    continue;
                }

                // OUT: FIFO by received_at asc, id asc unless preferred_lot_id is given.
                $need = $qty;

                $lotQuery = DB::table('inventory_lots')
                    ->where('branch_id', (string)$docRow->branch_id)
                    ->where('inventory_item_id', (string)$invItem->id)
                    ->where('remaining_qty', '>', 0);

                if (!empty($it->preferred_lot_id)) {
                    $prefId = (string)$it->preferred_lot_id;

                    $prefOk = DB::table('inventory_lots')
                        ->where('id', $prefId)
                        ->where('branch_id', (string)$docRow->branch_id)
                        ->where('inventory_item_id', (string)$invItem->id)
                        ->where('remaining_qty', '>', 0)
                        ->exists();

                    if (!$prefOk) {
                        throw ValidationException::withMessages([
                            "items.$lineNo.preferred_lot_id" => ['preferred_lot_id is invalid or has no remaining qty'],
                        ]);
                    }

                    $lotQuery->where('id', $prefId)->orderBy('id');
                } else {
                    $lotQuery->orderByRaw('CASE WHEN received_at IS NULL THEN 1 ELSE 0 END')
                        ->orderBy('received_at')
                        ->orderBy('id');
                }

                $lots = $lotQuery->lockForUpdate()->get();

                foreach ($lots as $lot) {
                    if (bccomp($need, '0.000', 3) <= 0) break;

                    $avail = $this->dec3((string)$lot->remaining_qty);
                    if (bccomp($avail, '0.000', 3) <= 0) continue;

                    $take = (bccomp($avail, $need, 3) <= 0) ? $avail : $need;

                    DB::update(
                        "update inventory_lots
                         set remaining_qty = remaining_qty - ?, updated_at = ?
                         where id = ?",
                        [$take, now(), (string)$lot->id]
                    );

                    $moveId = (string) Str::uuid();
                    DB::table('inventory_movements')->insert([
                        'id'                => $moveId,
                        'branch_id'         => (string)$docRow->branch_id,
                        'inventory_item_id' => (string)$invItem->id,
                        'type'              => 'ADJUSTMENT_OUT',
                        'qty'               => bcmul($take, '-1', 3), // signed ledger: OUT negative
                        'inventory_lot_id'  => (string)$lot->id,

                        'source_type'       => 'STOCK_ADJUSTMENT',
                        'source_id'         => (string)$docRow->id,
                        'ref_type'          => 'stock_adjustments',
                        'ref_id'            => (string)$docRow->id,

                        'actor_id'          => (string)$u->id,
                        'note'              => $it->remarks ?: $docRow->notes,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);

                    $movementIds[] = $moveId;
                    $linesForAudit[] = [
                        'line_no'           => $lineNo,
                        'direction'         => 'OUT',
                        'inventory_item_id' => (string)$invItem->id,
                        'item_name'         => $itemName,
                        'unit'              => $unit,
                        'qty'               => $take,
                        'lot_id'            => (string)$lot->id,
                        'lot_code'          => (string)$lot->lot_code,
                        'movement_id'       => $moveId,
                    ];

                    $need = bcsub($need, $take, 3);
                }

                if (bccomp($need, '0.000', 3) > 0) {
                    throw new HttpException(
                        409,
                        "Insufficient FIFO stock for item: {$itemName} (need={$qty}, before={$before})"
                    );
                }

                $after = $this->recomputeOnHandFromLots((string)$docRow->branch_id, (string)$invItem->id);

                $linesForAudit[] = [
                    'line_no'        => $lineNo,
                    '_invariant'     => true,
                    'on_hand_before' => $before,
                    'on_hand_after'  => $after,
                ];
            }

            // Mark POSTED
            DB::table('stock_adjustments')
                ->where('id', (string)$docRow->id)
                ->update([
                    'status'     => 'POSTED',
                    'posted_at'  => now(),
                    'posted_by'  => (string)$u->id,
                    'updated_at' => now(),
                ]);

            Audit::log($request, 'post', 'stock_adjustments', (string)$docRow->id, [
                'adjustment_no'   => (string)$docRow->adjustment_no,
                'branch_id'       => (string)$docRow->branch_id,
                'movement_ids'    => $movementIds,
                'lines'           => $linesForAudit,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $this->buildResponse((string)$docRow->id);
        });
    }

    private function buildResponse(string $docId): array
    {
        $doc = DB::table('stock_adjustments')->where('id', $docId)->first();
        $items = DB::table('stock_adjustment_items')
            ->where('stock_adjustment_id', $docId)
            ->orderBy('line_no')
            ->get();

        return [
            'id'            => (string)$doc->id,
            'company_id'    => (string)$doc->company_id,
            'branch_id'     => (string)$doc->branch_id,
            'adjustment_no' => (string)$doc->adjustment_no,
            'status'        => (string)$doc->status,
            'posted_at'     => $doc->posted_at,
            'posted_by'     => $doc->posted_by,
            'items'         => $items,
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

        return $this->dec3((string)$row->lots_sum);
    }

    /**
     * Recompute inventory_items.on_hand from sum(inventory_lots.remaining_qty) inside TX.
     */
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
        if (!is_numeric($n)) $n = '0';
        $parts = explode('.', $n, 2);
        $int = preg_replace('/[^0-9\-]/', '', $parts[0] ?: '0');
        $dec = preg_replace('/[^0-9]/', '', $parts[1] ?? '0');
        $dec = substr(str_pad($dec, 3, '0'), 0, 3);
        if ($int === '' || $int === '-') $int = '0';
        return $int . '.' . $dec;
    }
}
