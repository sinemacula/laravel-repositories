<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Support\Facades\Config;

/**
 * Resolves the tuning configuration for a cacheable repository from its
 * overridable property values and the package configuration, in that precedence
 * order.
 *
 * Constructed once per repository instance in bootCacheable(), from the
 * already-extracted overridable property values, so CacheStoreOptions is always
 * built from named values rather than a positional parameter list.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class CacheConfiguration
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
        public readonly string $storeName,

        /** The resolved per-query cache key prefix. */
        public readonly string $prefix,

        /** Whether the repository operates in whole-table reference mode. */
        public readonly bool $referenceMode,

        /** The resolved reference-mode cache lifetime, in seconds. */
        public readonly int $referenceTtl,

        /** The resolved per-query cache store options. */
        public readonly CacheStoreOptions $storeOptions,
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
            ttl: self::resolveTtl($overrides),
            sizeGuard: self::resolveSizeGuard($overrides),
            registryEnabled: (bool) $registryEnabled,
            negativeTtl: self::resolveNegativeTtl($overrides),
        );

        return new self(
            $storeName,
            $prefix,
            (bool) ($overrides['cacheReferenceTable'] ?? false),
            self::resolveReferenceTtl($overrides),
            $storeOptions,
        );
    }

    /**
     * Resolve the configured per-query cache TTL.
     *
     * @param  array<string, bool|int|string|null>  $overrides
     * @return int
     */
    private static function resolveTtl(array $overrides): int
    {
        $ttl = $overrides['cacheTtl'] ?? Config::get('repositories.cache.ttl', 3600);

        return is_numeric($ttl) ? (int) $ttl : 3600;
    }

    /**
     * Resolve the configured reference-mode cache TTL.
     *
     * @param  array<string, bool|int|string|null>  $overrides
     * @return int
     */
    private static function resolveReferenceTtl(array $overrides): int
    {
        $ttl = $overrides['cacheReferenceTtl'] ?? Config::get('repositories.cache.reference_ttl', 3600);

        return is_numeric($ttl) ? (int) $ttl : 3600;
    }

    /**
     * Resolve the configured negative-lookup (null/miss) cache TTL.
     *
     * @param  array<string, bool|int|string|null>  $overrides
     * @return int
     */
    private static function resolveNegativeTtl(array $overrides): int
    {
        $ttl = $overrides['cacheNegativeTtl'] ?? Config::get('repositories.cache.negative_ttl', 10);

        return is_numeric($ttl) ? (int) $ttl : 10;
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
