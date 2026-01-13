<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Profile;

class AuthUser
{
    public static function get(Request $request): Profile
    {
        /** @var Profile|null $u */
        $u = $request->attributes->get('auth_user');
        if (!$u) abort(401, 'Unauthenticated');
        return $u;
    }

    /**
     * Company is resolved by RequireCompanyContext (X-Company-Id header),
     * stored in request attributes as 'company_id'. Fallback to user company_id.
     */
    public static function companyId(Request $request): string
    {
        $u = self::get($request);
        $cid = $request->attributes->get('company_id') ?? ($u->company_id ?? null);
        if (!$cid) abort(401, 'Missing company context');
        return (string) $cid;
    }

    /**
     * Strong tenant guard: request company MUST equal user company_id.
     * Use this when you want zero ambiguity in multi-tenant controllers.
     */
    public static function requireCompanyContext(Request $request): string
    {
        $u = self::get($request);
        $cid = self::companyId($request);

        if (empty($u->company_id)) abort(403, 'User has no company_id');
        if ((string)$u->company_id !== (string)$cid) abort(403, 'Company mismatch');

        return (string) $cid;
    }

    /**
     * Normalized role codes.
     */
    public static function roleCodes(Profile $u): array
    {
        if (method_exists($u, 'roleCodes')) {
            $rc = $u->roleCodes();
            return is_array($rc) ? $rc : [];
        }

        // fallback patterns if needed later
        if (isset($u->roles) && is_array($u->roles)) return $u->roles;

        return [];
    }

    public static function isCompanyWide(Profile $u): bool
    {
        $roles = self::roleCodes($u);
        return in_array('ACCOUNTING', $roles, true) || in_array('KA_SPPG', $roles, true);
    }

    /**
     * Returns all branch IDs the user can access for the given company.
     * - ACCOUNTING / KA_SPPG => all branches in company
     * - else => branches listed in profile_branch_access
     * - fallback => user's own branch (if in company)
     */
    public static function allowedBranchIds(Request $request): array
    {
        $u = self::get($request);
        $companyId = self::companyId($request);

        if (self::isCompanyWide($u)) {
            return DB::table('branches')
                ->where('company_id', $companyId)
                ->pluck('id')
                ->all();
        }

        $branchIds = DB::table('profile_branch_access')
            ->where('company_id', $companyId)
            ->where('profile_id', $u->id)
            ->pluck('branch_id')
            ->all();

        // Fallback to own branch if in company
        if (empty($branchIds) && !empty($u->branch_id)) {
            $exists = DB::table('branches')
                ->where('id', $u->branch_id)
                ->where('company_id', $companyId)
                ->exists();

            if ($exists) $branchIds = [$u->branch_id];
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
     * Resolves the branch scope for the request, using:
     * - X-Branch-Id header if present
     * - else fallback to user.profile.branch_id
     *
     * Enforces: requested branch must be within allowedBranchIds for company.
     */
    public static function requireBranchAccess(Request $request): string
    {
        $u = self::get($request);
        $companyId = self::companyId($request);

        $requestedBranchId = $request->header('X-Branch-Id');
        $branchId = $requestedBranchId ?: ($u->branch_id ?? null);

        if (!$branchId) abort(403, 'Branch not assigned');

        $allowed = self::allowedBranchIds($request);
        if (empty($allowed) || !in_array($branchId, $allowed, true)) {
            abort(403, 'Forbidden (no branch access)');
        }

        // defense-in-depth: ensure branch belongs to company
        $exists = DB::table('branches')
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) abort(403, 'Forbidden (branch not in company)');

        $request->attributes->set('branch_id', $branchId);

        return (string) $branchId;
    }

    public static function requireRole(Profile $u, array $roles): void
    {
        foreach ($roles as $r) {
            if ($u->hasRole($r)) return;
        }
        abort(403, 'Forbidden (role)');
    }

    public static function requireAnyRole(Request $request, array $roles): Profile
    {
        $u = self::get($request);
        self::requireRole($u, $roles);
        return $u;
    }
}
