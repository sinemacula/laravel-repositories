<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Cache\Repository as ConcreteRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SineMacula\Repositories\Contracts\CacheInvalidator;
use SineMacula\Repositories\Enums\CacheKeys;

/**
 * Encapsulates all interactions with the Laravel cache contract for managing
 * per-query repository cache entries and their invalidation.
 *
 * Each executed query is stored under a key derived from its fingerprint, so a
 * filtered or by-id read never returns the full-table collection. Invalidation
 * is performed per table - via cache tags when the store supports them, or via
 * a generational table version otherwise: every per-query key embeds the
 * current table version, and a write bumps the version with a single atomic
 * increment. Invalidation is therefore O(1) and free of the read-modify-write
 * races a tracked key set suffers; orphaned old-version entries simply expire
 * by TTL.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class CacheStore implements CacheInvalidator
{
    /** @var string The cache tag scoping all per-query entries for the table. */
    private readonly string $tag;

    /** @var string The resolved cache key for the repository metadata. */
    private readonly string $metaKey;

    /** @var string The resolved cache key holding the table's generational version. */
    private readonly string $versionKey;

    /** @var \Illuminate\Cache\Repository|null The concrete store when it supports tags, otherwise null. */
    private readonly ?ConcreteRepository $taggableStore;

    /** @var int|null The memoised table version for the non-taggable generational scheme. */
    private ?int $version = null;

    /**
     * Create a new cache store instance.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $store
     * @param  string  $table
     * @param  \SineMacula\Repositories\Concerns\CacheStoreOptions  $options
     * @return void
     */
    public function __construct(

        /** The underlying cache store instance. */
        private readonly CacheContract $store,

        /** The cache key prefix scoping this store's per-query entries. */
        private readonly string $table,

        /** The cache behaviour options for this store. */
        private readonly CacheStoreOptions $options,
    ) {
        $this->taggableStore = $this->store instanceof ConcreteRepository && $this->store->supportsTags() ? $this->store : null;
        $this->tag           = self::tagFor($this->table);
        $this->metaKey       = CacheKeys::REPOSITORY_CACHE_META->resolveKey([$this->table]);
        $this->versionKey    = self::versionKeyFor($this->table);
    }

    /**
     * Invalidate every per-query cache entry for a table without a configured
     * store instance, for cross-cutting callers that only need to drop a
     * table's cache.
     *
     * This is the invalidation half of flushTable(): it flushes the table tag
     * on a taggable store, or bumps the generational version on a non-taggable
     * store when the registry is enabled. It deliberately omits the metadata
     * write flushTable() performs, because the caller is not the owning
     * repository and does not track that table's cache status.
     *
     * Resolves the store via the Cache facade rather than constructor
     * injection, since a static cross-cutting entry point has no composition
     * root to inject one from.
     *
     * @param  string  $cacheStoreName
     * @param  string  $table
     * @param  bool  $registryEnabled
     * @return void
     */
    public static function invalidateTable(string $cacheStoreName, string $table, bool $registryEnabled): void
    {
        $store = Cache::store($cacheStoreName);

        if ($store instanceof ConcreteRepository && $store->supportsTags()) {

            $store->tags([self::tagFor($table)])->flush();

            return;
        }

        if (!$registryEnabled) {
            return;
        }

        $versionKey = self::versionKeyFor($table);

        if (self::incrementVersion($store, $versionKey) !== null) {
            return;
        }

        Log::error('Table version increment failed after seed retry', [
            'store'       => $cacheStoreName,
            'table'       => $table,
            'version_key' => $versionKey,
        ]);
    }

    /**
     * Get the raw cached entry for the given query fingerprint in a single
     * round trip.
     *
     * The negative-cache marker is returned as-is, so a caller can
     * distinguish an absent entry (null), a negative hit (CacheMiss), and a
     * cached value without a separate has() check - and without the window
     * where an entry expiring between the two calls masquerades as a negative
     * hit.
     *
     * @param  string  $hash
     * @return mixed
     */
    public function fetch(string $hash): mixed
    {
        return $this->scopedStore()->get($this->keyFor($hash));
    }

    /**
     * Store the given result for a query fingerprint, subject to the size
     * guard.
     *
     * @param  string  $hash
     * @param  mixed  $result
     * @param  int  $rows
     * @return void
     */
    public function put(string $hash, mixed $result, int $rows): void
    {
        if (!$this->options->sizeGuard->allows($result, $rows)) {
            return;
        }

        $this->scopedStore()->put($this->keyFor($hash), $result, $this->options->ttl);
        $this->store->put($this->metaKey, ['populated_at' => now()->timestamp], $this->options->ttl);
    }

    /**
     * Store a negative (null/miss) marker for a query fingerprint under the
     * shorter negative TTL.
     *
     * The marker is scoped to the table tag/version like any other entry, so a
     * write still invalidates it; it bypasses the size guard because it is a
     * constant-size sentinel, and it does not touch the populated_at metadata
     * because it represents the absence of data rather than cached data.
     *
     * @param  string  $hash
     * @return void
     */
    public function putMiss(string $hash): void
    {
        $this->scopedStore()->put($this->keyFor($hash), new CacheMiss, $this->options->negativeTtl);
    }

    /**
     * Invalidate every per-query entry for the repository table.
     *
     * @return void
     */
    #[\Override]
    public function flushTable(): void
    {
        if ($this->taggableStore !== null) {
            $this->taggableStore->tags([$this->tag])->flush();
        } elseif ($this->options->registryEnabled) {
            $this->bumpVersion();
        }

        $this->store->put($this->metaKey, ['invalidated_at' => now()->timestamp], $this->options->ttl);
    }

    /**
     * Get the current cache status.
     *
     * Note: the returned status reflects stored metadata, not a guaranteed data
     * presence. An external or shared-store flush can remove data without going
     * through flushTable(), leaving isPopulated() returning true while the
     * underlying entries are gone.
     *
     * @return \SineMacula\Repositories\Concerns\CacheStatus
     */
    public function getStatus(): CacheStatus
    {
        /** @var array{populated_at?: int, invalidated_at?: int}|null $meta */
        $meta      = $this->store->get($this->metaKey);
        $populated = isset($meta['populated_at']) && !isset($meta['invalidated_at']);

        return CacheStatus::fromMeta($meta, $populated);
    }

    /**
     * Get the underlying cache repository instance.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public function getStore(): CacheContract
    {
        return $this->store;
    }

    /**
     * Atomically increment a table's generational version, seeding the key
     * when the store cannot increment a missing entry.
     *
     * Some stores (e.g. the database driver) return false instead of creating
     * the key on increment, which would silently reduce every version bump to
     * a no-op; add() seeds the key atomically so concurrent writers converge
     * on a single counter before retrying the increment. Returns null when the
     * increment still fails after the seed retry - a store outage rather than
     * a missing key - so the caller can surface the failure instead of masking
     * it.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $store
     * @param  string  $versionKey
     * @return int|null
     */
    private static function incrementVersion(CacheContract $store, string $versionKey): ?int
    {
        $bumped = $store->increment($versionKey);

        if (is_int($bumped)) {
            return $bumped;
        }

        $store->add($versionKey, 0);

        $bumped = $store->increment($versionKey);

        return is_int($bumped) ? $bumped : null;
    }

    /**
     * Resolve the cache tag scoping all per-query entries for a table.
     *
     * @param  string  $table
     * @return string
     */
    private static function tagFor(string $table): string
    {
        return 'repo-table:' . $table;
    }

    /**
     * Resolve the cache key holding a table's generational version.
     *
     * @param  string  $table
     * @return string
     */
    private static function versionKeyFor(string $table): string
    {
        return CacheKeys::REPOSITORY_CACHE_VERSION->resolveKey([$table]);
    }

    /**
     * Resolve the cache key for a query fingerprint.
     *
     * On taggable stores the tag handles invalidation, so the key is the bare
     * table/fingerprint pair. Otherwise the current generational version is
     * folded in, so a version bump orphans every previously stored key.
     *
     * @param  string  $hash
     * @return string
     */
    private function keyFor(string $hash): string
    {
        $scopedHash = $this->taggableStore !== null ? $hash : $this->tableVersion() . ':' . $hash;

        return CacheKeys::REPOSITORY_QUERY_CACHE->resolveKey([$this->table, $scopedHash]);
    }

    /**
     * Get the cache repository scoped to the table tag where supported.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    private function scopedStore(): CacheContract
    {
        return $this->taggableStore !== null
            ? $this->taggableStore->tags([$this->tag])
            : $this->store;
    }

    /**
     * Resolve the table's current generational version, memoised for the
     * lifetime of this store instance.
     *
     * Only applies to non-taggable stores. The version is read once per
     * instance and held for the request, so a concurrent bump elsewhere is
     * invisible here until this instance is discarded; orphaned entries still
     * expire by TTL, so the inconsistency is bounded and self-healing.
     *
     * @return int
     */
    private function tableVersion(): int
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $value = $this->store->get($this->versionKey);

        return $this->version = is_int($value) ? $value : 0;
    }

    /**
     * Bump the table's generational version, orphaning every existing per-query
     * key for the table in a single atomic write.
     *
     * Falls back to a local, unpersisted bump when the store cannot persist
     * the increment, so this instance still stops serving pre-flush entries;
     * the failure is logged since other processes never observe it.
     *
     * @return void
     */
    private function bumpVersion(): void
    {
        $bumped = self::incrementVersion($this->store, $this->versionKey);

        if ($bumped !== null) {

            $this->version = $bumped;

            return;
        }

        Log::error('Table version increment failed after seed retry', [
            'table'       => $this->table,
            'version_key' => $this->versionKey,
        ]);

        $this->version = $this->tableVersion() + 1;
    }
}
