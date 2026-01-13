<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RequireCompanyContext
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->attributes->get('auth_user');

        if (!$u || empty($u->company_id)) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Missing company context',
                ],
            ], 401);
        }

        if (empty($u->company_id)) {
            return response()->json([
                'error' => [
                    'code' => 'company_missing',
                    'message' => 'User has no company_id',
                ],
            ], 401);
        }

        // Enforce explicit company scope
        $companyId = $request->header('X-Company-Id');

        if (empty($companyId)) {
            return response()->json([
                'error' => [
                    'code' => 'company_header_required',
                    'message' => 'Missing X-Company-Id header',
                ],
            ], 422);
        }

        // Must match the user company_id (strong tenant isolation)
        if ($companyId !== $u->company_id) {
            return response()->json([
                'error' => [
                    'code' => 'company_forbidden',
                    'message' => 'Company mismatch',
                    'details' => [
                        'requested_company_id' => $companyId,
                        'user_company_id' => $u->company_id,
                    ],
                ],
            ], 403);
        }

        // Optional: ensure company exists (defense-in-depth)
        $exists = DB::table('companies')->where('id', $companyId)->exists();
        if (!$exists) {
            return response()->json([
                'error' => [
                    'code' => 'company_not_found',
                    'message' => 'Company not found',
                ],
            ], 404);
        }

        // Save resolved company in request context for controllers/services
        $request->attributes->set('company_id', $companyId);

        return $next($request);
    }
}
