<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SineMacula\Repositories\Contracts\CacheInvalidator;
use SineMacula\Repositories\Enums\CacheKeys;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;

/**
 * Whole-table reference cache for repositories whose backing table is small,
 * static, and read in full.
 *
 * In reference mode the table is loaded once and served from memory: full
 * collection reads and single-record lookups by primary key resolve without
 * touching the database. The rebuilt snapshot is memoised on the instance and
 * indexed by key, so repeated reads within a request neither re-hydrate the
 * cached payload nor scan it linearly. Whole-table caching semantics, including
 * cross-request persistence, are preserved as an explicit opt-in.
 *
 * The model's global scopes apply to the snapshot query, so each variant they
 * produce (a tenant, say) is keyed by its query fingerprint and held as its own
 * snapshot. Every snapshot key embeds a generation token that a write replaces,
 * so one write invalidates every variant at once.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ReferenceCache implements CacheInvalidator
{
    /** @var string The resolved cache key for the reference metadata. */
    private readonly string $metaKey;

    /** @var string The resolved cache key holding the snapshot generation token. */
    private readonly string $generationKey;

    /** @var array<string, array{\Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>, \Illuminate\Support\Collection<int|string, \Illuminate\Database\Eloquent\Model>}> The request-local snapshots and their key indexes, by query fingerprint. */
    private array $memo = [];

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
        $this->metaKey       = CacheKeys::REPOSITORY_CACHE_META->resolveKey([$this->table]);
        $this->generationKey = CacheKeys::REPOSITORY_CACHE_VERSION->resolveKey([$this->table]);
    }

    /**
     * Get the whole-table snapshot, loading it from the database on a miss.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>
     *
     * @phpstan-impure
     */
    public function all(Model $model): Collection
    {
        return $this->resolve($model)[0];
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
     *
     * @phpstan-impure
     */
    public function find(Model $model, array|int|string $id): Collection|Model|null
    {
        [$rows, $index] = $this->resolve($model);

        if (is_array($id)) {
            return $rows->whereIn($model->getKeyName(), $id)->values();
        }

        // A snapshot that is not memoised has no key index; scan the freshly
        // queried collection instead.
        if ($index === null) {
            return $rows->firstWhere($model->getKeyName(), $id);
        }

        /** @var \Illuminate\Database\Eloquent\Model|null */
        return $index->get($id);
    }

    /**
     * Invalidate every snapshot of the table.
     *
     * The generation token is replaced rather than the snapshots forgotten,
     * since a snapshot per scope variant leaves no fixed set of keys to forget.
     * A fresh random token, unlike a counter, never returns to a value an
     * earlier snapshot was stored under, so concurrent writes on a store
     * without atomic increments cannot make a stale snapshot reachable again.
     *
     * @return void
     */
    #[\Override]
    public function flushTable(): void
    {
        $this->memo = [];

        if (!$this->store->forever($this->generationKey, Str::random(32))) {
            Log::error('Reference snapshot generation could not be replaced', [
                'table'          => $this->table,
                'generation_key' => $this->generationKey,
            ]);
        }

        $this->store->put($this->metaKey, ['invalidated_at' => now()->timestamp], $this->ttl);
    }

    /**
     * Get the current reference cache status.
     *
     * The status reflects stored metadata rather than a guaranteed snapshot:
     * the store can evict a snapshot without going through flushTable().
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
     * Resolve the snapshot for the model's current scope variant, with its key
     * index when it is memoised.
     *
     * A variant that cannot be fingerprinted is queried on every read and never
     * stored, since no key would keep it apart from another variant.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array{\Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>, \Illuminate\Support\Collection<int|string, \Illuminate\Database\Eloquent\Model>|null}
     */
    private function resolve(Model $model): array
    {
        $query = $model->newQuery();

        try {
            $fingerprint = QueryFingerprint::for($query);
        } catch (UnfingerprintableQueryException $exception) {

            Log::debug('Reference snapshot fingerprinting unavailable; executing read uncached', [
                'table'     => $this->table,
                'exception' => $exception,
            ]);

            return [$query->get(), null];
        }

        return $this->memo[$fingerprint] ?? $this->load($fingerprint, $query, $model);
    }

    /**
     * Load the snapshot for a scope variant from the cache, or from the
     * database on a miss, memoising it with its key index.
     *
     * A snapshot over the size guard, or one holding a value the cache cannot
     * store, is neither cached nor memoised, so each read queries for it.
     *
     * @param  string  $fingerprint
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array{\Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>, \Illuminate\Support\Collection<int|string, \Illuminate\Database\Eloquent\Model>|null}
     */
    private function load(string $fingerprint, Builder $query, Model $model): array
    {
        $key  = CacheKeys::REPOSITORY_CACHE->resolveKey([$this->table . ':' . $this->generation() . ':' . $fingerprint]);
        $rows = $this->snapshot($key);

        if ($rows === null) {

            /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $rows */
            $rows    = $query->get();
            $payload = CachePayloadEncoder::encode($rows);

            if ($payload === null || !$this->sizeGuard->allows($payload, $rows->count())) {
                return [$rows, null];
            }

            $this->store->put($key, $payload, $this->ttl);
            $this->store->put($this->metaKey, ['populated_at' => now()->timestamp], $this->ttl);
        }

        return $this->memo[$fingerprint] = [$rows, $rows->keyBy($model->getKeyName())];
    }

    /**
     * Get the current generation token, or an empty one before the first write.
     *
     * @return string
     */
    private function generation(): string
    {
        $generation = $this->store->get($this->generationKey);

        return is_string($generation) ? $generation : '';
    }

    /**
     * Get the cached snapshot stored under the given key, or null on a miss.
     *
     * A snapshot that does not read back as a collection, such as one an
     * earlier version stored as objects, is a miss, so the table is loaded
     * again and the snapshot overwritten.
     *
     * @param  string  $key
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>|null
     */
    private function snapshot(string $key): ?Collection
    {
        $snapshot = CachePayloadDecoder::decode($this->store->get($key));

        /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>|null */
        return $snapshot instanceof Collection ? $snapshot : null;
    }
}
