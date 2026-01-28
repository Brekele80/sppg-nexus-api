<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Support\AuthUser;
use App\Services\StockPreflightService;
use App\Services\IdempotencyService;
use Symfony\Component\HttpFoundation\Response;

class EnsureStockAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = AuthUser::requireCompanyContext($request);
        $branchId  = AuthUser::requireBranchAccess($request);

        $key = $request->header('Idempotency-Key');
        if (!$key) {
            return response()->json([
                'error' => 'Missing Idempotency-Key'
            ], 400);
        }

        // Replay protection
        $cached = IdempotencyService::lock($request, $companyId, $key);
        if ($cached) {
            return response()->json($cached, 200);
        }

        // Oversell protection
        StockPreflightService::assertAvailable(
            $companyId,
            $branchId,
            $request->input('item_id'),
            (float) $request->input('quantity')
        );

        return $next($request);
    }
}
