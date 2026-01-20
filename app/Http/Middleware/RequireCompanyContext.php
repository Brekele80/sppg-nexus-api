<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RequireCompanyContext
{
    public function handle(Request $request, Closure $next)
    {
        // Set by VerifySupabaseJwt middleware
        $u = $request->attributes->get('auth_user');

        if (!$u || empty($u->company_id)) {
            return response()->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Missing auth user or company context',
                ],
            ], 401);
        }

        $companyHeader = trim((string) $request->header('X-Company-Id'));
        if ($companyHeader === '') {
            return response()->json([
                'error' => [
                    'code' => 'company_header_required',
                    'message' => 'Missing X-Company-Id header',
                ],
            ], 422);
        }

        $companyId = $this->normalizeUuid($companyHeader);
        $userCompanyId = $this->normalizeUuid((string) $u->company_id);

        // Strict tenant isolation
        if ($companyId !== $userCompanyId) {
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

        // Defense-in-depth: ensure company row exists
        if (!$this->tableExists('companies')) {
            return response()->json([
                'error' => [
                    'code' => 'server_misconfig',
                    'message' => 'companies table missing',
                ],
            ], 500);
        }

        $companyExists = DB::table('companies')->where('id', $companyId)->exists();
        if (!$companyExists) {
            return response()->json([
                'error' => [
                    'code' => 'company_not_found',
                    'message' => 'Company not found',
                ],
            ], 404);
        }

        // Branch binding: user must have branch_id and it must belong to the same company
        $branchIdRaw = trim((string) ($u->branch_id ?? ''));
        if ($branchIdRaw === '') {
            return response()->json([
                'error' => [
                    'code' => 'branch_missing',
                    'message' => 'Missing branch context on user',
                ],
            ], 401);
        }

        $branchId = $this->normalizeUuid($branchIdRaw);

        if (!$this->tableExists('branches')) {
            return response()->json([
                'error' => [
                    'code' => 'server_misconfig',
                    'message' => 'branches table missing',
                ],
            ], 500);
        }

        $branchOk = DB::table('branches')
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->exists();

        if (!$branchOk) {
            return response()->json([
                'error' => [
                    'code' => 'branch_forbidden',
                    'message' => 'Branch does not belong to this company',
                    'details' => [
                        'branch_id' => $branchId,
                        'company_id' => $companyId,
                    ],
                ],
            ], 403);
        }

        // Stamp context for downstream code
        $request->attributes->set('company_id', $companyId);
        $request->attributes->set('branch_id', $branchId);

        return $next($request);
    }

    private function normalizeUuid(string $uuid): string
    {
        return strtolower(trim($uuid));
    }

    private function tableExists(string $table): bool
    {
        try {
            // Postgres: check regclass
            $res = DB::selectOne("select to_regclass(?) as t", ["public.{$table}"]);
            return !empty($res?->t);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
