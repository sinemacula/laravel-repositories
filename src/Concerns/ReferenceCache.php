<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SineMacula\Repositories\Contracts\CacheInvalidator;
use SineMacula\Repositories\Enums\CacheKeys;

/**
 * Whole-table reference cache for repositories whose backing table is small,
 * static, and read in full.
 *
 * In reference mode the table is loaded once and served from memory: full
 * collection reads and single-record lookups by primary key resolve without
 * touching the database. The deserialized snapshot is memoised on the instance
 * and indexed by key, so repeated reads within a request neither re-hydrate the
 * cached payload nor scan it linearly. Whole-table caching semantics, including
 * cross-request persistence, are preserved as an explicit opt-in.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ReferenceCache implements CacheInvalidator
{
    /** @var string The resolved cache key for the whole-table snapshot. */
    private readonly string $cacheKey;

    /** @var string The resolved cache key for the reference metadata. */
    private readonly string $metaKey;

    /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>|null The request-local snapshot memo. */
    private ?Collection $memo = null;

    /** @var \Illuminate\Support\Collection<int|string, \Illuminate\Database\Eloquent\Model>|null The key-indexed snapshot for O(1) lookups. */
    private ?Collection $index = null;

    /**
     * Create a new reference cache instance.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $store
     * @param  string  $table
     * @param  int  $ttl
     * @param  \SineMacula\Repositories\Concerns\CacheSizeGuard  $sizeGuard
     * @return void
     */
    public function __construct(

        /** The underlying cache store instance. */
        private readonly CacheContract $store,

        /** The cache key prefix scoping the snapshot, qualified by the caller with the connection identity so two connections sharing a table name never share a snapshot. */
        private readonly string $table,

        /** The time-to-live, in seconds, for cached snapshots. */
        private readonly int $ttl,

        /** The guard that limits the size of cached snapshots. */
        private readonly CacheSizeGuard $sizeGuard,
    ) {
        $this->cacheKey = CacheKeys::REPOSITORY_CACHE->resolveKey([$this->table]);
        $this->metaKey  = CacheKeys::REPOSITORY_CACHE_META->resolveKey([$this->table]);
    }

    /**
     * Get the whole-table snapshot, loading it from the database on a miss.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function all(Model $model): Collection
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = $this->snapshot();

        return $cached !== null
            ? $this->remember($cached, $model)
            : $this->load($model);
    }

    /**
     * Find one or many records by key value from the whole-table snapshot.
     *
     * Mirrors Eloquent's find() contract: a scalar key resolves to a single
     * model or null, while an array of keys resolves to a collection of the
     * matching models.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<int, int|string>|int|string  $id
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>|null
     */
    public function find(Model $model, array|int|string $id): Collection|Model|null
    {
        $rows = $this->all($model);

        if (is_array($id)) {
            return $rows->whereIn($model->getKeyName(), $id)->values();
        }

        // Over-large tables are not memoised, so there is no key index; fall
        // back to scanning the freshly-queried collection.
        if ($this->index === null) {
            return $rows->firstWhere($model->getKeyName(), $id);
        }

        /** @var \Illuminate\Database\Eloquent\Model|null */
        return $this->index->get($id);
    }

    /**
     * Invalidate the whole-table snapshot.
     *
     * @return void
     */
    #[\Override]
    public function flushTable(): void
    {
        // The index must be cleared alongside the memo: a subsequent load that
        // trips the size guard skips remember(), so a surviving index would be
        // served stale by find().
        $this->memo  = null;
        $this->index = null;

        $this->store->forget($this->cacheKey);
        $this->store->put($this->metaKey, ['invalidated_at' => now()->timestamp], $this->ttl);
    }

    /**
     * Get the current reference cache status.
     *
     * @return \SineMacula\Repositories\Concerns\CacheStatus
     */
    public function getStatus(): CacheStatus
    {
        /** @var array{populated_at?: int, invalidated_at?: int}|null $meta */
        $meta      = $this->store->get($this->metaKey);
        $populated = $this->store->has($this->cacheKey);

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
     * Load the whole-table snapshot from the database, caching and memoising it
     * unless it exceeds the size guard.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private function load(Model $model): Collection
    {
        /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $rows */
        $rows = $model->newQuery()->get();

        // Skip caching a table over the size guard: reference mode on an
        // unexpectedly large table falls back to querying each read rather
        // than holding (or memoising) a huge serialized snapshot. Any earlier
        // memo state is dropped so no stale index can outlive the snapshot.
        if (!$this->sizeGuard->allows($rows, $rows->count())) {

            $this->memo  = null;
            $this->index = null;

            return $rows;
        }

        $this->store->put($this->cacheKey, $rows, $this->ttl);
        $this->store->put($this->metaKey, ['populated_at' => now()->timestamp], $this->ttl);

        return $this->remember($rows, $model);
    }

    /**
     * Memoise the snapshot on the instance and index it by key for O(1)
     * lookups, returning the snapshot to the caller.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private function remember(Collection $rows, Model $model): Collection
    {
        $this->memo  = $rows;
        $this->index = $rows->keyBy($model->getKeyName());

        return $rows;
    }

    /**
     * Get the cached whole-table snapshot, or null on a miss.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>|null
     */
    private function snapshot(): ?Collection
    {
        /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>|null */
        return $this->store->get($this->cacheKey);
    }
}
