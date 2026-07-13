<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use SineMacula\Repositories\Contracts\CacheInvalidator;

/**
 * Provides opt-in transparent caching for repositories.
 *
 * When used by a Repository subclass, this trait intercepts read operations
 * before execution and serves matching results from a per-query cache,
 * guaranteeing zero database queries on a cache hit. Each executed query is
 * keyed by its fingerprint, so a filtered or by-id read never returns the
 * full-table collection. Write operations invalidate the whole table.
 *
 * Overridable configuration properties (declare in the consuming class to
 * change defaults):
 *
 *   - `protected int $cacheTtl = 3600` - cache duration in seconds
 *   - `protected ?string $cacheStoreName = null` - Laravel cache store
 *   - `protected ?string $cacheKeyPrefix = null` - cache key prefix
 *   - `protected ?int $cacheMaxRows` - size guard row ceiling
 *   - `protected ?int $cacheMaxBytes` - size guard byte ceiling
 *   - `protected int $cacheReferenceTtl` - reference-mode cache duration
 *   - `protected ?int $cacheNegativeTtl` - null/miss cache duration
 *   - `protected bool $cacheReferenceTable = true` - opt into whole-table mode
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait Cacheable
{
    /** @var array<int, string> Read verbs whose results are served from the per-query cache. */
    private const array CACHEABLE_READS = ['get', 'find', 'first', 'firstWhere', 'firstOrFail', 'findOrFail', 'sole', 'value', 'pluck'];

    /** @var array<int, string> Write verbs that invalidate the whole-table cache after execution. */
    private const array WRITE_VERBS = [
        'create', 'forceCreate', 'firstOrCreate', 'createOrFirst', 'updateOrCreate', 'updateOrInsert',
        'update', 'updateFrom', 'delete', 'forceDelete', 'save', 'insert', 'insertGetId',
        'insertOrIgnore', 'insertUsing', 'insertOrIgnoreUsing', 'upsert', 'increment',
        'incrementEach', 'decrement', 'decrementEach', 'restore', 'touch', 'truncate',
    ];

    /** @var \SineMacula\Repositories\Concerns\CacheStore The per-query cache store collaborator. */
    private CacheStore $cacheStore;

    /** @var \SineMacula\Repositories\Concerns\ReferenceCache The whole-table reference cache collaborator. */
    private ReferenceCache $referenceCache;

    /** @var bool Whether the repository operates in whole-table reference mode. */
    private bool $cacheReferenceMode = false;

    /** @var bool Transient flag for bypassing the cache on the next read. */
    private bool $bypassCache = false;

    /**
     * Forward method calls to the model, applying cache interception.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[\Override]
    public function __call(string $method, array $arguments): mixed
    {
        if (in_array($method, self::WRITE_VERBS, true)) {
            return $this->forwardAndFlush($method, $arguments);
        }

        return $this->resolveRead($method, $arguments);
    }

    /**
     * Bypass the cache for the next read operation.
     *
     * @return static
     */
    public function withoutCache(): static
    {
        $this->bypassCache = true;

        return $this;
    }

    /**
     * Flush the repository cache.
     *
     * @return void
     */
    public function flushCache(): void
    {
        $this->activeStore()->flushTable();
    }

    /**
     * Get the current cache status.
     *
     * @return \SineMacula\Repositories\Concerns\CacheStatus
     */
    public function getCacheStatus(): CacheStatus
    {
        return $this->cacheReferenceMode
            ? $this->referenceCache->getStatus()
            : $this->cacheStore->getStatus();
    }

    /**
     * Boot the cacheable concern.
     *
     * Invoked by Repository::bootConcerns() rather than overriding boot()
     * directly, so the concern can coexist with other bootable concerns
     * without a fatal trait collision.
     *
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    protected function bootCacheable(): void
    {
        $table = $this->getModel()->getTable();
        $store = $this->resolveProperty('cacheStoreName') ?? Config::get('repositories.cache.store') ?? Config::get('cache.default');
        $store = is_string($store) ? $store : 'array';

        $prefix = $this->resolveProperty('cacheKeyPrefix') ?? $table;
        $prefix = is_string($prefix) ? $prefix : $table;

        $this->cacheReferenceMode = (bool) ($this->resolveProperty('cacheReferenceTable') ?? false);

        $this->cacheStore     = new CacheStore($store, $prefix, $this->resolveStoreOptions());
        $this->referenceCache = new ReferenceCache($store, $prefix, $this->resolveReferenceTtl(), $this->resolveSizeGuard());
    }

    /**
     * Resolve a read verb through the appropriate cache strategy.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function resolveRead(string $method, array $arguments): mixed
    {
        if ($this->shouldBypass()) {
            return parent::__call($method, $arguments);
        }

        if ($this->cacheReferenceMode) {
            return $this->isReferenceRead($method) && !$this->hasActiveComposition()
                ? $this->resolveReferenceRead($method, $arguments)
                : parent::__call($method, $arguments);
        }

        return in_array($method, self::CACHEABLE_READS, true)
            ? $this->resolveCachedRead($method, $arguments)
            : parent::__call($method, $arguments);
    }

    /**
     * Execute a write verb through the parent pipeline then flush the cache.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function forwardAndFlush(string $method, array $arguments): mixed
    {
        // A pending withoutCache() applies to the next read; consume it here so
        // it cannot leak past the write onto an unrelated later read.
        $this->bypassCache = false;

        $result = parent::__call($method, $arguments);

        try {
            $this->activeStore()->flushTable();
        } catch (\Throwable $exception) {
            // Best-effort: the write is already committed, so a cache-store
            // outage must not surface as a write failure (the caller could
            // retry and duplicate). Stale-until-TTL is the safe degraded state.
            Log::error('Cache flush after write failed', ['exception' => $exception]);
        }

        return $result;
    }

    /**
     * Resolve a cacheable read via pre-execution interception.
     *
     * The cache entry is resolved with a single fetch: an absent entry, a
     * negative-cache marker, and a cached value are distinguished in one round
     * trip, so an entry expiring mid-read can never be mistaken for a negative
     * hit. Reads whose arguments cannot be fingerprinted (e.g. closures)
     * execute uncached rather than risk colliding with another query's entry.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function resolveCachedRead(string $method, array $arguments): mixed
    {
        try {

            $query = $this->prepareQueryBuilder();

            try {
                $hash = QueryFingerprint::for($query, $method, $arguments);
            } catch (\Exception) {
                return parent::resetAndReturn(\Closure::fromCallable([$query, $method])(...$arguments));
            }

            $cached = $this->cacheStore->fetch($hash);

            if ($cached !== null) {
                return parent::resetAndReturn($cached instanceof CacheMiss ? null : $cached);
            }

            $result = \Closure::fromCallable([$query, $method])(...$arguments);

            if ($result !== null) {
                $this->cacheStore->put($hash, $result, $this->rowCount($result));
            } else {
                $this->cacheStore->putMiss($hash);
            }

            return parent::resetAndReturn($result);

        } catch (\Throwable $exception) {
            $this->resetAfterFailure();

            throw $exception;
        }
    }

    /**
     * Resolve a read from the whole-table reference cache.
     *
     * Consumes the one-shot criteria flags exactly as a normal query pipeline
     * would, so a skipCriteria()/useCriteria() intended for this read cannot
     * leak onto a later, unrelated query.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function resolveReferenceRead(string $method, array $arguments): mixed
    {
        $model = $this->getModel();

        $this->skipCriteria     = false;
        $this->forceUseCriteria = false;

        if ($method !== 'find') {
            return parent::resetAndReturn($this->referenceCache->all($model));
        }

        $id = $this->referenceId($arguments);

        if ($id === null) {
            return parent::__call($method, $arguments);
        }

        return parent::resetAndReturn($this->referenceCache->find($model, $id));
    }

    /**
     * Determine whether the given verb can be served from the reference cache.
     *
     * @param  string  $method
     * @return bool
     */
    private function isReferenceRead(string $method): bool
    {
        return in_array($method, ['get', 'find'], true);
    }

    /**
     * Determine whether repository-level query composition is pending, in
     * which case a reference read must execute a real query rather than serve
     * the unfiltered whole-table snapshot.
     *
     * Mirrors the applyCriteria() precedence: scopes always apply, skipped
     * criteria never apply, transient criteria always apply, and persistent
     * criteria apply unless disabled without an overriding useCriteria().
     *
     * @return bool
     */
    private function hasActiveComposition(): bool
    {
        if ($this->scopes !== []) {
            return true;
        }

        if ($this->skipCriteria) {
            return false;
        }

        return $this->transientCriteria->isNotEmpty()
            || (($this->forceUseCriteria || !$this->disableCriteria) && $this->persistentCriteria->isNotEmpty());
    }

    /**
     * Resolve the primary key argument for a reference-mode find().
     *
     * Returns null when the argument is not a supported key shape, signalling
     * the caller to fall back to a real query rather than coerce the value.
     *
     * @param  array<int, mixed>  $arguments
     * @return array<int, int|string>|int|string|null
     */
    private function referenceId(array $arguments): array|int|string|null
    {
        $id = $arguments[0] ?? 0;

        if ($id instanceof Arrayable) {
            $id = $id->toArray();
        }

        if (is_array($id)) {
            return array_values(array_filter($id, static fn (mixed $key): bool => is_int($key) || is_string($key)));
        }

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * Count the rows represented by a query result for the size guard.
     *
     * @param  mixed  $result
     * @return int
     */
    private function rowCount(mixed $result): int
    {
        if ($result instanceof Collection) {
            return $result->count();
        }

        return $result instanceof Model ? 1 : 0;
    }

    /**
     * Determine whether the next read should bypass the cache, consuming the
     * transient flag.
     *
     * @return bool
     */
    private function shouldBypass(): bool
    {
        if ($this->bypassCache) {

            $this->bypassCache = false;

            return true;
        }

        return false;
    }

    /**
     * Resolve the active cache store for invalidation.
     *
     * @return \SineMacula\Repositories\Contracts\CacheInvalidator
     */
    private function activeStore(): CacheInvalidator
    {
        return $this->cacheReferenceMode ? $this->referenceCache : $this->cacheStore;
    }

    /**
     * Resolve the configured cache TTL.
     *
     * @return int
     */
    private function resolveTtl(): int
    {
        $ttl = $this->resolveProperty('cacheTtl') ?? Config::get('repositories.cache.ttl', 3600);

        return is_numeric($ttl) ? (int) $ttl : 3600;
    }

    /**
     * Resolve the configured reference-mode cache TTL.
     *
     * @return int
     */
    private function resolveReferenceTtl(): int
    {
        $ttl = $this->resolveProperty('cacheReferenceTtl') ?? Config::get('repositories.cache.reference_ttl', 3600);

        return is_numeric($ttl) ? (int) $ttl : 3600;
    }

    /**
     * Resolve the configured negative-lookup (null/miss) cache TTL.
     *
     * @return int
     */
    private function resolveNegativeTtl(): int
    {
        $ttl = $this->resolveProperty('cacheNegativeTtl') ?? Config::get('repositories.cache.negative_ttl', 10);

        return is_numeric($ttl) ? (int) $ttl : 10;
    }

    /**
     * Resolve the per-query cache store options from properties and config.
     *
     * @return \SineMacula\Repositories\Concerns\CacheStoreOptions
     */
    private function resolveStoreOptions(): CacheStoreOptions
    {
        $registryEnabled = $this->resolveProperty('cacheRegistryEnabled')
            ?? Config::get('repositories.cache.registry_enabled', true);

        return new CacheStoreOptions($this->resolveTtl(), $this->resolveSizeGuard(), (bool) $registryEnabled, $this->resolveNegativeTtl());
    }

    /**
     * Build the size guard from the configured row and byte ceilings.
     *
     * @return \SineMacula\Repositories\Concerns\CacheSizeGuard
     */
    private function resolveSizeGuard(): CacheSizeGuard
    {
        $maxRows  = $this->resolveProperty('cacheMaxRows')  ?? Config::get('repositories.cache.max_rows', 1000);
        $maxBytes = $this->resolveProperty('cacheMaxBytes') ?? Config::get('repositories.cache.max_bytes', 262144);

        return new CacheSizeGuard(
            is_numeric($maxRows) ? (int) $maxRows : null,
            is_numeric($maxBytes) ? (int) $maxBytes : null,
        );
    }

    /**
     * Resolve an overridable property declared on the consuming repository.
     *
     * @param  string  $name
     * @return mixed
     */
    private function resolveProperty(string $name): mixed
    {
        // @phpstan-ignore property.dynamicName (guarded by property_exists; reads optional overridable config props)
        return property_exists($this, $name) ? $this->{$name} : null;
    }
}
