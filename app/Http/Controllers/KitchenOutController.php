<?php

namespace App\Http\Controllers;

use App\Exceptions\CrossBranchAccessException;
use App\Exceptions\InsufficientStockException;
use App\Support\AuthUser;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KitchenOutController extends Controller
{
    /**
     * POST /api/kitchen/out
     * FIFO consumption at lot level.
     *
     * Constraints:
     * - Must THROW inside transaction (never return errors) to force rollback
     * - Canonical ledger: inventory_movements
     * - FIFO state: inventory_lots.remaining_qty (consume oldest first)
     * - inventory_items.on_hand is cached projection; recompute from lots INSIDE TX
     * - Tenant boundary: company_id via RequireCompanyContext + branches.company_id defense-in-depth
     * - Mutations behind IdempotencyMiddleware
     * - Audit signature: Audit::log($request, $action, $entity, $entity_id, $payload)
     */
    public function store(Request $request)
    {
        // Auth user (your helper)
        $u = AuthUser::get($request);

        // Tenant context (your helper; RequireCompanyContext sets request attribute company_id)
        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'branch_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_item_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.remarks' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
            'out_at' => ['nullable', 'date'],
        ]);

        $branchId = (string) $data['branch_id'];
        $lines = $data['lines'];

        // Enforce unique inventory_item_id per request
        $ids = array_map(fn ($l) => (string) $l['inventory_item_id'], $lines);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'lines' => ['lines must contain unique inventory_item_id values.'],
            ]);
        }

        // Pre-transaction branch access fast-fail is OK to "return" (no state written yet)
        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array($branchId, $allowed, true)) {
            throw new CrossBranchAccessException($branchId, $companyId, 'Branch access denied');
        }

        $actorId = (string) $u->id;
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($request, $companyId, $branchId, $actorId, $lines, $data, $idempotencyKey) {

            // Defense-in-depth: branch belongs to company (inside TX)
            $branchOk = DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists();

            if (!$branchOk) {
                throw new CrossBranchAccessException($branchId, $companyId, 'Branch does not belong to company');
            }

            // Create header
            $outId = (string) Str::uuid();
            $outNumber = $this->generateOutNumber($outId);
            $outAt = $data['out_at'] ?? now();

            DB::table('kitchen_outs')->insert([
                'id' => $outId,
                'branch_id' => $branchId,
                'out_number' => $outNumber,
                'out_at' => $outAt,
                'created_by' => $actorId,
                'meta' => json_encode($data['meta'] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Insert intent lines
            foreach ($lines as $ln) {
                DB::table('kitchen_out_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'kitchen_out_id' => $outId,
                    'inventory_item_id' => (string) $ln['inventory_item_id'],
                    'qty' => $this->dec3((string) $ln['qty']),
                    'remarks' => $ln['remarks'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $allocations = [];

            // FIFO consume per line
            foreach ($lines as $ln) {
                $itemId = (string) $ln['inventory_item_id'];
                $need = $this->dec3((string) $ln['qty']); // string scale(3)

                // Lock inventory_items row
                $item = DB::selectOne(
                    "select id, branch_id, on_hand
                     from inventory_items
                     where id = ? and branch_id = ?
                     for update",
                    [$itemId, $branchId]
                );

                if (!$item) {
                    throw new InsufficientStockException(
                        $branchId,
                        $itemId,
                        $need,
                        '0.000',
                        'Item not available in this branch'
                    );
                }

                // Lock FIFO lots
                $lots = DB::select(
                    "select id, remaining_qty, received_at
                     from inventory_lots
                     where branch_id = ?
                       and inventory_item_id = ?
                       and remaining_qty > 0
                     order by received_at asc, id asc
                     for update",
                    [$branchId, $itemId]
                );

                $available = '0.000';
                foreach ($lots as $lot) {
                    $available = bcadd($available, $this->dec3((string) $lot->remaining_qty), 3);
                }

                if (bccomp($available, $need, 3) < 0) {
                    throw new InsufficientStockException(
                        $branchId,
                        $itemId,
                        $need,
                        $available,
                        'Insufficient stock for FIFO consumption'
                    );
                }

                // Allocate across lots
                foreach ($lots as $lot) {
                    if (bccomp($need, '0.000', 3) <= 0) break;

                    $lotRemaining = $this->dec3((string) $lot->remaining_qty);
                    if (bccomp($lotRemaining, '0.000', 3) <= 0) continue;

                    $take = (bccomp($lotRemaining, $need, 3) <= 0) ? $lotRemaining : $need;

                    // Decrement lot
                    DB::update(
                        "update inventory_lots
                         set remaining_qty = remaining_qty - ?
                         where id = ?",
                        [$take, $lot->id]
                    );

                    // Insert OUT movement (dual-write source_* and ref_*)
                    DB::table('inventory_movements')->insert([
                        'id' => (string) Str::uuid(),
                        'branch_id' => $branchId,
                        'inventory_item_id' => $itemId,
                        'type' => 'OUT',
                        'qty' => $take,

                        'inventory_lot_id' => $lot->id,

                        'source_type' => 'KITCHEN_OUT',
                        'source_id' => $outId,
                        'ref_type' => 'KITCHEN_OUT',
                        'ref_id' => $outId,

                        'actor_id' => $actorId,
                        'note' => $ln['remarks'] ?? null,

                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $allocations[] = [
                        'inventory_item_id' => $itemId,
                        'inventory_lot_id' => (string) $lot->id,
                        'qty' => $take,
                    ];

                    $need = bcsub($need, $take, 3);
                }

                // Recompute on_hand from lots (strong invariant) INSIDE TX
                $lotsSumRow = DB::selectOne(
                    "select coalesce(sum(remaining_qty), 0) as lots_sum
                     from inventory_lots
                     where branch_id = ? and inventory_item_id = ?",
                    [$branchId, $itemId]
                );

                $lotsSum = $this->dec3((string) $lotsSumRow->lots_sum);

                DB::update(
                    "update inventory_items
                     set on_hand = ?
                     where id = ? and branch_id = ?",
                    [$lotsSum, $itemId, $branchId]
                );
            }

            // Audit (exact signature)
            Audit::log($request, 'create', 'kitchen_outs', $outId, [
                'branch_id' => $branchId,
                'out_number' => $outNumber,
                'lines' => array_map(function ($ln) {
                    return [
                        'inventory_item_id' => (string) $ln['inventory_item_id'],
                        'qty' => $this->dec3((string) $ln['qty']),
                        'remarks' => $ln['remarks'] ?? null,
                    ];
                }, $lines),
                'allocations' => $allocations,
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json([
                'data' => [
                    'id' => $outId,
                    'branch_id' => $branchId,
                    'out_number' => $outNumber,
                    'out_at' => (string) $outAt,
                    'allocations' => $allocations,
                ],
            ], 201);
        });
    }

    private function generateOutNumber(string $outId): string
    {
        return 'KO-' . now()->format('Ymd-His') . '-' . strtoupper(substr(str_replace('-', '', $outId), 0, 8));
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
