<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessScope
{
    /**
     * Return branch IDs the current user can access.
     * Uses profile_branch_access (your mapping table).
     */
    public static function branchIdsForUser(object $u): array
    {
        if (empty($u->company_id) || empty($u->id)) return [];

        return DB::table('profile_branch_access')
            ->where('company_id', $u->company_id)
            ->where('profile_id', $u->id)
            ->pluck('branch_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Ensure branch is accessible by user.
     */
    public static function assertBranchAccess(object $u, string $branchId): void
    {
        $ok = DB::table('profile_branch_access')
            ->where('company_id', $u->company_id)
            ->where('profile_id', $u->id)
            ->where('branch_id', $branchId)
            ->exists();

        if (!$ok) {
            abort(403, 'Forbidden (no branch access)');
        }
    }

    /**
     * Return "active branch" for UI: prefer user's branch_id if accessible,
     * else first accessible branch.
     */
    public static function activeBranchId(object $u): ?string
    {
        $ids = self::branchIdsForUser($u);
        if (!$ids) return null;

        if (!empty($u->branch_id) && in_array($u->branch_id, $ids, true)) return $u->branch_id;

        return $ids[0];
    }
}
