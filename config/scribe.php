<?php

use Knuckles\Scribe\Config\AuthIn;

return [

    /*
    |--------------------------------------------------------------------------
    | Title & Description
    |--------------------------------------------------------------------------
    */
    'title' => env('SCRIBE_TITLE', 'SPPG Nexus API'),
    'description' => env('SCRIBE_DESCRIPTION', 'Audit-first, multi-tenant procurement ERP API.'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('APP_URL', 'http://127.0.0.1:8000'),

    /*
    |--------------------------------------------------------------------------
    | Routes to document
    |--------------------------------------------------------------------------
    | Scribe will scan Laravel routes and include/exclude based on these patterns.
    */
    'routes' => [
        [
            'match' => [
                'domains' => ['*'],
                'prefixes' => ['api/*'],

                // IMPORTANT: Scribe v4 uses include/exclude inside match.
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth
    |--------------------------------------------------------------------------
    | Your API uses Authorization: Bearer <Supabase JWT>.
    | Set SCRIBE_AUTH_TOKEN to a valid JWT in local env if you want Scribe to call endpoints.
    */
    'auth' => [
        'enabled' => true,
        'default' => true,

        // Prefer constants for correctness (AuthIn::BEARER)
        'in' => AuthIn::BEARER,
        'name' => 'Authorization',

        // If you leave blank, Scribe can still generate docs from annotations,
        // but "response calls" won't authenticate.
        'use_value' => env('SCRIBE_AUTH_TOKEN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global headers (multi-tenant)
    |--------------------------------------------------------------------------
    | This makes Scribe include X-Company-Id in requests it makes during generation.
    */
    'headers' => [
        'X-Company-Id' => env('SCRIBE_COMPANY_ID', ''),
        'Accept' => 'application/json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Output: OpenAPI + Postman
    |--------------------------------------------------------------------------
    */
    'openapi' => [
        'enabled' => true,
    ],
    'postman' => [
        'enabled' => true,
    ],
];
