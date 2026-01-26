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

    public static function companyId(Request $request): string
    {
        $u = self::get($request);
        $cid = $request->attributes->get('company_id') ?? ($u->company_id ?? null);
        if (!$cid) abort(401, 'Missing company context');
        return (string) $cid;
    }

    public static function requireCompanyContext(Request $request): string
    {
        $u = self::get($request);
        $cid = self::companyId($request);

        if (empty($u->company_id)) abort(403, 'User has no company_id');
        if ((string) $u->company_id !== (string) $cid) abort(403, 'Company mismatch');

        return (string) $cid;
    }

    public static function roleCodes(Profile $u): array
    {
        $roles = [];

        // Prefer Profile helper (DB roles OR injected roles)
        if (method_exists($u, 'roleCodes')) {
            $rc = $u->roleCodes();
            if (is_array($rc)) $roles = $rc;
        } elseif (isset($u->roles) && is_array($u->roles)) {
            $roles = $u->roles;
        }

        $roles = array_map(fn ($r) => strtoupper(trim((string) $r)), $roles);
        $roles = array_values(array_unique(array_filter($roles, fn ($r) => $r !== '')));

        return $roles;
    }

    public static function isCompanyWide(Profile $u): bool
    {
        $roles = self::roleCodes($u);
        return in_array('ACCOUNTING', $roles, true) || in_array('KA_SPPG', $roles, true);
    }

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

            if ($exists) $branchIds = [(string) $u->branch_id];
        }

        // Final defense: only branches in company
        if (!empty($branchIds)) {
            $branchIds = DB::table('branches')
                ->where('company_id', $companyId)
                ->whereIn('id', $branchIds)
                ->pluck('id')
                ->all();
        }

        return $branchIds;
    }

    public static function requireBranchAccess(Request $request): string
    {
        $u = self::get($request);
        $companyId = self::companyId($request);

        $requestedBranchId = (string) $request->header('X-Branch-Id', '');
        $branchId = $requestedBranchId !== '' ? $requestedBranchId : ($u->branch_id ?? null);

        if (!$branchId) abort(403, 'Branch not assigned');

        $allowed = self::allowedBranchIds($request);
        if (empty($allowed) || !in_array((string) $branchId, $allowed, true)) {
            abort(403, 'Forbidden (no branch access)');
        }

        // defense-in-depth: ensure branch belongs to company
        $exists = DB::table('branches')
            ->where('id', (string) $branchId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) abort(403, 'Forbidden (branch not in company)');

        $request->attributes->set('branch_id', (string) $branchId);

        return (string) $branchId;
    }

    public static function requireRole(Profile $u, array $roles): void
    {
        $userRoles = self::roleCodes($u);

        foreach ($roles as $r) {
            $r = strtoupper(trim((string) $r));
            if ($r !== '' && in_array($r, $userRoles, true)) return;
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
