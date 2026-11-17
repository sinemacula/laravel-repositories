<?php

declare(strict_types = 1);

return [

    /*
    |---------------------------------------------------------------------------
    | Repository Cache Configuration
    |---------------------------------------------------------------------------
    |
    | Configures the opt-in repository cache, enabled per repository with the
    | Cacheable trait. The 'prefix' namespaces every cache key. Each option may
    | be overridden per repository via a property:
    |
    |   - `ttl`              - `protected int $cacheTtl`
    |   - `store`            - `protected ?string $cacheStoreName`
    |   - `max_rows`         - `protected ?int $cacheMaxRows`
    |   - `max_bytes`        - `protected ?int $cacheMaxBytes`
    |   - `reference_ttl`    - `protected int $cacheReferenceTtl`
    |   - `negative_ttl`     - `protected ?int $cacheNegativeTtl`
    |   - `registry_enabled` - `protected bool $cacheRegistryEnabled`
    |   - (key prefix)       - `protected ?string $cacheKeyPrefix`
    |   - (reference mode)   - `protected bool $cacheReferenceTable`
    |
    | Results over `max_rows` or `max_bytes` are returned but not stored; null
    | disables that bound. With `registry_enabled` false, writes do not
    | invalidate per-query entries on a non-taggable store, so they live until
    | their TTL. `negative_ttl` is the lifetime of a cached "not found" read.
    |
    */

    'cache' => [

        'prefix' => env('REPOSITORY_CACHE_PREFIX', 'repositories'),

        'ttl' => is_numeric($cacheTtl = env('REPOSITORY_CACHE_TTL', 3600)) ? (int) $cacheTtl : 3600,

        'store' => env('REPOSITORY_CACHE_STORE'),

        'max_rows' => is_numeric($cacheMaxRows = env('REPOSITORY_CACHE_MAX_ROWS', 1000)) ? (int) $cacheMaxRows : 1000,

        'max_bytes' => is_numeric($cacheMaxBytes = env('REPOSITORY_CACHE_MAX_BYTES', 262144)) ? (int) $cacheMaxBytes : 262144,

        'reference_ttl' => is_numeric($cacheReferenceTtl = env('REPOSITORY_CACHE_REFERENCE_TTL', 3600)) ? (int) $cacheReferenceTtl : 3600,

        'negative_ttl' => is_numeric($cacheNegativeTtl = env('REPOSITORY_CACHE_NEGATIVE_TTL', 10)) ? (int) $cacheNegativeTtl : 10,

        'registry_enabled' => env('REPOSITORY_CACHE_REGISTRY_ENABLED', true),

    ],

];
