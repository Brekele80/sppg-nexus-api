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

        // Normalize required roles:
        // Accept both:
        // - requireRole:CHEF,ACCOUNTING,DC_ADMIN  (single arg with commas)
        // - requireRole:CHEF requireRole:ACCOUNTING (multiple args)
        $requiredRoles = [];
        foreach ($roles as $r) {
            foreach (explode(',', (string)$r) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') $requiredRoles[] = $piece;
            }
        }
        $requiredRoles = array_values(array_unique($requiredRoles));

        // Normalize user roles
        $userRoles = [];

        if (isset($user->roles) && is_array($user->roles)) {
            $userRoles = $user->roles;
        } elseif (method_exists($user, 'roles')) {
            $userRoles = $user->roles()->pluck('code')->all();
        } elseif (method_exists($user, 'userRoles')) {
            $userRoles = $user->userRoles()->pluck('role_code')->all();
        }

        foreach ($requiredRoles as $required) {
            if (in_array($required, $userRoles, true)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => [
                'code' => 'forbidden',
                'message' => 'Insufficient role',
                'details' => [
                    'required' => $requiredRoles,
                    'has' => $userRoles,
                ],
            ]
        ], 403);
    }
}
