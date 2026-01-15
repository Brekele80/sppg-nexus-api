<?php

namespace App\Domain\Inventory;

use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use App\Support\Audit;
use App\Support\AuthUser;

class StockAdjustmentPostingService
{
    public function post(StockAdjustment $doc, $request): array
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        // Compute once (avoid per-line schema calls)
        $hasLedgers = DB::getSchemaBuilder()->hasTable('inventory_ledgers');

        return DB::transaction(function () use ($doc, $request, $u, $companyId, $hasLedgers) {

            // Reload + lock document row (prevents double-post)
            $docRow = DB::table('stock_adjustments')
                ->where('id', $doc->id)
                ->lockForUpdate()
                ->first();

            if (!$docRow) {
                abort(404, 'Stock adjustment not found');
            }

            if ((string)$docRow->company_id !== (string)$companyId) {
                abort(403, 'Forbidden');
            }

            // Idempotent if already posted
            if ((string)$docRow->status === 'POSTED') {
                return $this->buildResponse((string)$docRow->id);
            }

            if ((string)$docRow->status !== 'APPROVED') {
                abort(409, 'Document must be APPROVED before posting');
            }

            // Load line items (stable order)
            $items = DB::table('stock_adjustment_items')
                ->where('stock_adjustment_id', $docRow->id)
                ->orderBy('line_no')
                ->get();

            if ($items->isEmpty()) {
                abort(422, 'No line items');
            }

            // Branch must be in company
            $branchOk = DB::table('branches')
                ->where('id', $docRow->branch_id)
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchOk) {
                abort(422, 'Branch not found in company');
            }

            // Access check
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string)$docRow->branch_id, $allowed, true)) {
                abort(403, 'No access to this branch');
            }

            $linesForAudit = [];
            $movementIds   = [];

            foreach ($items as $it) {
                $direction = strtoupper(trim((string)$it->direction)); // IN/OUT
                $qty       = (float)$it->qty;

                if (!in_array($direction, ['IN', 'OUT'], true)) {
                    abort(422, 'Invalid direction on item line');
                }
                if ($qty <= 0) {
                    abort(422, 'Qty must be > 0');
                }

                // Normalize name/unit (prevents Beras vs "Beras " split)
                $itemName = trim((string)$it->item_name);
                if ($itemName === '') {
                    abort(422, 'Item name is required');
                }

                $unit = $it->unit !== null ? trim((string)$it->unit) : null;
                if ($unit === '') $unit = null;

                // 1) Resolve existing inventory item (lock to serialize updates)
                $invItem = DB::table('inventory_items')
                    ->where('branch_id', $docRow->branch_id)
                    ->where('item_name', $itemName)
                    ->where(function ($q) use ($unit) {
                        if ($unit === null) $q->whereNull('unit');
                        else $q->where('unit', $unit);
                    })
                    ->lockForUpdate()
                    ->first();

                // Production rule: OUT must match existing inventory_item
                if (!$invItem && $direction === 'OUT') {
                    abort(422, "OUT line must reference an existing inventory item: {$itemName}" . ($unit ? " ({$unit})" : ""));
                }

                // IN may create new SKU
                if (!$invItem && $direction === 'IN') {
                    $invItemId = (string) Str::uuid();

                    DB::table('inventory_items')->insert([
                        'id'         => $invItemId,
                        'branch_id'  => $docRow->branch_id,
                        'item_name'  => $itemName,
                        'unit'       => $unit,
                        'on_hand'    => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $invItem = DB::table('inventory_items')
                        ->where('id', $invItemId)
                        ->lockForUpdate()
                        ->first();

                    if (!$invItem) {
                        abort(500, 'Failed to create inventory item');
                    }
                }

                // Persist resolved inventory_item_id back to document line (important for reporting + response)
                DB::table('stock_adjustment_items')
                    ->where('id', (string)$it->id)
                    ->update([
                        'inventory_item_id' => (string)$invItem->id,
                        'updated_at'        => now(),
                    ]);

                // OPTION A: compute before from lots (truth source)
                $beforeOnHand = (float) DB::table('inventory_lots')
                    ->where('branch_id', $docRow->branch_id)
                    ->where('inventory_item_id', (string)$invItem->id)
                    ->sum('remaining_qty');

                if ($direction === 'IN') {
                    // INCREASE: create new lot layer
                    $lotId = (string) Str::uuid();

                    // Clean lot code (avoid SA-SA- bug)
                    $lotCode = (string)$docRow->adjustment_no . '-' . str_pad((string)$it->line_no, 3, '0', STR_PAD_LEFT);

                    $receivedAt = $it->received_at ? $it->received_at : now();
                    $currency   = $it->currency ? (string)$it->currency : 'IDR';
                    $unitCost   = (float)($it->unit_cost ?? 0);

                    DB::table('inventory_lots')->insert([
                        'id'                    => $lotId,
                        'branch_id'             => $docRow->branch_id,
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

                    // movement (+)
                    $moveId = (string) Str::uuid();
                    DB::table('inventory_movements')->insert([
                        'id'                => $moveId,
                        'branch_id'         => $docRow->branch_id,
                        'inventory_item_id' => (string)$invItem->id,
                        'type'              => 'ADJUSTMENT_IN',
                        'qty'               => $qty,
                        'ref_id'            => (string)$docRow->id,
                        'ref_type'          => 'stock_adjustments',
                        'actor_id'          => (string)$u->id,
                        'note'              => $it->remarks ?: $docRow->notes,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                        'inventory_lot_id'  => $lotId,
                        'source_type'       => 'STOCK_ADJUSTMENT',
                        'source_id'         => (string)$docRow->id,
                    ]);

                    if ($hasLedgers) {
                        DB::table('inventory_ledgers')->insert([
                            'id'         => (string) Str::uuid(),
                            'branch_id'  => $docRow->branch_id,
                            'item_name'  => $itemName,
                            'ref_type'   => 'stock_adjustments',
                            'ref_id'     => (string)$docRow->id,
                            'qty'        => $qty,
                            'direction'  => 'IN',
                            'created_at' => now(),
                        ]);
                    }

                    // OPTION A: set on_hand from lots after mutation
                    $afterOnHand = (float) DB::table('inventory_lots')
                        ->where('branch_id', $docRow->branch_id)
                        ->where('inventory_item_id', (string)$invItem->id)
                        ->sum('remaining_qty');

                    DB::table('inventory_items')
                        ->where('id', (string)$invItem->id)
                        ->update([
                            'on_hand'    => $afterOnHand,
                            'updated_at' => now(),
                        ]);

                    $movementIds[] = $moveId;

                    $linesForAudit[] = [
                        'line_no'           => (int)$it->line_no,
                        'direction'         => 'IN',
                        'inventory_item_id' => (string)$invItem->id,
                        'item_name'         => $itemName,
                        'unit'              => $unit,
                        'qty'               => $qty,
                        'on_hand_before'    => $beforeOnHand,
                        'on_hand_after'     => $afterOnHand,
                        'lot_id'            => $lotId,
                        'lot_code'          => $lotCode,
                        'movement_id'       => $moveId,
                    ];

                    continue;
                }

                // OUT: FIFO consume lots
                $need = $qty;

                $lotQuery = DB::table('inventory_lots')
                    ->where('branch_id', $docRow->branch_id)
                    ->where('inventory_item_id', (string)$invItem->id)
                    ->where('remaining_qty', '>', 0);

                if (!empty($it->preferred_lot_id)) {
                    // Hard-check that preferred lot belongs to same branch+item and is available
                    $lotQuery->where('id', (string)$it->preferred_lot_id);

                    $prefOk = DB::table('inventory_lots')
                        ->where('id', (string)$it->preferred_lot_id)
                        ->where('branch_id', $docRow->branch_id)
                        ->where('inventory_item_id', (string)$invItem->id)
                        ->where('remaining_qty', '>', 0)
                        ->exists();

                    if (!$prefOk) {
                        abort(422, 'preferred_lot_id is invalid or has no remaining qty for this item');
                    }
                } else {
                    // FIFO ordering (expiry first, then received_at, then created_at)
                    $lotQuery->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                        ->orderBy('expiry_date')
                        ->orderByRaw('CASE WHEN received_at IS NULL THEN 1 ELSE 0 END')
                        ->orderBy('received_at')
                        ->orderBy('created_at');
                }

                // Lock lots
                $lots = $lotQuery->lockForUpdate()->get();

                foreach ($lots as $lot) {
                    if ($need <= 0) break;

                    $avail = (float)$lot->remaining_qty;
                    if ($avail <= 0) continue;

                    $take = min($avail, $need);

                    DB::table('inventory_lots')
                        ->where('id', (string)$lot->id)
                        ->update([
                            'remaining_qty' => $avail - $take,
                            'updated_at'    => now(),
                        ]);

                    // movement per lot chunk (-)
                    $moveId = (string) Str::uuid();
                    DB::table('inventory_movements')->insert([
                        'id'                => $moveId,
                        'branch_id'         => $docRow->branch_id,
                        'inventory_item_id' => (string)$invItem->id,
                        'type'              => 'ADJUSTMENT_OUT',
                        'qty'               => -1 * $take,
                        'ref_id'            => (string)$docRow->id,
                        'ref_type'          => 'stock_adjustments',
                        'actor_id'          => (string)$u->id,
                        'note'              => $it->remarks ?: $docRow->notes,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                        'inventory_lot_id'  => (string)$lot->id,
                        'source_type'       => 'STOCK_ADJUSTMENT',
                        'source_id'         => (string)$docRow->id,
                    ]);

                    if ($hasLedgers) {
                        DB::table('inventory_ledgers')->insert([
                            'id'         => (string) Str::uuid(),
                            'branch_id'  => $docRow->branch_id,
                            'item_name'  => $itemName,
                            'ref_type'   => 'stock_adjustments',
                            'ref_id'     => (string)$docRow->id,
                            'qty'        => $take,
                            'direction'  => 'OUT',
                            'created_at' => now(),
                        ]);
                    }

                    $movementIds[] = $moveId;

                    $linesForAudit[] = [
                        'line_no'           => (int)$it->line_no,
                        'direction'         => 'OUT',
                        'inventory_item_id' => (string)$invItem->id,
                        'item_name'         => $itemName,
                        'unit'              => $unit,
                        'qty'               => $take,
                        'lot_id'            => (string)$lot->id,
                        'lot_code'          => (string)$lot->lot_code,
                        'movement_id'       => $moveId,
                    ];

                    $need -= $take;
                }

                if ($need > 0) {
                    // Not enough stock in lots (true FIFO truth)
                    abort(409, "Insufficient FIFO stock for item: {$itemName} (need={$qty}, before={$beforeOnHand})");
                }

                // OPTION A: set on_hand from lots after mutation
                $afterOnHand = (float) DB::table('inventory_lots')
                    ->where('branch_id', $docRow->branch_id)
                    ->where('inventory_item_id', (string)$invItem->id)
                    ->sum('remaining_qty');

                DB::table('inventory_items')
                    ->where('id', (string)$invItem->id)
                    ->update([
                        'on_hand'    => $afterOnHand,
                        'updated_at' => now(),
                    ]);
            }

            // Mark doc as POSTED (single source of truth)
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
                'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
            ]);

            return $this->buildResponse((string)$docRow->id);
        });
    }

    private function buildResponse(string $docId): array
    {
        $doc = DB::table('stock_adjustments')->where('id', $docId)->first();
        $items = DB::table('stock_adjustment_items')->where('stock_adjustment_id', $docId)->orderBy('line_no')->get();

        return [
            'id'            => (string)$doc->id,
            'company_id'    => (string)$doc->company_id,
            'branch_id'     => (string)$doc->branch_id,
            'adjustment_no' => (string)$doc->adjustment_no,
            'status'        => (string)$doc->status,
            'posted_at'     => $doc->posted_at,
            'items'         => $items,
        ];
    }
}
