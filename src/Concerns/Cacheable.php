<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SineMacula\Repositories\Contracts\CacheInvalidator;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;

/**
 * Provides opt-in caching for repository reads, keyed per executed query.
 *
 * A write invalidates every cached read of the table. Overridable configuration
 * properties (declare in the consuming class to change defaults):
 *
 *   - `protected int $cacheTtl = 3600` - cache duration in seconds
 *   - `protected ?string $cacheStoreName = null` - Laravel cache store
 *   - `protected ?string $cacheKeyPrefix = null` - cache key prefix
 *   - `protected ?int $cacheMaxRows` - size guard row ceiling
 *   - `protected ?int $cacheMaxBytes` - size guard byte ceiling
 *   - `protected int $cacheReferenceTtl` - reference-mode cache duration
 *   - `protected ?int $cacheNegativeTtl` - null/miss cache duration
 *   - `protected bool $cacheReferenceTable` - set true for whole-table mode
 *   - `protected bool $cacheRegistryEnabled = true` - non-taggable invalidation
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
     * Create a new query with active repository criteria and scopes applied.
     *
     * Consumes a pending withoutCache(); a raw builder never reads the cache.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @throws \Throwable
     */
    #[\Override]
    public function query(): Builder
    {
        $this->bypassCache = false;

        return parent::query();
    }

    /**
     * Return a copy whose next read bypasses the cache.
     *
     * @return static
     *
     * @phpstan-pure
     */
    public function withoutCache(): static
    {
        $composed = clone $this;

        // @phpstan-ignore impure.propertyAssign
        $composed->bypassCache = true;

        return $composed;
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

        // Two connections exposing the same table must not share a snapshot.
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
            return $this->isReferenceRead($method, $arguments) && !$this->hasActiveComposition()
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
        // A pending withoutCache() must not outlive the write.
        $this->bypassCache = false;

        $outcome = parent::__call($method, $arguments);

        try {
            $this->activeStore()->flushTable();
        } catch (\Throwable $exception) { // @phpstan-ignore catch.neverThrown
            // The write has committed; failing it now would invite a retry.
            Log::error('Cache flush after write failed', ['exception' => $exception]);
        }

        return $outcome;
    }

    /**
     * Resolve a cacheable read from the per-query cache.
     *
     * A read that cannot be fingerprinted runs uncached.
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

                return parent::resetAndReturn($this->forwardRead($query, $method, $arguments));
            }

            $cached = $this->cacheStore->fetch($hash);

            if ($cached !== null) {
                return parent::resetAndReturn($cached instanceof CacheMiss ? null : $cached);
            }

            $value = $this->forwardRead($query, $method, $arguments);

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
     * Forward a read verb to the prepared query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    private function forwardRead(Builder $query, string $method, array $arguments): mixed
    {
        $callable = [$query, $method];

        if (!is_callable($callable)) {
            throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', $query::class, $method));
        }

        return $callable(...$arguments);
    }

    /**
     * Resolve a read from the whole-table reference cache.
     *
     * The one-shot criteria flags are consumed only when the snapshot serves
     * the read; a fallback query consumes them itself.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Throwable
     */
    private function resolveReferenceRead(string $method, array $arguments): mixed
    {
        try {

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
        } catch (\Throwable $exception) {

            $this->resetAfterFailure();

            throw $exception;
        }
    }

    /**
     * Determine whether the given read can be served from the reference cache.
     *
     * The snapshot holds every column, so only a get() or find() selecting
     * every column, with no named or extra arguments, is served from it.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return bool
     */
    private function isReferenceRead(string $method, array $arguments): bool
    {
        if (!in_array($method, ['get', 'find'], true)) {
            return false;
        }

        $position = $method === 'find' ? 1 : 0;

        if (!array_is_list($arguments) || count($arguments) > $position + 1) {
            return false;
        }

        return !array_key_exists($position, $arguments) || in_array($arguments[$position], ['*', ['*']], true);
    }

    /**
     * Determine whether any criteria or scope would constrain the next query,
     * which the unfiltered snapshot cannot answer.
     *
     * @return bool
     */
    private function hasActiveComposition(): bool
    {
        return $this->scopes !== [] || $this->persistentScopes !== [] || $this->hasPendingComposition();
    }

    /**
     * Resolve the primary key argument for a reference-mode find(), or null
     * when it is not a supported key shape.
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
     * Determine whether the next read bypasses the cache, consuming the flag.
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
     * Resolve an overridable property declared on the consuming repository,
     * treating a value of a type no override takes as undeclared.
     *
     * @param  string  $name
     * @return bool|int|string|null
     */
    private function resolveProperty(string $name): bool|int|string|null
    {
        // @phpstan-ignore property.dynamicName
        $value = property_exists($this, $name) ? $this->{$name} : null;

        return is_bool($value) || is_int($value) || is_string($value) ? $value : null;
    }
}
