<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

/**
 * The tuning options for a per-query repository cache store.
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

        /** Whether a flush on a non-taggable store replaces the generation token. */
        public bool $registryEnabled,

        /** The lifetime, in seconds, for cached negative lookups. */
        public int $negativeTtl,
    ) {}
}
