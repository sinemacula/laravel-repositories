<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

/**
 * Immutable configuration bundle for a per-query repository cache store.
 *
 * Groups the correlated tuning parameters - lifetime, size guard, registry
 * behaviour, and negative-lookup lifetime - so the cache store constructor
 * stays within the parameter limit and the options can be resolved once at
 * boot.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class CacheStoreOptions
{
    /**
     * Create a new cache store options instance.
     *
     * @param  int  $ttl
     * @param  \SineMacula\Repositories\Concerns\CacheSizeGuard  $sizeGuard
     * @param  bool  $registryEnabled
     * @param  int  $negativeTtl
     * @return void
     */
    public function __construct(

        /** The lifetime, in seconds, for positive cache entries. */
        public int $ttl,

        /** The guard that enforces the per-store cache size limit. */
        public CacheSizeGuard $sizeGuard,

        /** Whether non-taggable stores bump the table version on flush. */
        public bool $registryEnabled,

        /** The lifetime, in seconds, for cached negative lookups. */
        public int $negativeTtl,
    ) {}
}
