<?php

return [
    'title' => 'SPPG Nexus API',
    'description' => 'Audit-first, multi-tenant procurement ERP API.',
    'base_url' => env('SCRIBE_BASE_URL', env('APP_URL', 'http://127.0.0.1:8000')),

    /**
     * Which routes to document.
     * We document only /api/*, excluding /api/health.
     */
    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/*'],
                'domains' => ['*'],
            ],
            'include' => [
                'api/me',
                'api/dc/*',
                'api/prs*',
                'api/rabs*',
                'api/pos*',
                'api/supplier/*',
                'api/inventory*',
                'api/notifications*',
                'api/audit*',
            ],
            'exclude' => [
                'api/health',
            ],
        ],
    ],

    /**
     * Auth configuration: Supabase JWTs (Bearer).
     */
    'auth' => [
        'enabled' => true,
        'default' => true,
        'in' => 'bearer',
        'name' => 'Authorization',
        'use_value' => env('SCRIBE_AUTH_TOKEN', ''), // used during `scribe:generate` for authenticated routes
        'placeholder' => 'Bearer {SUPABASE_JWT}',
        'extra_info' => 'Use a Supabase access_token as Bearer token.',
    ],

    /**
     * Global headers to show on every endpoint.
     * X-Company-Id is required for tenant-scoped routes (almost everything except /me and /health).
     */
    'headers' => [
        'Accept' => 'application/json',
        'X-Company-Id' => env('SCRIBE_COMPANY_ID', ''),
    ],

    /**
     * Output formats.
     */
    'openapi' => [
        'enabled' => true,
    ],
    'postman' => [
        'enabled' => true,
    ],

    /**
     * Where to write generated docs/artifacts.
     */
    'output' => [
        'path' => 'public/docs',
    ],

    /**
     * Default response language.
     */
    'type' => 'static',
];
