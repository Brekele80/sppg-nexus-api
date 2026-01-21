<?php

namespace App\Http\Controllers;

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
     */
    public function store(Request $request)
    {
        $u = AuthUser::get($request);
        // Role is enforced in route middleware: requireRole:CHEF (optionally DC_ADMIN)

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

        // enforce unique inventory_item_id per request
        $ids = array_map(fn($l) => (string)$l['inventory_item_id'], $lines);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'lines' => ['lines must contain unique inventory_item_id values.'],
            ]);
        }

        // Branch access check (fast fail)
        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array($branchId, $allowed, true)) {
            return response()->json([
                'error' => [
                    'code' => 'cross_branch_access',
                    'message' => 'Branch access denied',
                    'details' => ['branch_id' => $branchId],
                ]
            ], 403);
        }

        $actorId = (string) $u->id;
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($request, $companyId, $branchId, $actorId, $lines, $data, $idempotencyKey) {

            // defense-in-depth: branch belongs to company
            $branchOk = DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', $companyId)
                ->exists();
            if (!$branchOk) {
                return response()->json([
                    'error' => [
                        'code' => 'cross_branch_access',
                        'message' => 'Branch does not belong to company',
                        'details' => ['branch_id' => $branchId, 'company_id' => $companyId],
                    ]
                ], 403);
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
                $need = $this->dec3((string) $ln['qty']); // string with 3 decimals

                // Lock inventory_items row
                $item = DB::selectOne(
                    "select id, branch_id, on_hand
                     from inventory_items
                     where id = ? and branch_id = ?
                     for update",
                    [$itemId, $branchId]
                );

                if (!$item) {
                    return response()->json([
                        'error' => [
                            'code' => 'insufficient_stock',
                            'message' => 'Item not available in this branch',
                            'details' => [
                                'branch_id' => $branchId,
                                'inventory_item_id' => $itemId,
                                'requested_qty' => $need,
                                'available_qty' => '0.000',
                            ],
                        ]
                    ], 409);
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

                $available = "0.000";
                foreach ($lots as $lot) {
                    $available = bcadd($available, $this->dec3((string)$lot->remaining_qty), 3);
                }

                if (bccomp($available, $need, 3) < 0) {
                    return response()->json([
                        'error' => [
                            'code' => 'insufficient_stock',
                            'message' => 'Insufficient stock for FIFO consumption',
                            'details' => [
                                'branch_id' => $branchId,
                                'inventory_item_id' => $itemId,
                                'requested_qty' => $need,
                                'available_qty' => $available,
                            ],
                        ]
                    ], 409);
                }

                // Allocate across lots
                foreach ($lots as $lot) {
                    if (bccomp($need, "0.000", 3) <= 0) break;

                    $lotRemaining = $this->dec3((string)$lot->remaining_qty);
                    if (bccomp($lotRemaining, "0.000", 3) <= 0) continue;

                    $take = (bccomp($lotRemaining, $need, 3) <= 0) ? $lotRemaining : $need;

                    // decrement lot
                    DB::update(
                        "update inventory_lots
                         set remaining_qty = remaining_qty - ?
                         where id = ?",
                        [$take, $lot->id]
                    );

                    // insert OUT movement (dual-write source_* and ref_*)
                    DB::table('inventory_movements')->insert([
                        'id' => (string) Str::uuid(),
                        'branch_id' => $branchId,
                        'inventory_item_id' => $itemId,
                        'type' => 'OUT',
                        'qty' => $take,
                        'ref_type' => 'KITCHEN_OUT',
                        'ref_id' => $outId,
                        'source_type' => 'KITCHEN_OUT',
                        'source_id' => $outId,
                        'actor_id' => $actorId,
                        'note' => $ln['remarks'] ?? null,
                        'inventory_lot_id' => $lot->id,
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

                // recompute on_hand from lots (strong invariant)
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

            // Audit: use your established signature
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
        // Simple, unique-ish. Unique constraint is (branch_id,out_number) so branch separation helps.
        return 'KO-' . now()->format('Ymd-His') . '-' . strtoupper(substr(str_replace('-', '', $outId), 0, 8));
    }

    private function dec3(string $n): string
    {
        if (!is_numeric($n)) $n = "0";
        $parts = explode('.', $n, 2);
        $int = preg_replace('/[^0-9\-]/', '', $parts[0] ?: '0');
        $dec = preg_replace('/[^0-9]/', '', $parts[1] ?? '0');
        $dec = substr(str_pad($dec, 3, '0'), 0, 3);
        if ($int === '' || $int === '-') $int = '0';
        return $int . '.' . $dec;
    }
}
