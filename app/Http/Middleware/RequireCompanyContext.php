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
                'error' => ['code' => 'unauthorized', 'message' => 'Missing auth user or company context'],
            ], 401);
        }

        $companyId = trim((string) $request->header('X-Company-Id'));
        if ($companyId === '') {
            return response()->json([
                'error' => ['code' => 'company_header_required', 'message' => 'Missing X-Company-Id header'],
            ], 422);
        }

        $userCompanyId = trim((string) $u->company_id);

        // Strict tenant isolation
        if (strcasecmp($companyId, $userCompanyId) !== 0) {
            return response()->json([
                'error' => [
                    'code' => 'company_forbidden',
                    'message' => 'Company mismatch',
                    'details' => [
                        'requested_company_id' => $companyId,
                        'user_company_id' => $userCompanyId,
                    ],
                ],
            ], 403);
        }

        // Defense-in-depth (optional but good)
        $exists = DB::table('companies')->where('id', $companyId)->exists();
        if (!$exists) {
            return response()->json([
                'error' => ['code' => 'company_not_found', 'message' => 'Company not found'],
            ], 404);
        }

        $request->attributes->set('company_id', $companyId);

        return $next($request);
    }

    private function normalizeUuid(string $uuid): string
    {
        $uuid = trim($uuid);
        // UUIDs are case-insensitive; normalize to lowercase.
        return strtolower($uuid);
    }

    private function tableExists(string $table): bool
    {
        try {
            // Works for Postgres
            $res = DB::selectOne(
                "select to_regclass(?) as t",
                ["public.{$table}"]
            );
            return !empty($res?->t);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
