<?php

namespace App\Http\Middleware;

use App\Support\AuthUser;
use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->attributes->get('auth_user');

        if (!$user) {
            return response()->json([
                'error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated']
            ], 401);
        }

        // Normalize required roles: allow commas + multiple args
        $requiredRoles = [];
        foreach ($roles as $r) {
            foreach (explode(',', (string) $r) as $piece) {
                $piece = strtoupper(trim($piece));
                if ($piece !== '') $requiredRoles[] = $piece;
            }
        }
        $requiredRoles = array_values(array_unique($requiredRoles));

        // Single source of truth for user roles
        $userRoles = array_map(
            fn($x) => strtoupper(trim((string)$x)),
            AuthUser::roleCodes($user)
        );
        $userRoles = array_values(array_unique(array_filter($userRoles, fn($x) => $x !== '')));

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
