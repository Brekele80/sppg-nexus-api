<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
            // ...
        });

        $this->renderable(function (ValidationException $e, $request) {
            if (!$request->expectsJson()) return null;

            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Validation failed',
                    'details' => $e->errors(),
                ]
            ], 422);
        });

        $this->renderable(function (AuthenticationException $e, $request) {
            if (!$request->expectsJson()) return null;

            return response()->json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Unauthenticated',
                ]
            ], 401);
        });

        $this->renderable(function (HttpExceptionInterface $e, $request) {
            if (!$request->expectsJson()) return null;

            $status = $e->getStatusCode();
            $message = $e->getMessage() ?: 'Request failed';

            $code = match ($status) {
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'conflict',
                429 => 'rate_limited',
                default => 'http_error',
            };

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ]
            ], $status);
        });

        $this->renderable(function (Throwable $e, $request) {
            if (!$request->expectsJson()) return null;

            if (config('app.debug')) {
                return response()->json([
                    'error' => [
                        'code' => 'server_error',
                        'message' => $e->getMessage(),
                    ]
                ], 500);
            }

            return response()->json([
                'error' => [
                    'code' => 'server_error',
                    'message' => 'Internal server error',
                ]
            ], 500);
        });
    }
}
