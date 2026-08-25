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
    'ssl' => (bool) env('PULSEINDEX_SSL', false),

    /*
    |--------------------------------------------------------------------------
    | Default RPC timeout (microseconds)
    |--------------------------------------------------------------------------
    */
    'timeout_us' => (int) env('PULSEINDEX_TIMEOUT_US', 5_000_000),
];
