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
 * Stores per-query cache entries and invalidates them per table.
 *
 * A taggable store flushes the table tag. Otherwise every key embeds the
 * table's generation token, which a write replaces with a random one; a token
 * never repeats, so an orphaned entry can never become reachable again.
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
     * Invalidate every per-query cache entry for a table from outside its
     * repository.
     *
     * Unlike flushTable(), this leaves the table's status metadata alone.
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
     * Get the cached entry for a query fingerprint: null when absent, the miss
     * marker for a negative hit, otherwise the value.
     *
     * One fetch decides all three, so an entry expiring mid-read is never taken
     * for a negative hit.
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
     * Store a miss marker for a query fingerprint under the negative TTL.
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
            $this->generation = $this->publishGeneration();
        }

        $this->store->put($this->metaKey, ['invalidated_at' => now()->timestamp], $this->options->ttl);
    }

    /**
     * Get the current cache status, as the stored metadata records it.
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
     * Resolve the cache key for a query fingerprint, embedding the generation
     * token on a non-taggable store.
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
     * Resolve the table's generation token, memoised for this instance.
     *
     * A missing token is replaced rather than read as a fixed value, which
     * would revive entries stored before an earlier write.
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
     * Store a fresh generation token for the table and return it.
     *
     * The token is returned even when it cannot be stored, so this instance
     * stops serving the old entries either way.
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
