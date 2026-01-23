<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockAdjustmentPostingService;
use App\Models\StockAdjustment;
use App\Support\Audit;
use App\Support\AuthUser;
use App\Support\DocumentNo;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockAdjustmentController extends Controller
{
    /**
     * GET /api/dc/stock-adjustments
     *
     * READ access: DC_ADMIN + ACCOUNTING (read-only)
     */
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN', 'ACCOUNTING']);

        $companyId = AuthUser::requireCompanyContext($request);

        $allowed = AuthUser::allowedBranchIds($request);
        if (empty($allowed)) {
            return response()->json([
                'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company'],
            ], 403);
        }

        $data = $request->validate([
            'branch_id' => 'nullable|uuid',
            'status'    => 'nullable|string|in:DRAFT,SUBMITTED,APPROVED,POSTED,REJECTED,VOIDED',
            'q'         => 'nullable|string|max:200',
            'limit'     => 'nullable|integer|min:1|max:200',
        ]);

        $limit = (int)($data['limit'] ?? 50);
        $q     = isset($data['q']) ? trim((string)$data['q']) : '';

        $query = DB::table('stock_adjustments')
            ->where('company_id', (string)$companyId)
            ->whereIn('branch_id', $allowed)
            ->orderByDesc('created_at');

        if (!empty($data['branch_id'])) {
            if (!in_array((string)$data['branch_id'], $allowed, true)) {
                return response()->json([
                    'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch'],
                ], 403);
            }
            $query->where('branch_id', (string)$data['branch_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', (string)$data['status']);
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('adjustment_no', 'ilike', "%{$q}%")
                    ->orWhere('reason', 'ilike', "%{$q}%")
                    ->orWhere('notes', 'ilike', "%{$q}%");
            });
        }

        $rows = $query->limit($limit)->get([
            'id',
            'company_id',
            'branch_id',
            'adjustment_no',
            'status',
            'reason',
            'notes',
            'posted_at',
            'created_at',
            'updated_at',
        ]);

        return response()->json([
            'company_id' => (string)$companyId,
            'filters'    => [
                'branch_id' => $data['branch_id'] ?? null,
                'status'    => $data['status'] ?? null,
                'q'         => $q ?: null,
                'limit'     => $limit,
            ],
            'data' => $rows,
        ]);
    }

    /**
     * GET /api/dc/stock-adjustments/{id}
     *
     * READ access: DC_ADMIN + ACCOUNTING (read-only)
     */
    public function show(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN', 'ACCOUNTING']);

        $companyId = AuthUser::requireCompanyContext($request);

        $allowed = AuthUser::allowedBranchIds($request);
        if (empty($allowed)) {
            return response()->json([
                'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company'],
            ], 403);
        }

        $doc = DB::table('stock_adjustments')->where('id', $id)->first();
        if (!$doc) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Stock adjustment not found'],
            ], 404);
        }
        if ((string)$doc->company_id !== (string)$companyId) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Forbidden'],
            ], 403);
        }
        if (!in_array((string)$doc->branch_id, $allowed, true)) {
            return response()->json([
                'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch'],
            ], 403);
        }

        $items = DB::table('stock_adjustment_items')
            ->where('stock_adjustment_id', $id)
            ->orderBy('line_no')
            ->get();

        $attachments = DB::table('stock_adjustment_attachments')
            ->where('stock_adjustment_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'doc'         => $doc,
            'items'       => $items,
            'attachments' => $attachments,
        ]);
    }

    /**
     * POST /api/dc/stock-adjustments (create DRAFT)
     *
     * Mutations: DC_ADMIN only
     */
    public function create(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'reason'    => 'nullable|string|max:2000',
            'notes'     => 'nullable|string|max:2000',
            'items'     => 'required|array|min:1',

            'items.*.direction'         => 'required|string|in:IN,OUT',
            'items.*.inventory_item_id' => 'nullable|uuid',
            'items.*.item_name'         => 'required|string|max:255',
            'items.*.unit'              => 'nullable|string|max:30',
            'items.*.qty'               => 'required|numeric|min:0.001',

            'items.*.expiry_date'      => 'nullable|date',
            'items.*.unit_cost'        => 'nullable|numeric|min:0',
            'items.*.currency'         => 'nullable|string|max:10',
            'items.*.received_at'      => 'nullable|date',
            'items.*.preferred_lot_id' => 'nullable|uuid',
            'items.*.remarks'          => 'nullable|string|max:2000',
        ]);

        // Branch belongs to company (defense-in-depth)
        $branchOk = DB::table('branches')
            ->where('id', (string)$data['branch_id'])
            ->where('company_id', (string)$companyId)
            ->exists();

        if (!$branchOk) {
            return response()->json([
                'error' => ['code' => 'invalid_branch', 'message' => 'Branch not found in company'],
            ], 422);
        }

        // Access check (branch is allowed for user)
        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array((string)$data['branch_id'], $allowed, true)) {
            return response()->json([
                'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch'],
            ], 403);
        }

        // Commercial-grade validations: prevent SKU mismatch and enforce preferred_lot requires inventory_item_id
        foreach ($data['items'] as $idx => $it) {
            $line = $idx + 1;

            $direction = strtoupper(trim((string)($it['direction'] ?? '')));
            $itemName  = trim((string)($it['item_name'] ?? ''));

            $unit = $it['unit'] ?? null;
            if (is_string($unit)) {
                $unit = trim($unit);
                if ($unit === '') $unit = null;
            }

            $invId = !empty($it['inventory_item_id']) ? (string)$it['inventory_item_id'] : null;

            if ($direction === 'OUT' && $invId === null) {
                return response()->json([
                    'error' => [
                        'code'    => 'validation',
                        'message' => "items.$line.inventory_item_id is required for OUT lines to prevent SKU mismatch",
                    ],
                ], 422);
            }

            if (!empty($it['preferred_lot_id']) && $invId === null) {
                return response()->json([
                    'error' => [
                        'code'    => 'validation',
                        'message' => "items.$line.preferred_lot_id requires items.$line.inventory_item_id",
                    ],
                ], 422);
            }

            if ($invId !== null) {
                $inv = DB::table('inventory_items')
                    ->where('id', $invId)
                    ->where('branch_id', (string)$data['branch_id'])
                    ->first(['id', 'item_name', 'unit']);

                if (!$inv) {
                    return response()->json([
                        'error' => [
                            'code'    => 'validation',
                            'message' => "items.$line.inventory_item_id not found in this branch",
                        ],
                    ], 422);
                }

                if ((string)$inv->item_name !== $itemName) {
                    return response()->json([
                        'error' => [
                            'code'    => 'validation',
                            'message' => "items.$line.item_name mismatch for inventory_item_id (expected: {$inv->item_name})",
                        ],
                    ], 422);
                }

                $invUnit = $inv->unit !== null ? (string)$inv->unit : null;
                if (($invUnit ?? null) !== ($unit ?? null)) {
                    $eu = $invUnit ?? 'NULL';
                    $uu = $unit ?? 'NULL';
                    return response()->json([
                        'error' => [
                            'code'    => 'validation',
                            'message' => "items.$line.unit mismatch for inventory_item_id (expected: {$eu}, got: {$uu})",
                        ],
                    ], 422);
                }

                if (!empty($it['preferred_lot_id'])) {
                    $prefId = (string)$it['preferred_lot_id'];
                    $prefOk = DB::table('inventory_lots')
                        ->where('id', $prefId)
                        ->where('branch_id', (string)$data['branch_id'])
                        ->where('inventory_item_id', $invId)
                        ->exists();

                    if (!$prefOk) {
                        return response()->json([
                            'error' => [
                                'code'    => 'validation',
                                'message' => "items.$line.preferred_lot_id not found for this branch + inventory_item_id",
                            ],
                        ], 422);
                    }
                }
            }
        }

        // Retry for DocumentNo uniqueness collisions
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return DB::transaction(function () use ($request, $data, $companyId, $u) {

                    $branchOkTx = DB::table('branches')
                        ->where('id', (string)$data['branch_id'])
                        ->where('company_id', (string)$companyId)
                        ->exists();

                    if (!$branchOkTx) {
                        throw ValidationException::withMessages([
                            'branch_id' => ['Branch not found in company'],
                        ]);
                    }

                    $docId = (string)Str::uuid();
                    $adjNo = DocumentNo::next($companyId, 'stock_adjustments', 'SA', 'adjustment_no');

                    DB::table('stock_adjustments')->insert([
                        'id'            => $docId,
                        'company_id'    => (string)$companyId,
                        'branch_id'     => (string)$data['branch_id'],
                        'adjustment_no' => $adjNo,
                        'status'        => 'DRAFT',
                        'reason'        => $data['reason'] ?? null,
                        'notes'         => $data['notes'] ?? null,
                        'created_by'    => (string)$u->id,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);

                    $lineNo = 0;
                    foreach ($data['items'] as $it) {
                        $lineNo++;

                        $unit = $it['unit'] ?? null;
                        if (is_string($unit)) {
                            $unit = trim($unit);
                            if ($unit === '') $unit = null;
                        }

                        DB::table('stock_adjustment_items')->insert([
                            'id'                  => (string)Str::uuid(),
                            'stock_adjustment_id' => $docId,
                            'line_no'             => $lineNo,
                            'inventory_item_id'   => !empty($it['inventory_item_id']) ? (string)$it['inventory_item_id'] : null,
                            'item_name'           => trim((string)$it['item_name']),
                            'unit'                => $unit,
                            'direction'           => strtoupper((string)$it['direction']),
                            'qty'                 => $it['qty'],
                            'expiry_date'         => $it['expiry_date'] ?? null,
                            'unit_cost'           => $it['unit_cost'] ?? 0,
                            'currency'            => $it['currency'] ?? 'IDR',
                            'received_at'         => $it['received_at'] ?? null,
                            'preferred_lot_id'    => $it['preferred_lot_id'] ?? null,
                            'remarks'             => $it['remarks'] ?? null,
                            'created_at'          => now(),
                            'updated_at'          => now(),
                        ]);
                    }

                    Audit::log($request, 'create', 'stock_adjustments', $docId, [
                        'adjustment_no'   => $adjNo,
                        'branch_id'       => (string)$data['branch_id'],
                        'line_count'      => $lineNo,
                        'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
                    ]);

                    return response()->json([
                        'id'            => $docId,
                        'adjustment_no' => $adjNo,
                        'status'        => 'DRAFT',
                    ], 200);
                });
            } catch (QueryException $e) {
                $msg = $e->getMessage();
                $isDup = str_contains($msg, 'stock_adjustments_company_no_unique')
                    || str_contains($msg, 'duplicate key value violates unique constraint');

                if (!$isDup || $attempts >= 5) {
                    throw $e;
                }
            }
        }
    }

    /**
     * POST /api/dc/stock-adjustments/{id}/submit
     */
    public function submit(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);
        $companyId = AuthUser::requireCompanyContext($request);

        return DB::transaction(function () use ($request, $id, $companyId, $u) {

            $doc = DB::table('stock_adjustments')->where('id', $id)->lockForUpdate()->first();
            if (!$doc) {
                throw new HttpException(404, 'Not found');
            }
            if ((string)$doc->company_id !== (string)$companyId) {
                throw new HttpException(403, 'Forbidden');
            }

            if ((string)$doc->status === 'SUBMITTED') {
                return response()->json(['id' => (string)$doc->id, 'status' => 'SUBMITTED']);
            }
            if ((string)$doc->status !== 'DRAFT') {
                throw new HttpException(409, 'Only DRAFT can be submitted');
            }

            DB::table('stock_adjustments')->where('id', $id)->update([
                'status'       => 'SUBMITTED',
                'submitted_at' => now(),
                'submitted_by' => (string)$u->id,
                'updated_at'   => now(),
            ]);

            Audit::log($request, 'submit', 'stock_adjustments', (string)$doc->id, [
                'adjustment_no'   => (string)$doc->adjustment_no,
                'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
            ]);

            return response()->json(['id' => (string)$doc->id, 'status' => 'SUBMITTED']);
        });
    }

    /**
     * POST /api/dc/stock-adjustments/{id}/approve
     */
    public function approve(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);
        $companyId = AuthUser::requireCompanyContext($request);

        return DB::transaction(function () use ($request, $id, $companyId, $u) {

            $doc = DB::table('stock_adjustments')->where('id', $id)->lockForUpdate()->first();
            if (!$doc) {
                throw new HttpException(404, 'Not found');
            }
            if ((string)$doc->company_id !== (string)$companyId) {
                throw new HttpException(403, 'Forbidden');
            }

            if ((string)$doc->status === 'APPROVED') {
                return response()->json(['id' => (string)$doc->id, 'status' => 'APPROVED']);
            }
            if ((string)$doc->status !== 'SUBMITTED') {
                throw new HttpException(409, 'Only SUBMITTED can be approved');
            }

            DB::table('stock_adjustments')->where('id', $id)->update([
                'status'      => 'APPROVED',
                'approved_at' => now(),
                'approved_by' => (string)$u->id,
                'updated_at'  => now(),
            ]);

            Audit::log($request, 'approve', 'stock_adjustments', (string)$doc->id, [
                'adjustment_no'   => (string)$doc->adjustment_no,
                'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
            ]);

            return response()->json(['id' => (string)$doc->id, 'status' => 'APPROVED']);
        });
    }

    /**
     * POST /api/dc/stock-adjustments/{id}/post
     *
     * Note:
     * - This method ONLY gates status/tenant and delegates ledger work to service.
     * - Do NOT wrap a second transaction around $svc->post(); the service itself is transactional.
     *   Wrapping is safe in PG as savepoints, but adds complexity.
     */
    public function post(Request $request, string $id, StockAdjustmentPostingService $svc)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);
        $companyId = AuthUser::requireCompanyContext($request);

        // Lock + validate doc state in a small TX, then call service (which locks again defensively).
        return DB::transaction(function () use ($request, $id, $companyId, $svc) {

            $docRow = DB::table('stock_adjustments')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$docRow) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
            }
            if ((string)$docRow->company_id !== (string)$companyId) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
            }

            if ((string)$docRow->status === 'POSTED') {
                return response()->json([
                    'data' => [
                        'id'     => (string)$docRow->id,
                        'status' => 'POSTED',
                    ],
                ]);
            }

            if ((string)$docRow->status !== 'APPROVED') {
                return response()->json([
                    'error' => ['code' => 'invalid_status', 'message' => 'Only APPROVED documents can be posted'],
                ], 409);
            }

            $doc = StockAdjustment::query()->where('id', $id)->first();
            if (!$doc) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
            }

            // Service enforces branch access + locks + recompute on_hand from lots.
            $result = $svc->post($doc, $request);

            return response()->json(['data' => $result]);
        });
    }

    /**
     * POST /api/dc/stock-adjustments/{id}/reject
     * Rule: only SUBMITTED can be rejected -> REJECTED
     */
    public function reject(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);
        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:2000',
        ]);

        return DB::transaction(function () use ($request, $id, $companyId, $u, $data) {

            $doc = DB::table('stock_adjustments')->where('id', $id)->lockForUpdate()->first();
            if (!$doc) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
            }
            if ((string)$doc->company_id !== (string)$companyId) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
            }

            if ((string)$doc->status === 'REJECTED') {
                return response()->json(['id' => (string)$doc->id, 'status' => 'REJECTED']);
            }

            if ((string)$doc->status !== 'SUBMITTED') {
                return response()->json([
                    'error' => ['code' => 'invalid_status', 'message' => 'Only SUBMITTED documents can be rejected'],
                ], 409);
            }

            DB::table('stock_adjustments')->where('id', $id)->update([
                'status'        => 'REJECTED',
                'rejected_at'   => now(),
                'rejected_by'   => (string)$u->id,
                'reject_reason' => (string)$data['reason'],
                'updated_at'    => now(),
            ]);

            Audit::log($request, 'reject', 'stock_adjustments', (string)$doc->id, [
                'adjustment_no'   => (string)$doc->adjustment_no,
                'reason'          => (string)$data['reason'],
                'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
            ]);

            return response()->json(['id' => (string)$doc->id, 'status' => 'REJECTED']);
        });
    }

    /**
     * POST /api/dc/stock-adjustments/{id}/void
     *
     * Production-grade deterministic reversal:
     * - Uses original inventory_movements (source_type=STOCK_ADJUSTMENT, source_id={id})
     * - Requires inventory_lot_id on each original movement
     * - Reverses by applying revQty = -origQty back to the SAME lot (no deletes, no re-FIFO)
     * - Inserts reversal movement rows:
     *     source_type = STOCK_ADJUSTMENT_VOID
     *     ref_type/ref_id link to the original movement row
     * - Recomputes inventory_items.on_hand from lots (truth) for impacted items
     *
     * Canonical rules assumed:
     * - inventory_movements.qty is signed numeric(12,3): IN positive, OUT negative
     * - inventory_movements.type is IN/OUT and must match sign (IN => qty>=0, OUT => qty<=0)
     */
    public function void(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);
        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:2000',
        ]);

        return DB::transaction(function () use ($request, $id, $companyId, $u, $data) {

            $doc = DB::table('stock_adjustments')->where('id', $id)->lockForUpdate()->first();
            if (!$doc) {
                return response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
            }
            if ((string)$doc->company_id !== (string)$companyId) {
                return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
            }

            if ((string)$doc->status === 'VOIDED') {
                return response()->json(['id' => (string)$doc->id, 'status' => 'VOIDED']);
            }

            if ((string)$doc->status !== 'POSTED') {
                return response()->json([
                    'error' => ['code' => 'invalid_status', 'message' => 'Only POSTED documents can be voided'],
                ], 409);
            }

            // Original movements for this SA posting
            $origMoves = DB::table('inventory_movements')
                ->where('source_type', 'STOCK_ADJUSTMENT')
                ->where('source_id', (string)$id)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            if ($origMoves->isEmpty()) {
                return response()->json([
                    'error' => ['code' => 'cannot_void', 'message' => 'Cannot void: no ledger movements found for this document'],
                ], 409);
            }

            // Track impacted SKUs so we recompute on_hand once per SKU
            $impacted = []; // key: branchId|invItemId => ['branch_id'=>..., 'inventory_item_id'=>...]
            $reversalIds = [];

            foreach ($origMoves as $m) {
                if (empty($m->inventory_lot_id)) {
                    return response()->json([
                        'error' => ['code' => 'cannot_void', 'message' => 'Cannot void: movement missing inventory_lot_id (deterministic reversal required)'],
                    ], 409);
                }

                $branchId = (string)$m->branch_id;
                $invItemId = (string)$m->inventory_item_id;
                $lotId = (string)$m->inventory_lot_id;

                // Signed qty (numeric string); reverse is -qty
                $origQty = $this->dec3((string)$m->qty);
                if (bccomp($origQty, '0.000', 3) === 0) {
                    // No-op movement; skip safely
                    continue;
                }
                $revQty = bcmul($origQty, '-1', 3);

                // Lock lot row and apply deterministic reversal to same lot
                $lot = DB::table('inventory_lots')
                    ->where('id', $lotId)
                    ->lockForUpdate()
                    ->first(['id', 'branch_id', 'inventory_item_id', 'remaining_qty']);

                if (!$lot) {
                    return response()->json([
                        'error' => ['code' => 'cannot_void', 'message' => 'Cannot void: lot not found'],
                    ], 409);
                }

                // Defense-in-depth: lot must match movement context
                if ((string)$lot->branch_id !== $branchId || (string)$lot->inventory_item_id !== $invItemId) {
                    return response()->json([
                        'error' => ['code' => 'cannot_void', 'message' => 'Cannot void: lot context mismatch'],
                    ], 409);
                }

                // If revQty is negative, we are reducing remaining_qty. Ensure enough remaining.
                $currentRemaining = $this->dec3((string)$lot->remaining_qty);
                if (bccomp($revQty, '0.000', 3) < 0) {
                    $need = bcmul($revQty, '-1', 3); // positive amount to subtract
                    if (bccomp($currentRemaining, $need, 3) < 0) {
                        return response()->json([
                            'error' => [
                                'code' => 'cannot_void',
                                'message' => 'Cannot void: insufficient remaining_qty to reverse movement',
                                'details' => [
                                    'lot_id' => $lotId,
                                    'remaining_qty' => $currentRemaining,
                                    'needed' => $need,
                                    'movement_id' => (string)$m->id,
                                ],
                            ],
                        ], 409);
                    }
                }

                // Apply: remaining_qty = remaining_qty + revQty (revQty may be + or -)
                DB::update(
                    "update inventory_lots set remaining_qty = remaining_qty + ?, updated_at = ? where id = ?",
                    [$revQty, now(), $lotId]
                );

                // Determine type that matches signed qty
                $revType = (bccomp($revQty, '0.000', 3) >= 0) ? 'IN' : 'OUT';

                $revId = (string)Str::uuid();
                DB::table('inventory_movements')->insert([
                    'id'                => $revId,
                    'branch_id'         => $branchId,
                    'inventory_item_id' => $invItemId,
                    'type'              => $revType,
                    'qty'               => $revQty,
                    'inventory_lot_id'  => $lotId,

                    'source_type'       => 'STOCK_ADJUSTMENT_VOID',
                    'source_id'         => (string)$id,
                    'ref_type'          => 'inventory_movements',
                    'ref_id'            => (string)$m->id,

                    'actor_id'          => (string)$u->id,
                    'note'              => 'VOID: ' . (string)$data['reason'],

                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                $reversalIds[] = $revId;

                $k = $branchId . '|' . $invItemId;
                $impacted[$k] = ['branch_id' => $branchId, 'inventory_item_id' => $invItemId];
            }

            // Recompute on_hand from lots for impacted items (canonical projection)
            $recomputed = [];
            foreach ($impacted as $row) {
                $branchId = $row['branch_id'];
                $invItemId = $row['inventory_item_id'];

                $sumRow = DB::selectOne(
                    "select coalesce(sum(remaining_qty), 0) as lots_sum
                     from inventory_lots
                     where branch_id = ? and inventory_item_id = ?",
                    [$branchId, $invItemId]
                );

                $onHand = $this->dec3((string)$sumRow->lots_sum);

                DB::update(
                    "update inventory_items set on_hand = ?, updated_at = ? where id = ? and branch_id = ?",
                    [$onHand, now(), $invItemId, $branchId]
                );

                $recomputed[] = [
                    'branch_id' => $branchId,
                    'inventory_item_id' => $invItemId,
                    'on_hand' => $onHand,
                ];
            }

            DB::table('stock_adjustments')->where('id', (string)$id)->update([
                'status'      => 'VOIDED',
                'voided_at'   => now(),
                'voided_by'   => (string)$u->id,
                'void_reason' => (string)$data['reason'],
                'updated_at'  => now(),
            ]);

            Audit::log($request, 'void', 'stock_adjustments', (string)$doc->id, [
                'adjustment_no'   => (string)$doc->adjustment_no,
                'reason'          => (string)$data['reason'],
                'original_moves'  => $origMoves->count(),
                'reversal_ids'    => $reversalIds,
                'recomputed'      => $recomputed,
                'idempotency_key' => (string)$request->header('Idempotency-Key', ''),
            ]);

            return response()->json([
                'id'     => (string)$doc->id,
                'status' => 'VOIDED',
            ]);
        });
    }

    /**
     * Normalize decimal to scale(3) string (safe for signed numeric strings).
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
