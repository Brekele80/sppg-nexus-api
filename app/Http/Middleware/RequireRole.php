<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->attributes->get('auth_user');

        if (!$user) {
            return response()->json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Unauthenticated',
                ]
            ], 401);
        }

        // Support BOTH:
        // 1) roles as array attribute (what /api/me seems to return)
        // 2) roles as relation (recommended for DB-backed roles)
        $userRoles = [];

        if (isset($user->roles) && is_array($user->roles)) {
            $userRoles = $user->roles;
        } elseif (method_exists($user, 'roles')) {
            // If Role model uses 'code', adapt as needed
            $userRoles = $user->roles()->pluck('code')->all();
        } elseif (method_exists($user, 'userRoles')) {
            $userRoles = $user->userRoles()->pluck('role_code')->all();
        }

        foreach ($roles as $required) {
            if (in_array($required, $userRoles, true)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => [
                'code' => 'forbidden',
                'message' => 'Insufficient role',
                'details' => [
                    'required' => $roles,
                    'has' => $userRoles,
                ],
            ]
        ], 403);
    }
}
