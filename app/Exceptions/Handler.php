<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Keep default reporting behavior
        });
    }

    /**
     * Standardized JSON API error responses.
     */
    public function render($request, Throwable $e)
    {
        if (!$request->expectsJson()) {
            return parent::render($request, $e);
        }

        // 422 Validation
        if ($e instanceof ValidationException) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Validation failed',
                    'details' => $e->errors(),
                ],
            ], 422);
        }

        // 401 Unauthenticated
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Unauthenticated',
                ],
            ], 401);
        }

        // 403 Forbidden (policies/gates)
        if ($e instanceof AuthorizationException) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => $e->getMessage() ?: 'Forbidden',
                ],
            ], 403);
        }

        // 404 Model not found
        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Resource not found',
                ],
            ], 404);
        }

        // 404 Route not found
        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Route not found',
                ],
            ], 404);
        }

        // Any explicit HTTP exception (403/404/409/429/etc)
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() ?: 'Request failed';

            $code = match ($status) {
                400 => 'bad_request',
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                422 => 'validation_failed',
                429 => 'rate_limited',
                default => 'http_error',
            };

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], $status);
        }

        // 500 fallback
        if (config('app.debug')) {
            return response()->json([
                'error' => [
                    'code' => 'server_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }

        return response()->json([
            'error' => [
                'code' => 'server_error',
                'message' => 'Internal server error',
            ],
        ], 500);
    }
}
