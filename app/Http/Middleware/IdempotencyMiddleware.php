<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Apply only to mutating requests
        if (!in_array($request->method(), ['POST','PUT','PATCH','DELETE'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (!$key) {
            // You can choose: require it for specific endpoints only.
            return $next($request);
        }

        $authUser = $request->attributes->get('auth_user');
        if (!$authUser) {
            // Should not happen if VerifySupabaseJwt is earlier in the chain
            return response()->json(['error' => ['code'=>'auth_required','message'=>'Unauthenticated']], 401);
        }

        $userId = $authUser->id;
        $method = $request->method();
        $path = '/' . ltrim($request->path(), '/');

        // Include body + query in request hash
        $payload = [
            'query' => $request->query(),
            'body' => $request->all(),
        ];
        $requestHash = hash('sha256', json_encode($payload));

        // Look for existing idempotent result
        $existing = DB::table('idempotency_keys')
            ->where('key', $key)
            ->where('user_id', $userId)
            ->where('method', $method)
            ->where('path', $path)
            ->first();

        if ($existing) {
            // If same key but different payload, reject (prevents key reuse attacks)
            if ($existing->request_hash !== $requestHash) {
                return response()->json([
                    'error' => [
                        'code' => 'idempotency_conflict',
                        'message' => 'Idempotency-Key reuse with different payload',
                    ]
                ], 409);
            }

            // If response already stored, return it
            if ($existing->response_status && $existing->response_body) {
                return response()->json(json_decode($existing->response_body, true), $existing->response_status);
            }

            // Otherwise it is "in progress" or previously crashed mid-flight
            return response()->json([
                'error' => [
                    'code' => 'idempotency_in_progress',
                    'message' => 'Request with this Idempotency-Key is still being processed',
                ]
            ], 409);
        }

        // Create lock row before executing action
        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid(),
            'key' => $key,
            'user_id' => $userId,
            'method' => $method,
            'path' => $path,
            'request_hash' => $requestHash,
            'locked_at' => now(),
            'created_at' => now(),
        ]);

        $response = $next($request);

        // Persist response (best effort)
        try {
            $status = $response->getStatusCode();
            $body = $response->getContent(); // JSON string for json responses

            DB::table('idempotency_keys')
                ->where('key', $key)
                ->where('user_id', $userId)
                ->where('method', $method)
                ->where('path', $path)
                ->update([
                    'response_status' => $status,
                    'response_body' => $body,
                ]);
        } catch (\Throwable $e) {
            // If storing fails, don't break the request.
        }

        return $response;
    }
}
