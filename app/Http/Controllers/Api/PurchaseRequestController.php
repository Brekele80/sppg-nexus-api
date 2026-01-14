<?php

namespace App\Http\Controllers\Api;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;
use App\Support\Audit;

class PurchaseRequestController extends BaseApiController
{
    public function store(Request $request)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['CHEF']);

        $companyId = AuthUser::requireCompanyContext($request);

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.unit' => 'required|string|max:50',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.remarks' => 'nullable|string',
        ]);

        $branchOk = DB::table('branches')
            ->where('id', $data['branch_id'])
            ->where('company_id', $companyId)
            ->exists();

        if (!$branchOk) {
            return response()->json([
                'error' => ['code' => 'branch_invalid', 'message' => 'Branch not found in company']
            ], 422);
        }

        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array($data['branch_id'], $allowed, true)) {
            return response()->json([
                'error' => ['code' => 'branch_forbidden', 'message' => 'No access to this branch']
            ], 403);
        }

        return DB::transaction(function () use ($request, $u, $data, $companyId) {
            $prId = (string) Str::uuid();

            PurchaseRequest::create([
                'id' => $prId,
                'branch_id' => $data['branch_id'],
                'requested_by' => $u->id,
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $it) {
                PurchaseRequestItem::create([
                    'id' => (string) Str::uuid(),
                    'purchase_request_id' => $prId,
                    'item_name' => $it['item_name'],
                    'unit' => $it['unit'],
                    'qty' => $it['qty'],
                    'remarks' => $it['remarks'] ?? null,
                ]);
            }

            Audit::log($request, 'create', 'purchase_requests', $prId, [
                'branch_id' => $data['branch_id'],
                'notes' => $data['notes'] ?? null,
                'items' => array_map(fn($it) => [
                    'item_name' => $it['item_name'],
                    'unit' => $it['unit'],
                    'qty' => (float) $it['qty'],
                    'remarks' => $it['remarks'] ?? null,
                ], $data['items']),
                'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
            ]);

            return response()->json($this->loadPrGuarded($request, $companyId, $prId), 201);
        });
    }

    public function index(Request $request)
    {
        $this->authUser($request);
        $companyId = AuthUser::requireCompanyContext($request);

        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) {
            return response()->json([
                'error' => ['code' => 'no_branch_access', 'message' => 'No branch access for this company']
            ], 403);
        }

        $q = PurchaseRequest::query()->whereIn('branch_id', $allowedBranchIds);

        if ($request->filled('status')) {
            $q->where('status', (string) $request->string('status'));
        }

        return response()->json($q->orderByDesc('created_at')->limit(200)->get(), 200);
    }

    public function show(Request $request, string $id)
    {
        $this->authUser($request);
        $companyId = AuthUser::requireCompanyContext($request);

        return response()->json($this->loadPrGuarded($request, $companyId, $id), 200);
    }

    public function submit(Request $request, string $id)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['CHEF']);

        $companyId = AuthUser::requireCompanyContext($request);

        $pr = $this->loadPrGuarded($request, $companyId, $id);

        if ($pr->status !== 'DRAFT') {
            return response()->json(['message' => 'Only DRAFT PR can be submitted'], 422);
        }

        $pr->status = 'SUBMITTED';
        $pr->submitted_at = now();
        $pr->save();

        Audit::log($request, 'submit', 'purchase_requests', $pr->id, [
            'from' => 'DRAFT',
            'to' => 'SUBMITTED',
            'branch_id' => $pr->branch_id,
            'submitted_at' => (string) $pr->submitted_at,
            'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
        ]);

        return response()->json($this->loadPrGuarded($request, $companyId, $id), 200);
    }

    private function loadPrGuarded(Request $request, string $companyId, string $id): PurchaseRequest
    {
        $pr = PurchaseRequest::with(['items'])->findOrFail($id);

        $branchOk = DB::table('branches')
            ->where('id', $pr->branch_id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$branchOk) abort(404, 'Not found');

        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array($pr->branch_id, $allowed, true)) {
            abort(403, 'Forbidden (no branch access)');
        }

        return $pr;
    }
}
