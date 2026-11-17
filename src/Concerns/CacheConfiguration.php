<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Support\Facades\Config;

/**
 * Resolves a cacheable repository's configuration from its property overrides,
 * falling back to the package configuration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class CacheConfiguration
{
    /**
     * Create a new cache configuration instance.
     *
     * @param  string  $storeName
     * @param  string  $prefix
     * @param  bool  $referenceMode
     * @param  int  $referenceTtl
     * @param  \SineMacula\Repositories\Concerns\CacheStoreOptions  $storeOptions
     * @return void
     */
    private function __construct(

        /** The resolved Laravel cache store name. */
        public string $storeName,

        /** The resolved per-query cache key prefix. */
        public string $prefix,

        /** Whether the repository operates in whole-table reference mode. */
        public bool $referenceMode,

        /** The resolved reference-mode cache lifetime, in seconds. */
        public int $referenceTtl,

        /** The resolved per-query cache store options. */
        public CacheStoreOptions $storeOptions,
    ) {}

    /**
     * Resolve the cache configuration from a repository's overridable property
     * values and the package configuration.
     *
     * @param  array<string, bool|int|string|null>  $overrides
     * @param  string  $table
     * @return self
     */
    public static function resolveFor(array $overrides, string $table): self
    {
        $storeName = $overrides['cacheStoreName'] ?? Config::get('repositories.cache.store') ?? Config::get('cache.default');
        $storeName = is_string($storeName) ? $storeName : 'array';

        $prefix = $overrides['cacheKeyPrefix'] ?? $table;
        $prefix = is_string($prefix) ? $prefix : $table;

        $registryEnabled = $overrides['cacheRegistryEnabled'] ?? Config::get('repositories.cache.registry_enabled', true);

        $storeOptions = new CacheStoreOptions(
            ttl: self::resolveTtl($overrides, 'cacheTtl', 'ttl', 3600),
            sizeGuard: self::resolveSizeGuard($overrides),
            registryEnabled: (bool) $registryEnabled,
            negativeTtl: self::resolveTtl($overrides, 'cacheNegativeTtl', 'negative_ttl', 10),
        );

        return new self(
            $storeName,
            $prefix,
            (bool) ($overrides['cacheReferenceTable'] ?? false),
            self::resolveTtl($overrides, 'cacheReferenceTtl', 'reference_ttl', 3600),
            $storeOptions,
        );
    }

    /**
     * Resolve a cache TTL from its override, else the package configuration.
     *
     * @param  array<string, bool|int|string|null>  $overrides
     * @param  string  $property
     * @param  string  $key
     * @param  int  $default
     * @return int
     */
    private static function resolveTtl(array $overrides, string $property, string $key, int $default): int
    {
        $ttl = $overrides[$property] ?? Config::get('repositories.cache.' . $key, $default);

        return is_numeric($ttl) ? (int) $ttl : $default;
    }

    /**
     * Build the size guard from the configured row and byte ceilings.
     *
     * @param  array<string, bool|int|string|null>  $overrides
     * @return \SineMacula\Repositories\Concerns\CacheSizeGuard
     */
    private static function resolveSizeGuard(array $overrides): CacheSizeGuard
    {
        $maxRows  = $overrides['cacheMaxRows']  ?? Config::get('repositories.cache.max_rows', 1000);
        $maxBytes = $overrides['cacheMaxBytes'] ?? Config::get('repositories.cache.max_bytes', 262144);

        return new CacheSizeGuard(
            is_numeric($maxRows) ? (int) $maxRows : null,
            is_numeric($maxBytes) ? (int) $maxBytes : null,
        );
    }
}
