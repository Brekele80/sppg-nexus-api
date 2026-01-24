<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\KitchenOutPostingService;
use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KitchenOutController extends Controller
{
    /**
     * POST /api/kitchen/out
     * Create a DRAFT kitchen out with lines.
     *
     * Body:
     * {
     *   "branch_id": "...",
     *   "out_at": "2026-01-24T10:00:00Z" (optional),
     *   "notes": "..." (optional),
     *   "lines": [{ "inventory_item_id": "...", "qty": 1.5, "remarks": "..." }, ...]
     * }
     */
    public function create(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $allowedBranches = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranches)) {
            throw new HttpException(403, 'No branch access');
        }

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'out_at'    => 'nullable|date',
            'notes'     => 'nullable|string',
            'lines'     => 'required|array|min:1',
            'lines.*.inventory_item_id' => 'required|uuid',
            'lines.*.qty'               => 'required|numeric|min:0.0001',
            'lines.*.remarks'           => 'nullable|string',
        ]);

        $idempotencyKey = (string)$request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($request, $u, $companyId, $allowedBranches, $data, $idempotencyKey) {
            $branchId = (string)$data['branch_id'];

            // Company boundary (defense-in-depth)
            $branchOk = DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', (string)$companyId)
                ->exists();

            if (!$branchOk) {
                throw new HttpException(404, 'Not found');
            }

            // Branch access
            if (!in_array($branchId, $allowedBranches, true)) {
                throw new HttpException(403, 'Forbidden (no branch access)');
            }

            $now = now();

            $koId = (string)Str::uuid();

            // Allocate out_number with collision retry (unique(branch_id, out_number))
            $tries = 0;
            $outNumber = $this->generateOutNumber();
            while ($tries < 3) {
                try {
                    DB::table('kitchen_outs')->insert([
                        'id'         => $koId,
                        'branch_id'  => $branchId,
                        'out_number' => $outNumber,
                        'out_at'     => $data['out_at'] ?? $now,
                        'status'     => 'DRAFT',
                        'created_by' => (string)$u->id,
                        'notes'      => $data['notes'] ?? null,
                        'meta'       => DB::raw("'{}'::jsonb"),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    break;
                } catch (QueryException $e) {
                    $sqlState = $e->errorInfo[0] ?? null;
                    // Postgres unique violation
                    if ($sqlState === '23505') {
                        $outNumber = $this->generateOutNumber();
                        $tries++;
                        continue;
                    }
                    throw $e;
                }
            }

            if ($tries >= 3) {
                throw new HttpException(500, 'Failed to allocate kitchen out number');
            }

            // Insert lines (validate each inventory_item belongs to branch)
            $lineIds = [];
            foreach ($data['lines'] as $idx => $row) {
                $invItemId = (string)$row['inventory_item_id'];
                $qty = $this->dec3((string)$row['qty']);

                if (bccomp($qty, '0.000', 3) <= 0) {
                    throw ValidationException::withMessages([
                        "lines.$idx.qty" => ['qty must be > 0'],
                    ]);
                }

                // Lock inventory item row to serialize against concurrent changes
                $invItem = DB::table('inventory_items')
                    ->where('id', $invItemId)
                    ->where('branch_id', $branchId)
                    ->lockForUpdate()
                    ->first();

                if (!$invItem) {
                    throw ValidationException::withMessages([
                        "lines.$idx.inventory_item_id" => ["inventory_item_id {$invItemId} not found in branch"],
                    ]);
                }

                $lineId = (string)Str::uuid();

                try {
                    DB::table('kitchen_out_lines')->insert([
                        'id'              => $lineId,
                        'kitchen_out_id'  => $koId,
                        'inventory_item_id' => $invItemId,
                        'qty'             => $qty,
                        'remarks'         => $row['remarks'] ?? null,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                } catch (QueryException $e) {
                    $sqlState = $e->errorInfo[0] ?? null;
                    if ($sqlState === '23505') {
                        throw ValidationException::withMessages([
                            "lines.$idx.inventory_item_id" => ['Duplicate inventory_item_id in this kitchen out'],
                        ]);
                    }
                    throw $e;
                }

                $lineIds[] = $lineId;
            }

            Audit::log($request, 'create', 'kitchen_outs', $koId, [
                'branch_id'       => $branchId,
                'out_number'      => $outNumber,
                'status'          => 'DRAFT',
                'notes'           => $data['notes'] ?? null,
                'line_ids'        => $lineIds,
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json($this->loadGuarded($request, (string)$companyId, $koId), 200);
        });
    }

    /**
     * PATCH /api/kitchen/out/{id}
     * Update header (notes) and lines while DRAFT only.
     *
     * Semantics:
     * - Upserts by inventory_item_id (schema enforces unique per item).
     * - Removes lines not included (full replace).
     */
    public function update(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string)$request->header('Idempotency-Key', '');

        $data = $request->validate([
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.inventory_item_id' => 'required|uuid',
            'lines.*.qty'               => 'required|numeric|min:0.0001',
            'lines.*.remarks'           => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $u, $companyId, $id, $data, $idempotencyKey) {
            $ko = DB::table('kitchen_outs')
                ->where('id', (string)$id)
                ->lockForUpdate()
                ->first();

            if (!$ko) {
                throw new HttpException(404, 'Not found');
            }

            // Company boundary via branch
            $branchOk = DB::table('branches')
                ->where('id', (string)$ko->branch_id)
                ->where('company_id', (string)$companyId)
                ->exists();

            if (!$branchOk) {
                throw new HttpException(404, 'Not found');
            }

            // Branch access
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string)$ko->branch_id, $allowed, true)) {
                throw new HttpException(403, 'Forbidden (no branch access)');
            }

            $status = (string)($ko->status ?? 'DRAFT');
            if ($status !== 'DRAFT') {
                throw new HttpException(409, 'Only DRAFT can be updated');
            }

            $now = now();

            // Existing lines keyed by inventory_item_id (locked)
            $existing = DB::table('kitchen_out_lines')
                ->where('kitchen_out_id', (string)$ko->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('inventory_item_id');

            $incomingIds = [];
            $lineChanges = [];

            foreach ($data['lines'] as $idx => $row) {
                $invItemId = (string)$row['inventory_item_id'];
                $qty = $this->dec3((string)$row['qty']);
                $remarks = $row['remarks'] ?? null;

                if (bccomp($qty, '0.000', 3) <= 0) {
                    throw ValidationException::withMessages([
                        "lines.$idx.qty" => ['qty must be > 0'],
                    ]);
                }

                // Validate item belongs to branch (lock)
                $invItem = DB::table('inventory_items')
                    ->where('id', $invItemId)
                    ->where('branch_id', (string)$ko->branch_id)
                    ->lockForUpdate()
                    ->first();

                if (!$invItem) {
                    throw ValidationException::withMessages([
                        "lines.$idx.inventory_item_id" => ["inventory_item_id {$invItemId} not found in branch"],
                    ]);
                }

                $incomingIds[] = $invItemId;

                if (isset($existing[$invItemId])) {
                    $line = $existing[$invItemId];

                    $before = [
                        'qty'     => $this->dec3((string)$line->qty),
                        'remarks' => $line->remarks !== null ? (string)$line->remarks : null,
                    ];

                    $after = [
                        'qty'     => $qty,
                        'remarks' => $remarks !== null ? (string)$remarks : null,
                    ];

                    if ($before !== $after) {
                        DB::table('kitchen_out_lines')
                            ->where('id', (string)$line->id)
                            ->update([
                                'qty'        => $qty,
                                'remarks'    => $remarks,
                                'updated_at' => $now,
                            ]);

                        $lineChanges[] = [
                            'kitchen_out_line_id' => (string)$line->id,
                            'inventory_item_id'   => $invItemId,
                            'before'              => $before,
                            'after'               => $after,
                        ];
                    }
                } else {
                    $lineId = (string)Str::uuid();

                    try {
                        DB::table('kitchen_out_lines')->insert([
                            'id'               => $lineId,
                            'kitchen_out_id'   => (string)$ko->id,
                            'inventory_item_id'=> $invItemId,
                            'qty'              => $qty,
                            'remarks'          => $remarks,
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ]);
                    } catch (QueryException $e) {
                        $sqlState = $e->errorInfo[0] ?? null;
                        if ($sqlState === '23505') {
                            throw ValidationException::withMessages([
                                "lines.$idx.inventory_item_id" => ['Duplicate inventory_item_id in this kitchen out'],
                            ]);
                        }
                        throw $e;
                    }

                    $lineChanges[] = [
                        'kitchen_out_line_id' => $lineId,
                        'inventory_item_id'   => $invItemId,
                        'before'              => null,
                        'after'               => [
                            'qty'     => $qty,
                            'remarks' => $remarks !== null ? (string)$remarks : null,
                        ],
                    ];
                }
            }

            // Delete lines not in incoming (full replace)
            $incomingIds = array_values(array_unique($incomingIds));
            $toDelete = $existing->keys()
                ->filter(fn($k) => !in_array((string)$k, $incomingIds, true))
                ->values()
                ->all();

            if (!empty($toDelete)) {
                $deleted = DB::table('kitchen_out_lines')
                    ->where('kitchen_out_id', (string)$ko->id)
                    ->whereIn('inventory_item_id', $toDelete)
                    ->get();

                DB::table('kitchen_out_lines')
                    ->where('kitchen_out_id', (string)$ko->id)
                    ->whereIn('inventory_item_id', $toDelete)
                    ->delete();

                foreach ($deleted as $d) {
                    $lineChanges[] = [
                        'kitchen_out_line_id' => (string)$d->id,
                        'inventory_item_id'   => (string)$d->inventory_item_id,
                        'before'              => [
                            'qty'     => $this->dec3((string)$d->qty),
                            'remarks' => $d->remarks !== null ? (string)$d->remarks : null,
                        ],
                        'after'               => null,
                        'deleted'             => true,
                    ];
                }
            }

            // Optional: update header notes
            $headerChanges = null;
            if (array_key_exists('notes', $data)) {
                $beforeNotes = $ko->notes !== null ? (string)$ko->notes : null;
                $afterNotes  = $data['notes'] !== null ? (string)$data['notes'] : null;

                if ($beforeNotes !== $afterNotes) {
                    DB::table('kitchen_outs')
                        ->where('id', (string)$ko->id)
                        ->update([
                            'notes'      => $afterNotes,
                            'updated_at' => $now,
                        ]);

                    $headerChanges = [
                        'notes' => ['before' => $beforeNotes, 'after' => $afterNotes],
                    ];
                }
            }

            if (!empty($lineChanges) || $headerChanges !== null) {
                Audit::log($request, 'update', 'kitchen_outs', (string)$ko->id, [
                    'branch_id'       => (string)$ko->branch_id,
                    'header_changes'  => $headerChanges,
                    'line_changes'    => $lineChanges ?: null,
                    'idempotency_key' => $idempotencyKey,
                ]);
            }

            return response()->json($this->loadGuarded($request, (string)$companyId, (string)$ko->id), 200);
        });
    }

    /**
     * POST /api/kitchen/out/{id}/submit
     * Moves DRAFT -> SUBMITTED
     */
    public function submit(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        $idempotencyKey = (string)$request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($request, $u, $companyId, $id, $idempotencyKey) {
            $ko = DB::table('kitchen_outs')
                ->where('id', (string)$id)
                ->lockForUpdate()
                ->first();

            if (!$ko) {
                throw new HttpException(404, 'Not found');
            }

            // Company boundary via branch
            $branchOk = DB::table('branches')
                ->where('id', (string)$ko->branch_id)
                ->where('company_id', (string)$companyId)
                ->exists();

            if (!$branchOk) {
                throw new HttpException(404, 'Not found');
            }

            // Branch access
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string)$ko->branch_id, $allowed, true)) {
                throw new HttpException(403, 'Forbidden (no branch access)');
            }

            $status = (string)($ko->status ?? 'DRAFT');

            // Idempotent replay
            if ($status === 'SUBMITTED') {
                return response()->json($this->loadGuarded($request, (string)$companyId, (string)$ko->id), 200);
            }

            if ($status !== 'DRAFT') {
                throw new HttpException(409, 'Only DRAFT can be submitted');
            }

            $count = DB::table('kitchen_out_lines')
                ->where('kitchen_out_id', (string)$ko->id)
                ->count();

            if ($count <= 0) {
                throw ValidationException::withMessages([
                    'lines' => ['No kitchen out lines'],
                ]);
            }

            $now = now();

            DB::table('kitchen_outs')
                ->where('id', (string)$ko->id)
                ->update([
                    'status'       => 'SUBMITTED',
                    'submitted_at' => $now,
                    'submitted_by' => (string)$u->id,
                    'updated_at'   => $now,
                ]);

            Audit::log($request, 'submit', 'kitchen_outs', (string)$ko->id, [
                'from'           => 'DRAFT',
                'to'             => 'SUBMITTED',
                'branch_id'      => (string)$ko->branch_id,
                'out_number'     => (string)($ko->out_number ?? ''),
                'submitted_at'   => (string)$now,
                'idempotency_key'=> $idempotencyKey,
            ]);

            return response()->json($this->loadGuarded($request, (string)$companyId, (string)$ko->id), 200);
        });
    }

    /**
     * POST /api/kitchen/out/{id}/post
     * FIFO-posts to inventory ledger via KitchenOutPostingService
     */
    public function post(Request $request, string $id, KitchenOutPostingService $svc)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $result = $svc->post($id, $request);
        return response()->json($result, 200);
    }

    /**
     * GET /api/kitchen/out/{id}
     */
    public function show(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        return response()->json(
            $this->loadGuarded($request, (string)$companyId, (string)$id),
            200
        );
    }

    private function loadGuarded(Request $request, string $companyId, string $id): array
    {
        $ko = DB::table('kitchen_outs')->where('id', $id)->first();
        if (!$ko) {
            throw new HttpException(404, 'Not found');
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
            ->where('kitchen_out_id', (string)$ko->id)
            ->orderBy('created_at')
            ->get();

        $moves = DB::table('inventory_movements')
            ->where('source_type', 'KITCHEN_OUT')
            ->where('source_id', (string)$ko->id)
            ->orderBy('created_at')
            ->get();

        return [
            'id'          => (string)$ko->id,
            'branch_id'   => (string)$ko->branch_id,
            'out_number'  => (string)($ko->out_number ?? ''),
            'status'      => (string)($ko->status ?? ''),
            'out_at'      => $ko->out_at ?? null,

            'created_by'  => $ko->created_by ?? null,

            'submitted_at'=> $ko->submitted_at ?? null,
            'submitted_by'=> $ko->submitted_by ?? null,

            'posted_at'   => $ko->posted_at ?? null,
            'posted_by'   => $ko->posted_by ?? null,

            'notes'       => $ko->notes ?? null,
            'meta'        => $ko->meta ?? null,

            'created_at'  => $ko->created_at ?? null,
            'updated_at'  => $ko->updated_at ?? null,

            'lines'       => $lines,
            'movements'   => $moves,
        ];
    }

    private function generateOutNumber(): string
    {
        return 'KO-' . now()->format('Ymd') . '-' . random_int(100000, 999999);
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
