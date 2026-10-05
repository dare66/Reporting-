<?php

return [
    'jwt' => [
        'secret' => env('JWT_SECRET'),
        'issuer' => env('JWT_ISSUER', 'aixbi'),
        'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),
        'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 2592000),
    ],

    'query' => [
        'connection' => env('ANALYTICS_CONNECTION', 'analytics'),
        'dialect' => env('ANALYTICS_DIALECT', 'postgres'),
        'timeout_ms' => (int) env('QUERY_TIMEOUT_MS', 15000),
        'max_rows' => (int) env('QUERY_MAX_ROWS', 5000),
        'cache_ttl' => (int) env('QUERY_CACHE_TTL', 300),
    ],

    'ai' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8100'),
        'token' => env('AI_SERVICE_TOKEN'),
        'timeout' => (int) env('AI_SERVICE_TIMEOUT', 120),
    ],

    'connectors' => [
        // Loopback and link-local (cloud metadata) hosts are always refused.
        // Set to true to also refuse private networks, e.g. for a hosted multi-tenant deployment.
        'block_private' => (bool) env('CONNECTORS_BLOCK_PRIVATE', false),
        // Comma-separated hosts exempt from the checks above, e.g. "127.0.0.1" to load a database on this machine.
        'allow_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CONNECTORS_ALLOW_HOSTS', ''))))),
    ],

    'streaming' => [
        'kafka_brokers' => env('KAFKA_BROKERS'),
    ],

    'demo' => [
        // Seed the demo tenant outside local/testing (e.g. a hosted demo environment).
        'seed' => (bool) env('SEED_DEMO', false),
        // Synthetic dataset size multiplier (1 ≈ 290k applications).
        'scale' => (float) env('DEMO_SCALE', 1),
    ],
];
