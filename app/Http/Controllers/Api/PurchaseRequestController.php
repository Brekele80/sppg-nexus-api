<?php

namespace App\Http\Controllers\Api;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Support\Audit;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseRequestController extends BaseApiController
{
    public function store(Request $request)
    {
        $u = $this->authUser($request);
        $this->requireRole($u, ['CHEF']);

        // Strong tenant boundary
        $companyId = AuthUser::requireCompanyContext($request);

        // Mutation endpoints must have branch scope (header or profile.branch_id)
        // Even though we accept branch_id in payload, this ensures consistent request context.
        AuthUser::requireBranchAccess($request);

        $data = $request->validate([
            'branch_id' => 'required|uuid',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.unit' => 'required|string|max:50',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.remarks' => 'nullable|string',
        ]);

        // Defense-in-depth: branch belongs to company
        $branchOk = DB::table('branches')
            ->where('id', (string) $data['branch_id'])
            ->where('company_id', (string) $companyId)
            ->exists();

        if (!$branchOk) {
            throw ValidationException::withMessages([
                'branch_id' => ['Branch not found in company'],
            ]);
        }

        // Entitlement: user must have access to branch
        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array((string) $data['branch_id'], $allowed, true)) {
            throw new HttpException(403, 'No access to this branch');
        }

        return DB::transaction(function () use ($request, $u, $data, $companyId) {
            $prId = (string) Str::uuid();

            PurchaseRequest::create([
                'id' => $prId,
                'branch_id' => (string) $data['branch_id'],
                'requested_by' => (string) $u->id,
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
                'branch_id' => (string) $data['branch_id'],
                'notes' => $data['notes'] ?? null,
                'items' => array_map(fn ($it) => [
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
        AuthUser::requireCompanyContext($request);

        $allowedBranchIds = AuthUser::allowedBranchIds($request);
        if (empty($allowedBranchIds)) {
            throw new HttpException(403, 'No branch access for this company');
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

        // Mutation endpoints must have branch scope
        AuthUser::requireBranchAccess($request);

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        return DB::transaction(function () use ($request, $id, $u, $companyId, $idempotencyKey) {

            $prRow = DB::table('purchase_requests')
                ->where('id', (string) $id)
                ->lockForUpdate()
                ->first();

            if (!$prRow) {
                throw new HttpException(404, 'Purchase request not found');
            }

            // Tenant boundary via branches.company_id
            $branchOk = DB::table('branches')
                ->where('id', (string) $prRow->branch_id)
                ->where('company_id', (string) $companyId)
                ->exists();

            if (!$branchOk) {
                throw new HttpException(404, 'Not found');
            }

            // Branch access entitlement
            $allowed = AuthUser::allowedBranchIds($request);
            if (!in_array((string) $prRow->branch_id, $allowed, true)) {
                throw new HttpException(403, 'Forbidden (no branch access)');
            }

            $status = (string) ($prRow->status ?? 'DRAFT');

            if ($status === 'SUBMITTED') {
                return response()->json($this->loadPrGuarded($request, $companyId, (string) $id), 200);
            }

            if ($status !== 'DRAFT') {
                throw new HttpException(409, "Only DRAFT PR can be submitted (current: {$status})");
            }

            $now = now();

            $update = [
                'status' => 'SUBMITTED',
                'submitted_at' => $now,
                'updated_at' => $now,
                'submitted_by' => (string) $u->id, // assumes column exists
            ];

            DB::table('purchase_requests')
                ->where('id', (string) $id)
                ->update($update);

            Audit::log($request, 'submit', 'purchase_requests', (string) $id, [
                'from' => 'DRAFT',
                'to' => 'SUBMITTED',
                'branch_id' => (string) $prRow->branch_id,
                'submitted_by' => (string) $u->id,
                'submitted_at' => (string) $now,
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json($this->loadPrGuarded($request, $companyId, (string) $id), 200);
        });
    }

    private function loadPrGuarded(Request $request, string $companyId, string $id): PurchaseRequest
    {
        $pr = PurchaseRequest::with(['items'])->findOrFail($id);

        $branchOk = DB::table('branches')
            ->where('id', (string) $pr->branch_id)
            ->where('company_id', (string) $companyId)
            ->exists();

        if (!$branchOk) {
            throw new HttpException(404, 'Not found');
        }

        $allowed = AuthUser::allowedBranchIds($request);
        if (!in_array((string) $pr->branch_id, $allowed, true)) {
            throw new HttpException(403, 'Forbidden (no branch access)');
        }

        return $pr;
    }
}
