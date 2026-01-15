<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\StockAdjustmentPostingService;
use App\Models\StockAdjustment;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use App\Support\Audit;
use App\Support\AuthUser;
use App\Support\DocumentNo;

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

            'items.*.direction' => 'required|string|in:IN,OUT',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.unit'      => 'nullable|string|max:30',
            'items.*.qty'       => 'required|numeric|min:0.001',

            'items.*.expiry_date'      => 'nullable|date',
            'items.*.unit_cost'        => 'nullable|numeric|min:0',
            'items.*.currency'         => 'nullable|string|max:10',
            'items.*.received_at'      => 'nullable|date',
            'items.*.preferred_lot_id' => 'nullable|uuid',
            'items.*.remarks'          => 'nullable|string|max:2000',
        ]);

        $branchOk = DB::table('branches')
            ->where('id', $data['branch_id'])
            ->where('company_id', $companyId)
            ->exists();

        if (!$branchOk) {
            return response()->json([
                'error' => ['code' => 'invalid_branch', 'message' => 'Branch not found in company']
            ], 422);
        }

        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array((string)$data['branch_id'], $allowed, true)) {
            return response()->json([
                'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
            ], 403);
        }

        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                return DB::transaction(function () use ($request, $data, $companyId, $u) {

                    $docId = (string) Str::uuid();
                    $adjNo = DocumentNo::next($companyId, 'stock_adjustments', 'SA', 'adjustment_no');

                    DB::table('stock_adjustments')->insert([
                        'id'            => $docId,
                        'company_id'    => $companyId,
                        'branch_id'     => (string) $data['branch_id'],
                        'adjustment_no' => $adjNo,
                        'status'        => 'DRAFT',
                        'reason'        => $data['reason'] ?? null,
                        'notes'         => $data['notes'] ?? null,
                        'created_by'    => $u->id,
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
                            'id'                  => (string) Str::uuid(),
                            'stock_adjustment_id' => $docId,
                            'line_no'             => $lineNo,
                            'inventory_item_id'   => null,
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
                // Only retry unique collision on (company_id, adjustment_no)
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
                'submitted_by' => $u->id,
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
                'approved_by' => $u->id,
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
        if (!$doc) return response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
        if ((string)$doc->company_id !== (string)$companyId) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'Forbidden']], 403);
        }

        return response()->json($svc->post($doc, $request));
    }
}
