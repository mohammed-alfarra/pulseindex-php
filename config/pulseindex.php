<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | PulseIndex gRPC host
    |--------------------------------------------------------------------------
    |
    | Hostname:port of the PulseIndex engine (e.g. localhost:50051).
    |
    */
    'host' => env('PULSEINDEX_HOST', 'localhost:50051'),

    /*
    |--------------------------------------------------------------------------
    | API key
    |--------------------------------------------------------------------------
    |
    | Sent as x-api-key metadata on every RPC. Must match a key configured on
    | the engine via PULSEINDEX_API_KEYS (unless auth is disabled server-side).
    |
    */
    'api_key' => env('PULSEINDEX_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | TLS
    |--------------------------------------------------------------------------
    */
    'ssl' => filter_var(env('PULSEINDEX_SSL', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Default RPC timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Converted to microseconds when constructing the gRPC client. Override
    | with PULSEINDEX_TIMEOUT_US when you need microsecond precision.
    |
    */
    'timeout' => (float) env('PULSEINDEX_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Default RPC timeout (microseconds)
    |--------------------------------------------------------------------------
    |
    | When set, takes precedence over `timeout`.
    |
    */
    'timeout_us' => env('PULSEINDEX_TIMEOUT_US') !== null && env('PULSEINDEX_TIMEOUT_US') !== ''
        ? (int) env('PULSEINDEX_TIMEOUT_US')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Eloquent fallback
    |--------------------------------------------------------------------------
    |
    | When PulseIndex is unreachable, PulseQueryBuilder logs a warning and
    | hydrates via a native Eloquent query instead of failing the request.
    |
    */
    'fallback_enabled' => (bool) env('PULSEINDEX_FALLBACK_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default tenant
    |--------------------------------------------------------------------------
    */
    'tenant_id' => env('PULSEINDEX_TENANT_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Admin HTTP endpoint
    |--------------------------------------------------------------------------
    |
    | The engine's admin port (health/ready/recovery), separate from the gRPC
    | port. Used by `php artisan pulse:reindex --recovery` to POST
    | /recovery/reindex-complete. When admin_url is null it is derived from
    | `host` by replacing the port with `admin_port`.
    |
    */
    'admin_url' => env('PULSEINDEX_ADMIN_URL'),
    'admin_port' => (int) env('PULSEINDEX_ADMIN_PORT', 8081),
    'internal_token' => env('PULSEINDEX_ENGINE_INTERNAL_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Searchable models
    |--------------------------------------------------------------------------
    |
    | Fully-qualified model classes using the PulseSearchable trait. Used by
    | `php artisan pulse:reindex` with no argument, and required for
    | `pulse:reindex --recovery` (which rebuilds the whole index).
    |
    */
    'searchable_models' => [],

    /*
    |--------------------------------------------------------------------------
    | Durable outbox
    |--------------------------------------------------------------------------
    |
    | Every model change is written as a marker row (on the model's own
    | connection, in its transaction) and drained by a worker with retry, so a
    | change is never lost when the engine is briefly unreachable.
    |
    | Run the migration on every connection listed here, and schedule
    | `php artisan pulse:outbox:work --once` (or run it as a daemon).
    |
    */
    'outbox' => [
        'connections' => array_values(array_filter(array_map('trim', explode(
            ',',
            (string) env('PULSEINDEX_OUTBOX_CONNECTIONS', (string) env('DB_CONNECTION', 'mysql')),
        )))),
        'table' => 'pulseindex_outbox',
        'dispatch' => filter_var(env('PULSEINDEX_OUTBOX_DISPATCH', true), FILTER_VALIDATE_BOOLEAN),
        'queue' => env('PULSEINDEX_OUTBOX_QUEUE', 'default'),
        'lease_seconds' => (int) env('PULSEINDEX_OUTBOX_LEASE', 300),
        'max_attempts' => (int) env('PULSEINDEX_OUTBOX_MAX_ATTEMPTS', 12),
    ],
];
