<?php

namespace App\Http\Controllers\Api;

use App\Domain\Rab\RabApprovalService;
use App\Models\RabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;
use App\Support\Audit;

class RabDecisionController extends BaseApiController
{
    public function store(Request $request, string $id, RabApprovalService $service)
    {
        $u = $this->authUser($request);

        // ANY OF: KA_SPPG or ACCOUNTING (parallel approval; min=1)
        $this->requireRole($u, ['KA_SPPG', 'ACCOUNTING']);

        // Strong tenant boundary
        $companyId = AuthUser::requireCompanyContext($request);

        // Mutation endpoints must have branch scope
        AuthUser::requireBranchAccess($request);

        $data = $request->validate([
            'decision' => 'required|string|in:APPROVE,REJECT,approve,reject',
            'reason' => 'nullable|string|max:2000',
        ]);

        $rab = RabVersion::findOrFail($id);

        $ok = DB::table('purchase_requests as pr')
            ->join('branches as b', 'b.id', '=', 'pr.branch_id')
            ->where('pr.id', $rab->purchase_request_id)
            ->where('b.company_id', (string) $companyId)
            ->exists();

        if (!$ok) abort(404, 'Not found');

        $before = (string) $rab->status;

        try {
            $updated = $service->decide($rab, $u, $data['decision'], $data['reason'] ?? null);
            $updated->refresh();

            if ($before !== (string) $updated->status) {
                Audit::log($request, 'decision', 'rab_versions', $updated->id, [
                    'from' => $before,
                    'to' => (string) $updated->status,
                    'decision' => strtoupper((string) $data['decision']),
                    'reason' => $data['reason'] ?? null,
                    'idempotency_key' => (string) $request->header('Idempotency-Key', ''),
                ]);
            }

            return response()->json($updated->load('lineItems'));
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'approval_decisions')) {
                return response()->json(['message' => 'You have already submitted a decision for this RAB'], 409);
            }
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function index(Request $request, string $id)
    {
        $this->authUser($request);

        // Strong tenant boundary
        $companyId = AuthUser::requireCompanyContext($request);

        $rab = RabVersion::findOrFail($id);

        $ok = DB::table('purchase_requests as pr')
            ->join('branches as b', 'b.id', '=', 'pr.branch_id')
            ->where('pr.id', $rab->purchase_request_id)
            ->where('b.company_id', (string) $companyId)
            ->exists();

        if (!$ok) abort(404, 'Not found');

        $decisions = DB::table('approval_decisions')
            ->where('entity_type', 'RAB_VERSION')
            ->where('entity_id', $rab->id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'rab_id' => $rab->id,
            'status' => $rab->status,
            'decisions' => $decisions,
        ]);
    }
}
