<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Cache\Repository as ConcreteRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SineMacula\Repositories\Contracts\CacheInvalidator;
use SineMacula\Repositories\Enums\CacheKeys;

/**
 * Encapsulates all interactions with the Laravel cache contract for managing
 * per-query repository cache entries and their invalidation.
 *
 * Each executed query is stored under a key derived from its fingerprint, so a
 * filtered or by-id read never returns the full-table collection. Invalidation
 * is performed per table - via cache tags when the store supports them, or via
 * a generation token otherwise: every per-query key embeds the table's current
 * token, and a write replaces it with a fresh random one in a single write.
 * Invalidation is therefore O(1), and since a token never repeats, neither
 * concurrent writes nor an evicted token can make an orphaned entry reachable
 * again; orphaned entries simply expire by TTL.
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

    /** @var string The resolved cache key holding the table's generation token. */
    private readonly string $generationKey;

    /** @var \Illuminate\Cache\Repository|null The concrete store when it supports tags, otherwise null. */
    private readonly ?ConcreteRepository $taggableStore;

    /** @var string|null The memoised generation token for the non-taggable scheme. */
    private ?string $generation = null;

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
        $this->generationKey = self::generationKeyFor($this->table);
    }

    /**
     * Invalidate every per-query cache entry for a table without a configured
     * store instance, for cross-cutting callers that only need to drop a
     * table's cache.
     *
     * This is the invalidation half of flushTable(): it flushes the table tag
     * on a taggable store, or replaces the generation token on a non-taggable
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

        $generationKey = self::generationKeyFor($table);

        if ($store->forever($generationKey, self::newGeneration())) {
            return;
        }

        Log::error('Table generation could not be replaced', [
            'store'          => $cacheStoreName,
            'table'          => $table,
            'generation_key' => $generationKey,
        ]);
    }

    /**
     * Get the cached entry for the given query fingerprint in a single round
     * trip.
     *
     * A negative entry is returned as the miss marker, so a caller can
     * distinguish an absent entry (null), a negative hit (CacheMiss), and a
     * cached value without a separate has() check - and without the window
     * where an entry expiring between the two calls masquerades as a negative
     * hit. An entry that does not read back, such as one an earlier version
     * stored as objects, is absent, so the read recomputes and overwrites it.
     *
     * @param  string  $hash
     * @return mixed
     */
    public function fetch(string $hash): mixed
    {
        return CachePayloadDecoder::decode($this->scopedStore()->get($this->keyFor($hash)));
    }

    /**
     * Store the given value for a query fingerprint, subject to the size guard.
     *
     * The value is stored as arrays and scalars. One holding an object that
     * cannot be stored that way is left uncached.
     *
     * @param  string  $hash
     * @param  mixed  $value
     * @param  int  $rows
     * @return void
     */
    public function put(string $hash, mixed $value, int $rows): void
    {
        $payload = CachePayloadEncoder::encode($value);

        if ($payload === null) {

            Log::debug('Query result holds a value the cache cannot store; read left uncached', [
                'table' => $this->table,
                'hash'  => $hash,
            ]);

            return;
        }

        if (!$this->options->sizeGuard->allows($payload, $rows)) {
            return;
        }

        $this->scopedStore()->put($this->keyFor($hash), $payload, $this->options->ttl);
        $this->store->put($this->metaKey, ['populated_at' => now()->timestamp], $this->options->ttl);
    }

    /**
     * Store a negative (null/miss) marker for a query fingerprint under the
     * shorter negative TTL.
     *
     * The marker is scoped to the table tag or generation like any other entry,
     * so a write still invalidates it; it bypasses the size guard because it is
     * a constant-size sentinel, and it does not touch the populated_at metadata
     * because it represents the absence of data rather than cached data.
     *
     * @param  string  $hash
     * @return void
     */
    public function putMiss(string $hash): void
    {
        $this->scopedStore()->put($this->keyFor($hash), CachePayloadEncoder::miss(), $this->options->negativeTtl);
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
            $this->rotateGeneration();
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
     * Mint a generation token no earlier entry can have been stored under.
     *
     * @return string
     */
    private static function newGeneration(): string
    {
        return Str::random(32);
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
     * Resolve the cache key holding a table's generation token.
     *
     * @param  string  $table
     * @return string
     */
    private static function generationKeyFor(string $table): string
    {
        return CacheKeys::REPOSITORY_CACHE_VERSION->resolveKey([$table]);
    }

    /**
     * Resolve the cache key for a query fingerprint.
     *
     * On taggable stores the tag handles invalidation, so the key is the bare
     * table/fingerprint pair. Otherwise the current generation token is folded
     * in, so replacing it orphans every previously stored key.
     *
     * @param  string  $hash
     * @return string
     */
    private function keyFor(string $hash): string
    {
        $scopedHash = $this->taggableStore !== null ? $hash : $this->generation() . ':' . $hash;

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
     * Resolve the table's current generation token, memoised for the lifetime
     * of this store instance.
     *
     * Only applies to non-taggable stores. The token is read once per instance
     * and held for the request, so a replacement elsewhere is invisible here
     * until this instance is discarded; orphaned entries still expire by TTL,
     * so the inconsistency is bounded and self-healing. A missing token, never
     * written or evicted, is replaced with a fresh one rather than read as a
     * fixed value, which would make the entries stored under it before an
     * earlier write reachable again.
     *
     * @return string
     */
    private function generation(): string
    {
        if ($this->generation !== null) {
            return $this->generation;
        }

        $generation = $this->store->get($this->generationKey);

        if (is_string($generation)) {
            return $this->generation = $generation;
        }

        return $this->generation = $this->publishGeneration();
    }

    /**
     * Replace the table's generation token, orphaning every existing per-query
     * key for the table in a single write.
     *
     * @return void
     */
    private function rotateGeneration(): void
    {
        $this->generation = $this->publishGeneration();
    }

    /**
     * Store a fresh generation token for the table and return it.
     *
     * The token is used by this instance even when the store cannot persist
     * it, so this instance still stops serving entries stored under the old
     * one; the failure is logged since other processes never observe the new
     * token. A store that throws rather than refusing the write takes the same
     * fallback.
     *
     * @return string
     */
    private function publishGeneration(): string
    {
        $generation = self::newGeneration();

        try {
            $published = $this->store->forever($this->generationKey, $generation);
        } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown

            Log::error('Table generation could not be stored due to a store error', [
                'table'          => $this->table,
                'generation_key' => $this->generationKey,
                'exception'      => $exception,
            ]);

            return $generation;
        }

        if (!$published) {
            Log::error('Table generation could not be replaced', [
                'table'          => $this->table,
                'generation_key' => $this->generationKey,
            ]);
        }

        return $generation;
    }
}
