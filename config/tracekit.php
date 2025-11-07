<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TraceKit API Key
    |--------------------------------------------------------------------------
    |
    | Your TraceKit API key. Get one at https://app.tracekit.dev
    |
    */
    'api_key' => env('TRACEKIT_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | TraceKit Endpoint
    |--------------------------------------------------------------------------
    |
    | The OTLP endpoint for sending traces. Default is TraceKit's hosted service.
    |
    */
    'endpoint' => env('TRACEKIT_ENDPOINT', 'https://app.tracekit.dev/v1/traces'),

    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    |
    | The name of your service as it will appear in TraceKit.
    |
    */
    'service_name' => env('TRACEKIT_SERVICE_NAME', env('APP_NAME', 'laravel-app')),

    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Tracing
    |--------------------------------------------------------------------------
    |
    | Enable or disable tracing. Useful for local development.
    |
    */
    'enabled' => env('TRACEKIT_ENABLED', env('APP_ENV') !== 'local'),

    /*
    |--------------------------------------------------------------------------
    | Sample Rate
    |--------------------------------------------------------------------------
    |
    | Percentage of requests to trace (0.0 to 1.0). 1.0 = trace everything.
    |
    */
    'sample_rate' => env('TRACEKIT_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Enable/disable specific tracing features.
    |
    */
    'features' => [
        'http' => env('TRACEKIT_HTTP_ENABLED', true),
        'database' => env('TRACEKIT_DATABASE_ENABLED', true),
        'cache' => env('TRACEKIT_CACHE_ENABLED', true),
        'queue' => env('TRACEKIT_QUEUE_ENABLED', true),
        'redis' => env('TRACEKIT_REDIS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Routes
    |--------------------------------------------------------------------------
    |
    | Routes to exclude from tracing (e.g., health checks).
    |
    */
    'ignored_routes' => [
        '/health',
        '/up',
        '/_healthz',
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow Query Threshold (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Queries slower than this will be highlighted in traces.
    |
    */
    'slow_query_threshold' => env('TRACEKIT_SLOW_QUERY_MS', 100),

    /*
    |--------------------------------------------------------------------------
    | Include Query Bindings
    |--------------------------------------------------------------------------
    |
    | Whether to include SQL query bindings in traces. Disable if handling
    | sensitive data.
    |
    */
    'include_query_bindings' => env('TRACEKIT_INCLUDE_BINDINGS', true),
];
