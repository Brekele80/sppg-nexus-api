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

class StockAdjustmentController extends Controller
{
    /**
     * GET /api/dc/stock-adjustments
     */
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $allowed = AuthUser::allowedBranchIds($request);
        if (empty($allowed)) {
            return response()->json([
                'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company']
            ], 403);
        }

        $data = $request->validate([
            'branch_id' => 'nullable|uuid',
            'status'    => 'nullable|string|in:DRAFT,SUBMITTED,APPROVED,POSTED',
            'q'         => 'nullable|string|max:200',
            'limit'     => 'nullable|integer|min:1|max:200',
        ]);

        $limit = (int)($data['limit'] ?? 50);
        $q     = isset($data['q']) ? trim((string)$data['q']) : '';

        $query = DB::table('stock_adjustments')
            ->where('company_id', $companyId)
            ->whereIn('branch_id', $allowed)
            ->orderByDesc('created_at');

        if (!empty($data['branch_id'])) {
            if (!in_array((string)$data['branch_id'], $allowed, true)) {
                return response()->json([
                    'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
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
            'company_id' => $companyId,
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
     */
    public function show(Request $request, string $id)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);

        $companyId = AuthUser::requireCompanyContext($request);

        $allowed = AuthUser::allowedBranchIds($request);
        if (empty($allowed)) {
            return response()->json([
                'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company']
            ], 403);
        }

        $doc = DB::table('stock_adjustments')->where('id', $id)->first();

        if (!$doc) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Stock adjustment not found']
            ], 404);
        }
        if ((string)$doc->company_id !== (string)$companyId) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Forbidden']
            ], 403);
        }
        if (!in_array((string)$doc->branch_id, $allowed, true)) {
            return response()->json([
                'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
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
     * Multi-tenant / commercial-grade enforcement:
     * - OUT lines MUST specify items.*.inventory_item_id to prevent SKU drift (Beras NULL vs Beras kg).
     * - If inventory_item_id is provided, it MUST belong to the same branch and match item_name + unit (if provided).
     * - preferred_lot_id (if provided) MUST belong to same branch + inventory_item_id (only safe with explicit SKU).
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
                'error' => ['code' => 'invalid_branch', 'message' => 'Branch not found in company']
            ], 422);
        }

        // Access check (branch is allowed for user)
        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array((string)$data['branch_id'], $allowed, true)) {
            return response()->json([
                'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
            ], 403);
        }

        // Commercial-grade item validation BEFORE writing anything.
        // - OUT: inventory_item_id required and must match item_name+unit
        // - IN: inventory_item_id optional; if provided must match item_name+unit
        // - preferred_lot_id: only allowed when inventory_item_id is provided; must belong to branch+inventory_item_id
        foreach ($data['items'] as $idx => $it) {
            $line = $idx + 1;

            $direction = strtoupper(trim((string)($it['direction'] ?? '')));
            $itemName = trim((string)($it['item_name'] ?? ''));
            $unit = $it['unit'] ?? null;
            if (is_string($unit)) {
                $unit = trim($unit);
                if ($unit === '') $unit = null;
            }

            $invId = !empty($it['inventory_item_id']) ? (string)$it['inventory_item_id'] : null;

            if ($direction === 'OUT' && $invId === null) {
                return response()->json([
                    'error' => [
                        'code' => 'validation',
                        'message' => "items.$line.inventory_item_id is required for OUT lines to prevent SKU mismatch"
                    ]
                ], 422);
            }

            if (!empty($it['preferred_lot_id']) && $invId === null) {
                return response()->json([
                    'error' => [
                        'code' => 'validation',
                        'message' => "items.$line.preferred_lot_id requires items.$line.inventory_item_id"
                    ]
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
                            'code' => 'validation',
                            'message' => "items.$line.inventory_item_id not found in this branch"
                        ]
                    ], 422);
                }

                // item_name must match exactly after trimming
                if ((string)$inv->item_name !== $itemName) {
                    return response()->json([
                        'error' => [
                            'code' => 'validation',
                            'message' => "items.$line.item_name mismatch for inventory_item_id (expected: {$inv->item_name})"
                        ]
                    ], 422);
                }

                // unit must match (NULL-safe)
                $invUnit = $inv->unit !== null ? (string)$inv->unit : null;
                if (($invUnit ?? null) !== ($unit ?? null)) {
                    $eu = $invUnit ?? 'NULL';
                    $uu = $unit ?? 'NULL';
                    return response()->json([
                        'error' => [
                            'code' => 'validation',
                            'message' => "items.$line.unit mismatch for inventory_item_id (expected: {$eu}, got: {$uu})"
                        ]
                    ], 422);
                }

                // preferred lot must belong to same branch + sku
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
                                'code' => 'validation',
                                'message' => "items.$line.preferred_lot_id not found for this branch + inventory_item_id"
                            ]
                        ], 422);
                    }
                }
            }
        }

        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return DB::transaction(function () use ($request, $data, $companyId, $u) {

                    // Defense-in-depth inside TX as well (multi-tenant safety)
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

                            // Persist explicit SKU pin (critical for OUT and recommended for IN)
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
                // Only retry unique collision on adjustment_no (depending on your unique constraint name)
                $msg = $e->getMessage();
                $isDup = str_contains($msg, 'stock_adjustments_company_no_unique')
                    || str_contains($msg, 'duplicate key value violates unique constraint');

                if (!$isDup || $attempts >= 5) {
                    throw $e;
                }
                // retry loop
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
            if (!$doc) abort(404, 'Not found');
            if ((string)$doc->company_id !== (string)$companyId) abort(403, 'Forbidden');

            if ((string)$doc->status === 'SUBMITTED') {
                return response()->json(['id' => (string)$doc->id, 'status' => 'SUBMITTED']);
            }
            if ((string)$doc->status !== 'DRAFT') abort(409, 'Only DRAFT can be submitted');

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
            if (!$doc) abort(404, 'Not found');
            if ((string)$doc->company_id !== (string)$companyId) abort(403, 'Forbidden');

            if ((string)$doc->status === 'APPROVED') {
                return response()->json(['id' => (string)$doc->id, 'status' => 'APPROVED']);
            }
            if ((string)$doc->status !== 'SUBMITTED') abort(409, 'Only SUBMITTED can be approved');

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
     */
    public function post(Request $request, string $id, StockAdjustmentPostingService $svc)
    {
        $u = AuthUser::get($request);
        AuthUser::requireRole($u, ['DC_ADMIN']);
        $companyId = AuthUser::requireCompanyContext($request);

        $doc = StockAdjustment::query()->where('id', $id)->first();
        if (!$doc) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        }
        if ((string)$doc->company_id !== (string)$companyId) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
        }

        return response()->json(['data' => $svc->post($doc, $request)]);
    }
}
