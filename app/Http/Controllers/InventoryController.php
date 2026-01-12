<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AuthUser;

class InventoryController extends Controller
{
    /**
     * Resolve which branches the current user is allowed to see,
     * within the current company context.
     *
     * Rules (minimal but production-sane):
     * - If user has ACCOUNTING or KA_SPPG role => can see ALL branches in the company.
     * - Otherwise => can only see branches listed in profile_branch_access for this company.
     * - Always enforce branch.company_id = current company_id (defense-in-depth).
     */
    private function resolveAllowedBranchIds(Request $request): array
    {
        $u = AuthUser::get($request);
        if (!$u) {
            return [];
        }

        // RequireCompanyContext should set this, but we defensively fallback to user company_id
        $companyId = $request->attributes->get('company_id') ?? ($u->company_id ?? null);
        if (!$companyId) {
            return [];
        }

        // Normalize role codes
        $roleCodes = [];
        if (method_exists($u, 'roleCodes')) {
            $roleCodes = $u->roleCodes();
        } elseif (isset($u->roles) && is_array($u->roles)) {
            $roleCodes = $u->roles;
        } elseif (method_exists($u, 'roles')) {
            $roleCodes = $u->roles()->pluck('code')->all();
        }

        $isCompanyWide = in_array('ACCOUNTING', $roleCodes, true) || in_array('KA_SPPG', $roleCodes, true);

        if ($isCompanyWide) {
            // All branches in this company
            return DB::table('branches')
                ->where('company_id', $companyId)
                ->pluck('id')
                ->all();
        }

        // Branch-scoped: must exist in profile_branch_access (in this company)
        $branchIds = DB::table('profile_branch_access')
            ->where('company_id', $companyId)
            ->where('profile_id', $u->id)
            ->pluck('branch_id')
            ->all();

        // Fallback: if mapping table is empty for this user, allow their own branch only
        // BUT still ensure it's within this company.
        if (empty($branchIds) && !empty($u->branch_id)) {
            $exists = DB::table('branches')
                ->where('id', $u->branch_id)
                ->where('company_id', $companyId)
                ->exists();

            if ($exists) {
                $branchIds = [$u->branch_id];
            }
        }

        // Final defense: filter branchIds to only those within company
        if (!empty($branchIds)) {
            $branchIds = DB::table('branches')
                ->where('company_id', $companyId)
                ->whereIn('id', $branchIds)
                ->pluck('id')
                ->all();
        }

        return $branchIds;
    }

    /**
     * GET /api/inventory
     * Returns on-hand balances grouped by item_name across the user's allowed branches in the company.
     */
    public function index(Request $request)
    {
        $u = AuthUser::get($request);
        if (!$u) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated']], 401);
        }

        $companyId = $request->attributes->get('company_id') ?? ($u->company_id ?? null);
        if (!$companyId) {
            return response()->json(['error' => ['code' => 'company_missing', 'message' => 'Missing company context']], 401);
        }

        $allowedBranchIds = $this->resolveAllowedBranchIds($request);

        if (empty($allowedBranchIds)) {
            return response()->json([
                'error' => [
                    'code' => 'no_branch_access',
                    'message' => 'No branch access for this company',
                ]
            ], 403);
        }

        // Tenant-safe: join branches to enforce company filter even if branch_id is spoofed somewhere upstream.
        $rows = DB::table('inventory_ledgers as l')
            ->join('branches as b', 'b.id', '=', 'l.branch_id')
            ->where('b.company_id', $companyId)
            ->whereIn('l.branch_id', $allowedBranchIds)
            ->selectRaw("
                l.item_name,
                SUM(CASE WHEN l.direction = 'IN'  THEN l.qty ELSE 0 END) -
                SUM(CASE WHEN l.direction = 'OUT' THEN l.qty ELSE 0 END) AS on_hand
            ")
            ->groupBy('l.item_name')
            ->orderBy('l.item_name')
            ->get();

        return response()->json($rows, 200);
    }

    /**
     * GET /api/inventory/movements
     * Returns last 100 ledger entries across allowed branches in the company.
     */
    public function movements(Request $request)
    {
        $u = AuthUser::get($request);
        if (!$u) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated']], 401);
        }

        $companyId = $request->attributes->get('company_id') ?? ($u->company_id ?? null);
        if (!$companyId) {
            return response()->json(['error' => ['code' => 'company_missing', 'message' => 'Missing company context']], 401);
        }

        $allowedBranchIds = $this->resolveAllowedBranchIds($request);

        if (empty($allowedBranchIds)) {
            return response()->json([
                'error' => [
                    'code' => 'no_branch_access',
                    'message' => 'No branch access for this company',
                ]
            ], 403);
        }

        $rows = DB::table('inventory_ledgers as l')
            ->join('branches as b', 'b.id', '=', 'l.branch_id')
            ->where('b.company_id', $companyId)
            ->whereIn('l.branch_id', $allowedBranchIds)
            ->orderByDesc('l.created_at')
            ->limit(100)
            ->select('l.*')
            ->get();

        return response()->json($rows, 200);
    }
}
