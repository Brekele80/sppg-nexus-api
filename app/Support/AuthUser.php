<?php

namespace App\Support;

use Illuminate\Http\Request;
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

    public static function requireRole(Profile $u, array $roles): void
    {
        foreach ($roles as $r) {
            if ($u->hasRole($r)) return;
        }
        abort(403, 'Forbidden (role)');
    }

    public static function requireBranch(Profile $u): void
    {
        if (!$u->branch_id) abort(403, 'Branch not assigned');
    }
}
