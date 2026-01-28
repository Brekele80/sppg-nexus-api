<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnforceInventoryIdempotency
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->hasHeader('Idempotency-Key')) {
            abort(400, 'Missing Idempotency-Key header');
        }

        $key = $request->header('Idempotency-Key');

        if (strlen($key) < 12) {
            abort(400, 'Invalid Idempotency-Key');
        }

        $request->attributes->set('idempotency_key', $key);

        return $next($request);
    }
}
