<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\KitchenOutPostingService;
use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

class KitchenOutController extends Controller
{
    /**
     * POST /api/kitchen/outs
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
            return response()->json(['error' => ['code' => 'no_branch_access', 'message' => 'No branch access']], 403);
        }

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'out_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.inventory_item_id' => 'required|uuid',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.remarks' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $u, $companyId, $allowedBranches, $data) {

            $branchId = (string) $data['branch_id'];

            // Company boundary (defense-in-depth)
            $branchOk = DB::table('branches')
                ->where('id', $branchId)
                ->where('company_id', (string) $companyId)
                ->exists();
            if (!$branchOk) abort(404, 'Not found');

            // Branch access
            if (!in_array($branchId, $allowedBranches, true)) abort(403, 'Forbidden (no branch access)');

            $now = now();

            // Create header
            $koId = (string) Str::uuid();

            // Best effort unique(branch_id, out_number) collision retry
            $tries = 0;
            $outNumber = $this->generateOutNumber();
            while ($tries < 3) {
                try {
                    DB::table('kitchen_outs')->insert([
                        'id' => $koId,
                        'branch_id' => $branchId,
                        'out_number' => $outNumber,
                        'out_at' => $data['out_at'] ?? $now,
                        'status' => 'DRAFT',
                        'created_by' => (string) $u->id,
                        'notes' => $data['notes'] ?? null,
                        'meta' => DB::raw("'{}'::jsonb"),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    break;
                } catch (QueryException $e) {
                    $sqlState = $e->errorInfo[0] ?? null;
                    if ($sqlState === '23505') {
                        $outNumber = $this->generateOutNumber();
                        $tries++;
                        continue;
                    }
                    throw $e;
                }
            }

            if ($tries >= 3) {
                abort(500, 'Failed to allocate kitchen out number');
            }

            // Insert lines
            $lineIds = [];
            foreach ($data['lines'] as $idx => $row) {
                $invItemId = (string) $row['inventory_item_id'];
                $qty = (float) $row['qty'];
                if ($qty <= 0) {
                    throw ValidationException::withMessages(["lines.$idx.qty" => ['qty must be > 0']]);
                }

                // Validate item belongs to branch
                $invItem = DB::table('inventory_items')
                    ->where('id', $invItemId)
                    ->where('branch_id', $branchId)
                    // row lock to serialize against concurrent writes; safe and consistent for audit-grade ops
                    ->lockForUpdate()
                    ->first();

                if (!$invItem) {
                    throw ValidationException::withMessages([
                        "lines.$idx.inventory_item_id" => ["inventory_item_id {$invItemId} not found in branch"],
                    ]);
                }

                $lineId = (string) Str::uuid();

                // Schema enforces unique(kitchen_out_id, inventory_item_id)
                try {
                    DB::table('kitchen_out_lines')->insert([
                        'id' => $lineId,
                        'kitchen_out_id' => $koId,
                        'inventory_item_id' => $invItemId,
                        'qty' => $qty,
                        'remarks' => $row['remarks'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
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
                'branch_id' => $branchId,
                'out_number' => $outNumber,
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
                'line_ids' => $lineIds,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadGuarded($request, (string) $companyId, $koId), 200);
        });
    }

    /**
     * PATCH /api/kitchen/outs/{id}
     * Update lines while DRAFT only.
     *
     * Semantics:
     * - Upserts by inventory_item_id (schema enforces unique per item).
     * - Removes items not included (full replace).
     * - Optionally update header notes.
     */
    public function update(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.inventory_item_id' => 'required|uuid',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.remarks' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $u, $companyId, $id, $data) {

            $ko = DB::table('kitchen_outs')
                ->where('id', (string) $id)
                ->lockForUpdate()
                ->first();

            if (!$ko) abort(404, 'Not found');

            $branchOk = DB::table('branches')
                ->where('id', (string) $ko->branch_id)
                ->where('company_id', (string) $companyId)
                ->exists();
            if (!$branchOk) abort(404, 'Not found');

            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string) $ko->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            $status = (string) ($ko->status ?? 'DRAFT');
            if ($status !== 'DRAFT') {
                return response()->json(['error' => ['code' => 'kitchen_out_not_editable', 'message' => 'Only DRAFT can be updated']], 409);
            }

            $now = now();

