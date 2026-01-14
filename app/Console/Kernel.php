<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middlewareGroups = [
        'api' => [
            \App\Http\Middleware\ForceJsonResponse::class,

            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    protected $routeMiddleware = [
        'supabase'       => \App\Http\Middleware\VerifySupabaseJwt::class,
        'requireCompany' => \App\Http\Middleware\RequireCompanyContext::class,
        'idempotency'    => \App\Http\Middleware\IdempotencyMiddleware::class,
        'requireRole'    => \App\Http\Middleware\RequireRole::class,
    ];
}
