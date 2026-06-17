<?php

return [
    'default' => env('GITHUB_CONNECTION', 'main'),

    'connections' => [
        'main' => [
            'method' => 'token',
            'token' => env('GITHUB_ACCESS_TOKEN'),
        ],
    ],

    'repositories' => [
        // Per-repository GitHub credentials.
        // Each key is a short name used in CLI/API requests.
        // You can use "owner/repo" as the key, or a short alias with explicit owner.
        //
        // Examples:
        //
        // 'my-app' => [
        //     'token' => 'github_pat_...',       // optional; falls back to GITHUB_ACCESS_TOKEN
        //     'owner' => 'my-organization',       // required when the key is not "owner/repo"
        // ],
        //
        // 'laravel/laravel' => [
        //     'token' => 'github_pat_...',
        // ],
    ],

    'cache' => [
        'main' => [
            'driver' => 'illuminate',
            'connector' => env('GITHUB_CACHE_STORE'),
        ],
    ],
];
