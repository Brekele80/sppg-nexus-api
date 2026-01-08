<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\Request;

class BaseApiController extends Controller
{
    protected function authUser(Request $request): Profile
    {
        /** @var Profile $u */
        $u = $request->attributes->get('auth_user');
        if (!$u) {
            abort(401, 'Unauthenticated');
        }
        return $u;
    }

    protected function requireRole(Profile $u, array $roles): void
    {
        foreach ($roles as $r) {
            if ($u->hasRole($r)) return;
        }
        abort(403, 'Forbidden');
    }
}
