<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Support\AuthUser;
use Illuminate\Http\Request;

class BaseApiController extends Controller
{
    protected function authUser(Request $request): Profile
    {
        /** @var Profile|null $u */
        $u = $request->attributes->get('auth_user');
        if (!$u) abort(401, 'Unauthenticated');
        return $u;
    }

    /**
     * ANY-OF role check (matches your middleware usage: requireRole:CHEF,ACCOUNTING,...)
     */
    protected function requireRole(Profile $u, array $roles): void
    {
        // Delegate to canonical role resolution (JWT-injected / model helper)
        AuthUser::requireRole($u, $roles);
    }
}
