<?php

namespace App\Http\Controllers\Api;

use App\Domain\Rab\RabApprovalService;
use App\Models\RabVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RabDecisionController extends BaseApiController
{
    public function store(Request $request, string $id, RabApprovalService $service)
    {
        $u = $this->authUser($request);

        // Must be approver roles
        $this->requireRole($u, ['KA_SPPG', 'ACCOUNTING']);

        $data = $request->validate([
            'decision' => 'required|string|in:APPROVE,REJECT,approve,reject',
            'reason' => 'nullable|string|max:2000',
        ]);

        $rab = RabVersion::findOrFail($id);

        try {
            $updated = $service->decide($rab, $u, $data['decision'], $data['reason'] ?? null);
            return response()->json($updated->load('lineItems'));
        } catch (\Throwable $e) {
            // Handle unique constraint (double vote) nicely
            if (str_contains($e->getMessage(), 'approval_decisions')) {
                return response()->json(['message' => 'You have already submitted a decision for this RAB'], 409);
            }
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function index(Request $request, string $id)
    {
        $this->authUser($request);

        $rab = RabVersion::findOrFail($id);

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
