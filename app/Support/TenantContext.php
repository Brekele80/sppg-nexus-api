<?php

namespace App\Support;

use Illuminate\Http\Request;

class TenantContext
{
    public static function user(Request $request)
    {
        return $request->attributes->get('auth_user');
    }

    public static function companyId(Request $request): string
    {
        $u = self::user($request);
        if (!$u || empty($u->company_id)) {
            abort(401, 'Unauthorized (missing company)');
        }
        return $u->company_id;
    }

    public static function requireCompanyMatch(Request $request, string $companyId): void
    {
        if ($companyId !== self::companyId($request)) {
            abort(403, 'Forbidden (cross-company)');
        }
    }

    public static function requireBranchAccess(Request $request, string $branchId): void
    {
        $u = self::user($request);
        if (!$u) abort(401, 'Unauthorized');

        // optional escape hatch: company admin can access all branches
        // If you want this, you must define a role code such as COMPANY_ADMIN
        if (method_exists($u, 'hasRole') && $u->hasRole('COMPANY_ADMIN')) {
            return;
        }

        $companyId = self::companyId($request);

        $hasAccess = \Illuminate\Support\Facades\DB::table('profile_branch_access')
            ->where('company_id', $companyId)
            ->where('profile_id', $u->id)
            ->where('branch_id', $branchId)
            ->exists();

        if (!$hasAccess) {
            abort(403, 'Forbidden (no branch access)');
        }
    }
}