            // Existing lines keyed by inventory_item_id
            $existing = DB::table('kitchen_out_lines')
                ->where('kitchen_out_id', (string) $ko->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('inventory_item_id');

            $incomingIds = [];
            $changes = [];

            foreach ($data['lines'] as $idx => $row) {
                $invItemId = (string) $row['inventory_item_id'];
                $qty = (float) $row['qty'];
                if ($qty <= 0) {
                    throw ValidationException::withMessages(["lines.$idx.qty" => ['qty must be > 0']]);
                }

                // Validate item belongs to branch
                $invItem = DB::table('inventory_items')
                    ->where('id', $invItemId)
                    ->where('branch_id', (string) $ko->branch_id)
                    ->lockForUpdate()
                    ->first();

                if (!$invItem) {
                    throw ValidationException::withMessages([
                        "lines.$idx.inventory_item_id" => ["inventory_item_id {$invItemId} not found in branch"],
                    ]);
                }

                $incomingIds[] = $invItemId;

                $newRemarks = $row['remarks'] ?? null;

                if (isset($existing[$invItemId])) {
                    $line = $existing[$invItemId];

                    $before = [
                        'qty' => (float) $line->qty,
                        'remarks' => $line->remarks !== null ? (string) $line->remarks : null,
                    ];

                    DB::table('kitchen_out_lines')
                        ->where('id', (string) $line->id)
                        ->update([
                            'qty' => $qty,
                            'remarks' => $newRemarks,
                            'updated_at' => $now,
                        ]);

                    $after = [
                        'qty' => $qty,
                        'remarks' => $newRemarks !== null ? (string) $newRemarks : null,
                    ];

                    if ($before !== $after) {
                        $changes[] = [
                            'kitchen_out_line_id' => (string) $line->id,
                            'inventory_item_id' => $invItemId,
                            'before' => $before,
                            'after' => $after,
                        ];
                    }
                } else {
                    $lineId = (string) Str::uuid();

                    try {
                        DB::table('kitchen_out_lines')->insert([
                            'id' => $lineId,
                            'kitchen_out_id' => (string) $ko->id,
                            'inventory_item_id' => $invItemId,
                            'qty' => $qty,
                            'remarks' => $newRemarks,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } catch (QueryException $e) {
                        $sqlState = $e->errorInfo[0] ?? null;
                        if ($sqlState === '23505') {
                            // extremely rare due to tx+locks, but keep deterministic
                            throw ValidationException::withMessages([
                                "lines.$idx.inventory_item_id" => ['Duplicate inventory_item_id in this kitchen out'],
                            ]);
                        }
                        throw $e;
                    }

                    $changes[] = [
                        'kitchen_out_line_id' => $lineId,
                        'inventory_item_id' => $invItemId,
                        'before' => null,
                        'after' => [
                            'qty' => $qty,
                            'remarks' => $newRemarks !== null ? (string) $newRemarks : null,
                        ],
                    ];
                }
            }

            // Full replace: remove lines not in incoming
            $incomingIds = array_values(array_unique($incomingIds));
            $toDelete = $existing->keys()->filter(fn ($k) => !in_array((string) $k, $incomingIds, true))->values()->all();

            if (!empty($toDelete)) {
                $deleted = DB::table('kitchen_out_lines')
                    ->where('kitchen_out_id', (string) $ko->id)
                    ->whereIn('inventory_item_id', $toDelete)
                    ->get();

                DB::table('kitchen_out_lines')
                    ->where('kitchen_out_id', (string) $ko->id)
                    ->whereIn('inventory_item_id', $toDelete)
                    ->delete();

                foreach ($deleted as $d) {
                    $changes[] = [
                        'kitchen_out_line_id' => (string) $d->id,
                        'inventory_item_id' => (string) $d->inventory_item_id,
                        'before' => [
                            'qty' => (float) $d->qty,
                            'remarks' => $d->remarks !== null ? (string) $d->remarks : null,
                        ],
                        'after' => null,
                        'deleted' => true,
                    ];
                }
            }

            // Optional: update header notes
            $headerChanges = [];
            if (array_key_exists('notes', $data)) {
                $beforeNotes = $ko->notes !== null ? (string) $ko->notes : null;
                $afterNotes = $data['notes'] !== null ? (string) $data['notes'] : null;

                if ($beforeNotes !== $afterNotes) {
                    DB::table('kitchen_outs')
                        ->where('id', (string) $ko->id)
                        ->update([
                            'notes' => $afterNotes,
                            'updated_at' => $now,
                        ]);

                    $headerChanges['notes'] = ['before' => $beforeNotes, 'after' => $afterNotes];
                }
            }

            if (!empty($changes) || !empty($headerChanges)) {
                Audit::log($request, 'update', 'kitchen_outs', (string) $ko->id, [
                    'branch_id' => (string) $ko->branch_id,
                    'header_changes' => $headerChanges ?: null,
                    'line_changes' => $changes ?: null,
                    'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
                ]);
            }

            return response()->json($this->loadGuarded($request, (string) $companyId, (string) $ko->id), 200);
        });
    }

