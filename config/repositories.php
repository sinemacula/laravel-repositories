<?php

declare(strict_types = 1);

return [

    /*
    |---------------------------------------------------------------------------
    | Repository Cache Configuration
    |---------------------------------------------------------------------------
    |
    | This section configures the opt-in repository cache (enabled per
    | repository via the Cacheable trait). The default mode keys cache entries
    | per executed query, so a filtered or by-id read never returns the full
    | table. The 'prefix' namespaces every cache entry to avoid key collisions
    | with other parts of your application. Each option may be overridden per
    | repository via a property:
    |
    |   - `ttl`             - `protected int $cacheTtl`
    |   - `store`           - `protected ?string $cacheStoreName`
    |   - `max_rows`        - `protected ?int $cacheMaxRows`
    |   - `max_bytes`       - `protected ?int $cacheMaxBytes`
    |   - `reference_ttl`   - `protected int $cacheReferenceTtl`
    |   - `negative_ttl`    - `protected ?int $cacheNegativeTtl`
    |   - (key prefix)      - `protected ?string $cacheKeyPrefix`
    |   - (reference mode)  - `protected bool $cacheReferenceTable`
    |
    | `max_rows` and `max_bytes` form the size guard: results larger than either
    | limit are still fetched and returned, but not stored. Set either to null
    | to disable that bound. `registry_enabled` controls how non-taggable stores
    | invalidate per-query entries: when true a generational table version is
    | bumped on every write, orphaning existing entries in a single atomic
    | increment; when false invalidation falls back to TTL expiry only (a
    | documented degraded behaviour). `negative_ttl` is the shorter lifetime
    | applied to negatively cached null/miss reads, bounding how long a stale
    | "not found" is served and how much memory probe-fill can occupy; it
    | defaults to 10 seconds.
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
