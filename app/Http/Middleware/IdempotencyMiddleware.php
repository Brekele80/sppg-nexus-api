<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Carbon\Carbon;

class IdempotencyMiddleware
{
    private int $staleLockSeconds = 60;

    public function handle(Request $request, Closure $next)
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return $next($request);
        }

        $authUser = $request->attributes->get('auth_user');
        if (!$authUser) {
            return response()->json(['error' => ['code' => 'auth_required', 'message' => 'Unauthenticated']], 401);
        }

        // If you ONLY apply this middleware on company-scoped routes, this is fine:
        $companyId = $request->header('X-Company-Id');
        if (!$companyId) {
            return response()->json(['error' => ['code' => 'company_required', 'message' => 'Missing X-Company-Id']], 400);
        }

        $userId = (string) $authUser->id;
        $method = $request->method();

        // Canonical path (includes leading slash; stable under /api prefix)
        $path = $request->getPathInfo();

        $payload = $this->normalizeForHash([
            'query' => $request->query(),
            'body'  => $request->all(),
        ]);

        $requestHash = hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Look for existing
        $existing = DB::table('idempotency_keys')
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->where('user_id', $userId)
            ->where('method', $method)
            ->where('path', $path)
            ->first();

        if ($existing) {
            if ((string) $existing->request_hash !== (string) $requestHash) {
                return response()->json([
                    'error' => [
                        'code' => 'idempotency_conflict',
                        'message' => 'Idempotency-Key reuse with different payload',
                    ]
                ], 409);
            }

            // Completed: replay
            if ($existing->response_status !== null) {
                $body = $this->normalizeDbJsonb($existing->response_body);
                return response()->json($body, (int) $existing->response_status);
            }

            // In progress: stale lock handling
            $lockedAt = $existing->locked_at ? Carbon::parse($existing->locked_at) : null;

            if ($lockedAt && $lockedAt->diffInSeconds(now()) > $this->staleLockSeconds) {
                $this->deleteKeyRow($companyId, $key, $userId, $method, $path);
                // continue to reserve a new lock
            } else {
                return response()->json([
                    'error' => [
                        'code' => 'idempotency_in_progress',
                        'message' => 'Request is still being processed',
                    ]
                ], 409);
            }
        }

        // Reserve (handle race: unique constraint)
        try {
            DB::table('idempotency_keys')->insert([
                'id'           => (string) Str::uuid(),
                'company_id'   => $companyId,
                'key'          => $key,
                'user_id'      => $userId,
                'method'       => $method,
                'path'         => $path,
                'request_hash' => $requestHash,
                'locked_at'    => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (QueryException $e) {
            // If another request reserved first, re-read and respond accordingly
            $sqlState = $e->errorInfo[0] ?? null;
            if ($sqlState === '23505') {
                $existing = DB::table('idempotency_keys')
                    ->where('company_id', $companyId)
                    ->where('key', $key)
                    ->where('user_id', $userId)
                    ->where('method', $method)
                    ->where('path', $path)
                    ->first();

                if ($existing && $existing->response_status !== null) {
                    $body = $this->normalizeDbJsonb($existing->response_body);
                    return response()->json($body, (int) $existing->response_status);
                }

                return response()->json([
                    'error' => [
                        'code' => 'idempotency_in_progress',
                        'message' => 'Request is still being processed',
                    ]
                ], 409);
            }

            throw $e;
        }

        $response = $next($request);

        // Persist response (best-effort)
        try {
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
            $body   = $this->extractJsonBody($response);

            DB::table('idempotency_keys')
                ->where('company_id', $companyId)
                ->where('key', $key)
                ->where('user_id', $userId)
                ->where('method', $method)
                ->where('path', $path)
                ->update([
                    'response_status' => $status,
                    'response_body'   => $body,
                    'updated_at'      => now(),
                ]);
        } catch (\Throwable $e) {
            // do not break the request
        }

        return $response;
    }

    private function deleteKeyRow(string $companyId, string $key, string $userId, string $method, string $path): void
    {
        DB::table('idempotency_keys')
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->where('user_id', $userId)
            ->where('method', $method)
            ->where('path', $path)
            ->delete();
    }

    private function extractJsonBody($response): ?array
    {
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            return $response->getData(true);
        }

        if (method_exists($response, 'getContent')) {
            $raw = $response->getContent();
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    private function normalizeDbJsonb($value): array
    {
        if (is_array($value)) return $value;

        if ($value instanceof \stdClass) {
            return json_decode(json_encode($value), true) ?: [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function normalizeForHash($value)
    {
        if ($value instanceof \Illuminate\Http\UploadedFile) {
            return [
                '__file' => true,
                'name'   => $value->getClientOriginalName(),
                'size'   => $value->getSize(),
                'mime'   => $value->getClientMimeType(),
            ];
        }

        if (is_object($value)) {
            return $this->normalizeForHash((array) $value);
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalizeForHash($v);
            }
            if ($this->isAssoc($value)) ksort($value);
            return $value;
        }

        return $value;
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