    /**
     * POST /api/kitchen/outs/{id}/submit
     * Moves DRAFT -> SUBMITTED
     */
    public function submit(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        return DB::transaction(function () use ($request, $u, $companyId, $id) {

            $ko = DB::table('kitchen_outs')
                ->where('id', (string) $id)
                ->lockForUpdate()
                ->first();

            if (!$ko) abort(404, 'Not found');

            $branchOk = DB::table('branches')
                ->where('id', (string) $ko->branch_id)
                ->where('company_id', (string) $companyId)
                ->exists();
            if (!$branchOk) abort(404, 'Not found');

            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string) $ko->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

            $status = (string) ($ko->status ?? 'DRAFT');

            if ($status === 'SUBMITTED') {
                // idempotent replay
                return response()->json($this->loadGuarded($request, (string) $companyId, (string) $ko->id), 200);
            }

            if ($status !== 'DRAFT') {
                return response()->json(['error' => ['code' => 'kitchen_out_not_submittable', 'message' => 'Only DRAFT can be submitted']], 409);
            }

            // Require at least 1 line
            $count = DB::table('kitchen_out_lines')
                ->where('kitchen_out_id', (string) $ko->id)
                ->count();

            if ($count <= 0) {
                throw ValidationException::withMessages(['lines' => ['No kitchen out lines']]);
            }

            $now = now();

            DB::table('kitchen_outs')
                ->where('id', (string) $ko->id)
                ->update([
                    'status' => 'SUBMITTED',
                    'submitted_at' => $now,
                    'submitted_by' => (string) $u->id,
                    'updated_at' => $now,
                ]);

            Audit::log($request, 'submit', 'kitchen_outs', (string) $ko->id, [
                'from' => 'DRAFT',
                'to' => 'SUBMITTED',
                'branch_id' => (string) $ko->branch_id,
                'out_number' => (string) ($ko->out_number ?? ''),
                'submitted_at' => (string) $now,
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadGuarded($request, (string) $companyId, (string) $ko->id), 200);
        });
    }

    /**
     * POST /api/kitchen/outs/{id}/post
     * FIFO-posts to inventory ledger via KitchenOutPostingService
     */
    public function post(Request $request, string $id, KitchenOutPostingService $svc)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        // service enforces company, branch, status gate, fifo, recompute, audit
        $result = $svc->post($id, $request);
        return response()->json($result, 200);
    }

    /**
     * GET /api/kitchen/outs/{id}
     */
    public function show(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['CHEF', 'DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);
        return response()->json($this->loadGuarded($request, (string) $companyId, (string) $id), 200);
    }

    private function loadGuarded(Request $request, string $companyId, string $id): array
    {
        $ko = DB::table('kitchen_outs')->where('id', (string) $id)->first();
        if (!$ko) abort(404, 'Not found');

        $branchOk = DB::table('branches')
            ->where('id', (string) $ko->branch_id)
            ->where('company_id', (string) $companyId)
            ->exists();
        if (!$branchOk) abort(404, 'Not found');

        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array((string) $ko->branch_id, $allowed, true)) abort(403, 'Forbidden (no branch access)');

        $lines = DB::table('kitchen_out_lines')
            ->where('kitchen_out_id', (string) $ko->id)
            ->orderBy('created_at')
            ->get();

        $moves = DB::table('inventory_movements')
            ->where('source_type', 'KITCHEN_OUT')
            ->where('source_id', (string) $ko->id)
            ->orderBy('created_at')
            ->get();

        return [
            'id' => (string) $ko->id,
            'branch_id' => (string) $ko->branch_id,
            'out_number' => (string) ($ko->out_number ?? ''),
            'status' => (string) ($ko->status ?? ''),
            'out_at' => $ko->out_at ?? null,
            'notes' => $ko->notes ?? null,

            'created_by' => $ko->created_by ?? null,
            'submitted_at' => $ko->submitted_at ?? null,
            'submitted_by' => $ko->submitted_by ?? null,
            'posted_at' => $ko->posted_at ?? null,
            'posted_by' => $ko->posted_by ?? null,

            'meta' => $ko->meta ?? null,
            'created_at' => $ko->created_at ?? null,
            'updated_at' => $ko->updated_at ?? null,

            'lines' => $lines,
            'movements' => $moves,
        ];
    }

    private function generateOutNumber(): string
    {
        return 'KO-' . now()->format('Ymd') . '-' . random_int(100000, 999999);
    }
}
