<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SineMacula\Repositories\Contracts\CacheInvalidator;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;

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
 *   - `protected bool $cacheRegistryEnabled = true` - non-taggable version bump
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
        'create', 'createQuietly', 'forceCreate', 'forceCreateQuietly', 'firstOrCreate', 'createOrFirst',
        'updateOrCreate', 'updateOrInsert', 'update', 'updateFrom', 'delete', 'forceDelete', 'save',
        'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'insertOrIgnoreUsing', 'upsert',
        'increment', 'incrementEach', 'incrementOrCreate', 'decrement', 'decrementEach', 'restore',
        'touch', 'truncate',
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
        $model = $this->getModel();
        $table = $model->getTable();

        $configuration = CacheConfiguration::resolveFor([
            'cacheStoreName'       => $this->resolveProperty('cacheStoreName'),
            'cacheKeyPrefix'       => $this->resolveProperty('cacheKeyPrefix'),
            'cacheReferenceTable'  => $this->resolveProperty('cacheReferenceTable'),
            'cacheTtl'             => $this->resolveProperty('cacheTtl'),
            'cacheReferenceTtl'    => $this->resolveProperty('cacheReferenceTtl'),
            'cacheNegativeTtl'     => $this->resolveProperty('cacheNegativeTtl'),
            'cacheMaxRows'         => $this->resolveProperty('cacheMaxRows'),
            'cacheMaxBytes'        => $this->resolveProperty('cacheMaxBytes'),
            'cacheRegistryEnabled' => $this->resolveProperty('cacheRegistryEnabled'),
        ], $table);

        $store = Cache::store($configuration->storeName);

        $this->cacheReferenceMode = $configuration->referenceMode;

        // The reference-mode snapshot key is qualified with the connection
        // identity (unlike the per-query key, which folds it into the query
        // fingerprint instead), so two connections exposing the same table
        // name never share a whole-table snapshot.
        $connection      = $model->getConnection();
        $referencePrefix = $configuration->prefix . ':' . $connection->getName() . ':' . $connection->getDatabaseName();

        $this->cacheStore     = new CacheStore($store, $configuration->prefix, $configuration->storeOptions);
        $this->referenceCache = new ReferenceCache($store, $referencePrefix, $configuration->referenceTtl, $configuration->storeOptions->sizeGuard);
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

        $outcome = parent::__call($method, $arguments);

        try {
            $this->activeStore()->flushTable();
        } catch (\Throwable $exception) {
            // Best-effort: the write is already committed, so a cache-store
            // outage must not surface as a write failure (the caller could
            // retry and duplicate). Stale-until-TTL is the safe degraded state.
            Log::error('Cache flush after write failed', ['exception' => $exception]);
        }

        return $outcome;
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
     * @throws \Throwable
     */
    private function resolveCachedRead(string $method, array $arguments): mixed
    {
        try {

            $query = $this->prepareQueryBuilder();

            try {
                $hash = QueryFingerprint::for($query, $method, $arguments);
            } catch (UnfingerprintableQueryException $exception) {
                Log::debug('Query fingerprinting unavailable; executing read uncached', [
                    'method'    => $method,
                    'exception' => $exception,
                ]);

                return parent::resetAndReturn(\Closure::fromCallable([$query, $method])(...$arguments));
            }

            $cached = $this->cacheStore->fetch($hash);

            if ($cached !== null) {
                return parent::resetAndReturn($cached instanceof CacheMiss ? null : $cached);
            }

            $value = \Closure::fromCallable([$query, $method])(...$arguments);

            if ($value !== null) {
                $this->cacheStore->put($hash, $value, $this->rowCount($value));
            } else {
                $this->cacheStore->putMiss($hash);
            }

            return parent::resetAndReturn($value);
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
     * leak onto a later, unrelated query. The flags are only consumed on the
     * branches that actually serve the snapshot: an unsupported find() id
     * falls back to the real query pipeline instead, which must see the
     * flags untouched so parent::__call() can consume them itself.
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

        if ($method === 'find') {

            $id = $this->referenceId($arguments);

            if ($id === null) {

                Log::debug('Reference cache bypassed for unsupported find argument', [
                    'method'    => $method,
                    'arguments' => $arguments,
                ]);

                return parent::__call($method, $arguments);
            }

            $this->skipCriteria     = false;
            $this->forceUseCriteria = false;

            return parent::resetAndReturn($this->referenceCache->find($model, $id));
        }

        $this->skipCriteria     = false;
        $this->forceUseCriteria = false;

        return parent::resetAndReturn($this->referenceCache->all($model));
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
     * Scopes are Cacheable's own concern; the criteria precedence itself is
     * owned by ManagesCriteria::hasPendingComposition() so applyCriteria()
     * and this check can never silently diverge.
     *
     * @return bool
     */
    private function hasActiveComposition(): bool
    {
        return $this->scopes !== [] || $this->hasPendingComposition();
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
     * @param  mixed  $value
     * @return int
     */
    private function rowCount(mixed $value): int
    {
        if ($value instanceof Collection) {
            return $value->count();
        }

        return $value instanceof Model ? 1 : 0;
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
