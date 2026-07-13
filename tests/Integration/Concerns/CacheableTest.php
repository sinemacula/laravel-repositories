<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Concerns\CacheSizeGuard;
use SineMacula\Repositories\Concerns\CacheStore;
use SineMacula\Repositories\Concerns\CacheStoreOptions;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Concerns\InteractsWithNonPublicMembers;
use Tests\Support\Criteria\NamedTagsCriterion;
use Tests\Support\Models\Tag;
use Tests\Support\Repositories\CacheableTagRepository;
use Tests\Support\Repositories\CustomPrefixCacheableTagRepository;
use Tests\Support\Repositories\CustomStoreCacheableTagRepository;
use Tests\Support\Repositories\ReferenceTableTagRepository;
use Tests\Support\Repositories\ShortTtlTagRepository;
use Tests\Support\Repositories\TunedCacheableTagRepository;

/**
 * Tests for the Cacheable trait.
 *
 * @SuppressWarnings("php:S1448")
 * @SuppressWarnings("php:S3011")
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(Cacheable::class)]
#[CoversTrait(ManagesCriteria::class)]
final class CacheableTest extends IntegrationTestCase
{
    use InteractsWithNonPublicMembers;

    /** @var string The resolved per-query metadata cache key for the tags table. */
    private const string META_KEY = 'repositories:repository-cache-meta:tags';

    /** @var \Tests\Support\Repositories\CacheableTagRepository The repository under test. */
    private CacheableTagRepository $repository;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');

        Tag::create(['name' => 'php']);
        Tag::create(['name' => 'laravel']);

        assert($this->app !== null);

        $this->repository = $this->app->make(CacheableTagRepository::class);
    }

    /**
     * Test that the first read populates the cache and returns results.
     *
     * @return void
     */
    public function testFirstReadPopulatesCacheAndReturnsResults(): void
    {
        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Collection::class, $result);
        self::assertCount(2, $result);
        self::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that the second read returns cached data without executing a new
     * database query.
     *
     * @return void
     */
    public function testSecondReadReturnsCachedDataWithoutDatabaseQuery(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Collection::class, $result);
        self::assertCount(2, $result);
    }

    /**
     * Test that a write operation flushes the cache.
     *
     * @return void
     */
    public function testWriteOperationFlushesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->repository->scopeById(1)->update(['name' => 'updated']); // @phpstan-ignore staticMethod.dynamicCall

        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a cache-store failure during the post-write flush is swallowed
     * and logged, and the already-committed write still returns its result
     * rather than surfacing the flush error to the caller (who could retry and
     * duplicate the record).
     *
     * @return void
     */
    public function testWriteSwallowsAndLogsAPostWriteFlushFailure(): void
    {
        // Arrange - a store whose flush write blows up (e.g. a cache outage
        // after the DB mutation has already committed).
        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('put')->andThrow(new \RuntimeException('cache down'));

        Cache::extend('throwing', fn (): Repository => new Repository($store));
        Config::set('cache.stores.throwing', ['driver' => 'throwing']);

        $failing = new CacheStore(Cache::store('throwing'), 'tags', new CacheStoreOptions(3600, new CacheSizeGuard(null, null), false, 0));
        $this->setProperty($this->repository, 'cacheStore', $failing);

        Log::shouldReceive('error')
            ->once()
            ->with('Cache flush after write failed', \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof \RuntimeException
                    && $context['exception']->getMessage() === 'cache down',
            ));

        // Act - the write commits; the flush throws but must not surface.
        $created = $this->repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        // Assert - the committed write returns its result despite the failure.
        self::assertInstanceOf(Tag::class, $created);
        self::assertSame('vue', $created->getAttribute('name'));
    }

    /**
     * Test that withoutCache bypasses the cache without invalidating it.
     *
     * @return void
     */
    public function testWithoutCacheBypassesCacheWithoutInvalidating(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $result = $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Collection::class, $result);
        self::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that withoutCache is transient and only applies to the next read.
     *
     * @return void
     */
    public function testWithoutCacheIsTransient(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $result);
    }

    /**
     * Test that flushCache clears populated cache.
     *
     * @return void
     */
    public function testFlushCacheClearsPopulatedCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->repository->flushCache();

        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that getCacheStatus returns accurate state transitions.
     *
     * @return void
     */
    public function testGetCacheStatusReturnsAccurateState(): void
    {
        self::assertFalse($this->repository->getCacheStatus()->isPopulated());

        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a custom TTL is respected and the cache expires.
     *
     * @return void
     */
    public function testCustomTtlIsRespected(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ShortTtlTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($repository->getCacheStatus()->isPopulated());

        $this->travel(6)->seconds();

        self::assertFalse($repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a custom cache store name is used.
     *
     * @return void
     */
    public function testCustomCacheStoreNameIsUsed(): void
    {
        Config::set('cache.stores.custom-test', [
            'driver' => 'array',
        ]);

        assert($this->app !== null);

        $repository = $this->app->make(CustomStoreCacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($repository->getCacheStatus()->isPopulated());

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        self::assertTrue($cacheManager->store('custom-test')->has(self::META_KEY));
    }

    /**
     * Test that a custom cache key prefix is used instead of the model table
     * name.
     *
     * @return void
     */
    public function testCustomCacheKeyPrefixIsUsed(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(CustomPrefixCacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        self::assertTrue($cacheManager->store('array')->has('repositories:repository-cache-meta:custom-prefix'));
    }

    /**
     * Test that withoutCache reads fresh data from the database while the
     * cached snapshot remains stale.
     *
     * @return void
     */
    public function testWithoutCacheReadsFreshDataFromDatabase(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $result = $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(3, $result);
    }

    /**
     * Test that boot invokes the parent boot chain so the subclass boot hook
     * and the cacheable concern are both initialized.
     *
     * @return void
     */
    public function testBootInvokesParentBootChain(): void
    {
        self::assertTrue($this->repository->booted);
        self::assertInstanceOf(CacheStore::class, $this->getProperty($this->repository, 'cacheStore'));
    }

    /**
     * Test that the default cache TTL is exactly one hour.
     *
     * @return void
     */
    public function testDefaultCacheTtlIsOneHour(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $this->travel(3599)->seconds();

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->travel(1)->seconds();

        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that the default cache key prefix uses the model table name.
     *
     * @return void
     */
    public function testDefaultCacheKeyPrefixUsesTableName(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $cacheKey = self::META_KEY;

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        self::assertTrue($cacheManager->store('array')->has($cacheKey));
    }

    /**
     * Test that distinct scoped reads resolve distinct cached rows rather than
     * colliding on a single whole-table entry.
     *
     * @return void
     */
    public function testDistinctScopedReadsResolveDistinctRows(): void
    {
        $one = $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall
        $two = $this->repository->scopeById(2)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertSame('php', $one?->getAttribute('name'));
        self::assertSame('laravel', $two?->getAttribute('name'));
    }

    /**
     * Test that a cached scoped read is served from the cache on repeat without
     * returning a different scope's rows.
     *
     * @return void
     */
    public function testCachedScopedReadIsStablePerScope(): void
    {
        $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        $repeat = $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertSame('php', $repeat?->getAttribute('name'));
    }

    /**
     * Test that a by-id read never returns the full-table collection from the
     * cache.
     *
     * @return void
     */
    public function testByIdReadNeverReturnsFullCollection(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $single = $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Tag::class, $single);
        self::assertSame('php', $single->getAttribute('name'));
    }

    /**
     * Test that find() does not collide across distinct ids. The by-id
     * constraint is applied at execution time - after the base builder is
     * fingerprinted - so without folding the verb arguments into the cache key
     * find(2) would be served the cached find(1) record.
     *
     * @return void
     */
    public function testFindDoesNotCollideAcrossDistinctIds(): void
    {
        $first  = $this->repository->find(1); // @phpstan-ignore staticMethod.dynamicCall
        $second = $this->repository->find(2); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Tag::class, $first);
        self::assertInstanceOf(Tag::class, $second);
        self::assertSame('php', $first->getAttribute('name'));
        self::assertSame('laravel', $second->getAttribute('name'));
    }

    /**
     * Test that a scalar value() read does not collide with a cached get(). The
     * two reads share an identical base builder, so without folding the verb
     * into the cache key value('name') would be served the cached get()
     * collection instead of the scalar column value.
     *
     * @return void
     */
    public function testScalarValueReadDoesNotCollideWithCachedGet(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $cached = $this->repository->value('name'); // @phpstan-ignore staticMethod.dynamicCall
        $fresh  = $this->repository->withoutCache()->value('name'); // @phpstan-ignore staticMethod.dynamicCall

        self::assertIsString($cached);     // A scalar column value, never the cached get() collection
        self::assertSame($fresh, $cached); // And the same value the database returns uncached
    }

    /**
     * Test that create() returning a Model invalidates the cache.
     *
     * @return void
     */
    public function testCreateReturningModelInvalidatesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $created = $this->repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Tag::class, $created);
        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a delete() write verb invalidates the cache.
     *
     * @return void
     */
    public function testDeleteInvalidatesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->repository->scopeById(1)->delete(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a fresh read repopulates the cache after a write invalidation.
     *
     * @return void
     */
    public function testReadAfterWriteRepopulatesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $this->repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(3, $result);
        self::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that the cached find returns a sentinel miss that is invalidated
     * once the missing record is created.
     *
     * @return void
     */
    public function testCachedFindMissIsInvalidatedOnWrite(): void
    {
        $missing = $this->repository->scopeById(999)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertNull($missing);

        $this->repository->create(['name' => 'svelte']); // @phpstan-ignore staticMethod.dynamicCall

        $created = Tag::query()->where('name', 'svelte')->first();

        $found = $this->repository->scopeById($created?->getAttribute('id'))->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Tag::class, $found);
    }

    /**
     * Test that a null/miss read is negatively cached, so a repeated read for
     * the same missing key is served from the cache without a database query.
     *
     * @return void
     */
    public function testMissingReadIsServedFromNegativeCacheWithoutRequery(): void
    {
        self::assertNull($this->repository->find(999)); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $this->repository->find(999); // @phpstan-ignore staticMethod.dynamicCall

        DB::disableQueryLog();

        self::assertNull($result);
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a cached read which throws leaves no dirty builder or scope
     * state behind, so the next cached read composes from a clean slate
     * instead of inheriting the failed call's constraints.
     *
     * @return void
     */
    public function testFailedCachedReadLeavesTransientStateClean(): void
    {
        $this->repository
            ->withCriteria(new NamedTagsCriterion('php'))
            ->addScope(static function (Builder $query): void {
                $query->where('id', '>', 0);
            });

        try {
            $this->repository->findOrFail(999); // @phpstan-ignore staticMethod.dynamicCall
            self::fail('Expected the cached findOrFail() to throw.');
        } catch (ModelNotFoundException) {
            // The dirty builder and scope must not survive the failure.
        }

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $result);
    }

    /**
     * Test that the row count used by the size guard is the collection size for
     * a collection, exactly one for a single model, and zero otherwise.
     *
     * @return void
     */
    public function testRowCountReflectsResultShape(): void
    {
        $rowCount = new \ReflectionMethod($this->repository, 'rowCount');

        self::assertSame(2, $rowCount->invoke($this->repository, new Collection(['a', 'b'])));
        self::assertSame(1, $rowCount->invoke($this->repository, new Tag));
        self::assertSame(0, $rowCount->invoke($this->repository, 'not-a-model'));
        self::assertSame(0, $rowCount->invoke($this->repository, null));
    }

    /**
     * Test that the reference-mode key argument is taken from the first
     * argument, defaults to zero, and preserves integer and string keys while
     * casting any other type to a string.
     *
     * @return void
     */
    public function testReferenceIdResolvesPrimaryKeyArgument(): void
    {
        $referenceId = new \ReflectionMethod($this->repository, 'referenceId');

        self::assertSame(5, $referenceId->invoke($this->repository, [5, 99]));
        self::assertSame('php', $referenceId->invoke($this->repository, ['php']));
        self::assertSame(0, $referenceId->invoke($this->repository, []));
        self::assertSame([1, 'two'], $referenceId->invoke($this->repository, [[1, 3.5, 'two']]));
        self::assertNull($referenceId->invoke($this->repository, [1.5]));
    }

    /**
     * Test that the repository's cache tuning properties take precedence over
     * the package configuration when resolving the store options.
     *
     * @return void
     */
    public function testTuningPropertiesTakePrecedenceOverConfig(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(TunedCacheableTagRepository::class);

        $resolveTtl          = new \ReflectionMethod($repository, 'resolveTtl');
        $resolveReferenceTtl = new \ReflectionMethod($repository, 'resolveReferenceTtl');
        $resolveNegativeTtl  = new \ReflectionMethod($repository, 'resolveNegativeTtl');
        $resolveStoreOptions = new \ReflectionMethod($repository, 'resolveStoreOptions');

        self::assertSame(120, $resolveTtl->invoke($repository));
        self::assertSame(240, $resolveReferenceTtl->invoke($repository));
        self::assertSame(30, $resolveNegativeTtl->invoke($repository));

        $options = $resolveStoreOptions->invoke($repository);

        self::assertInstanceOf(CacheStoreOptions::class, $options);
        self::assertSame(120, $options->ttl);
        self::assertSame(30, $options->negativeTtl);
        self::assertFalse($options->registryEnabled);
        self::assertSame(50, (new \ReflectionProperty(CacheSizeGuard::class, 'maxRows'))->getValue($options->sizeGuard));
        self::assertSame(2048, (new \ReflectionProperty(CacheSizeGuard::class, 'maxBytes'))->getValue($options->sizeGuard));
    }

    /**
     * Test that the per-query and reference cache TTLs fall back to one hour
     * when neither a repository property nor numeric configuration is present.
     *
     * @return void
     */
    public function testCacheTtlsFallBackToOneHourForNonNumericConfig(): void
    {
        assert($this->app !== null);

        Config::set('repositories.cache.ttl', 'not-numeric');
        Config::set('repositories.cache.reference_ttl', 'not-numeric');

        $repository = $this->app->make(CacheableTagRepository::class);

        self::assertSame(3600, (new \ReflectionMethod($repository, 'resolveTtl'))->invoke($repository));
        self::assertSame(3600, (new \ReflectionMethod($repository, 'resolveReferenceTtl'))->invoke($repository));
    }

    /**
     * Test that the negative-lookup TTL casts a numeric configuration value to
     * an int and falls back to ten seconds for a non-numeric value.
     *
     * @return void
     */
    public function testNegativeTtlCastsNumericConfigAndFallsBackForNonNumeric(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(CacheableTagRepository::class);
        $resolve    = new \ReflectionMethod($repository, 'resolveNegativeTtl');

        Config::set('repositories.cache.negative_ttl', '25');

        self::assertSame(25, $resolve->invoke($repository));

        Config::set('repositories.cache.negative_ttl', 'not-numeric');

        self::assertSame(10, $resolve->invoke($repository));
    }

    /**
     * Test that every tuning resolver falls back to its packaged default when
     * the configuration keys are absent entirely.
     *
     * @return void
     */
    public function testResolversFallBackToPackagedDefaultsWhenConfigIsAbsent(): void
    {
        assert($this->app !== null);

        Config::set('repositories.cache', []);

        $repository = $this->app->make(CacheableTagRepository::class);

        self::assertSame(3600, (new \ReflectionMethod($repository, 'resolveTtl'))->invoke($repository));
        self::assertSame(3600, (new \ReflectionMethod($repository, 'resolveReferenceTtl'))->invoke($repository));
        self::assertSame(10, (new \ReflectionMethod($repository, 'resolveNegativeTtl'))->invoke($repository));

        $options = (new \ReflectionMethod($repository, 'resolveStoreOptions'))->invoke($repository);

        self::assertInstanceOf(CacheStoreOptions::class, $options);
        self::assertTrue($options->registryEnabled);
        self::assertSame(1000, (new \ReflectionProperty(CacheSizeGuard::class, 'maxRows'))->getValue($options->sizeGuard));
        self::assertSame(262144, (new \ReflectionProperty(CacheSizeGuard::class, 'maxBytes'))->getValue($options->sizeGuard));
    }

    /**
     * Test that numeric string configuration values are cast to integers by
     * the tuning resolvers.
     *
     * @return void
     */
    public function testNumericStringTuningConfigIsCastToInteger(): void
    {
        assert($this->app !== null);

        Config::set('repositories.cache.ttl', '120');
        Config::set('repositories.cache.reference_ttl', '240');
        Config::set('repositories.cache.max_rows', '50');
        Config::set('repositories.cache.max_bytes', '2048');

        $repository = $this->app->make(CacheableTagRepository::class);

        self::assertSame(120, (new \ReflectionMethod($repository, 'resolveTtl'))->invoke($repository));
        self::assertSame(240, (new \ReflectionMethod($repository, 'resolveReferenceTtl'))->invoke($repository));

        $guard = (new \ReflectionMethod($repository, 'resolveSizeGuard'))->invoke($repository);

        self::assertInstanceOf(CacheSizeGuard::class, $guard);
        self::assertSame(50, (new \ReflectionProperty(CacheSizeGuard::class, 'maxRows'))->getValue($guard));
        self::assertSame(2048, (new \ReflectionProperty(CacheSizeGuard::class, 'maxBytes'))->getValue($guard));
    }

    /**
     * Test that the configured store name is preferred over the application's
     * default cache store when no repository property overrides it.
     *
     * @return void
     */
    public function testConfiguredStoreNameIsPreferredOverApplicationDefault(): void
    {
        Config::set('cache.stores.custom-test', ['driver' => 'array']);
        Config::set('repositories.cache.store', 'custom-test');

        assert($this->app !== null);

        $repository = $this->app->make(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        self::assertTrue($cacheManager->store('custom-test')->has(self::META_KEY));
        self::assertFalse($cacheManager->store('array')->has(self::META_KEY));
    }

    /**
     * Test that a repository's store name property takes precedence over the
     * configured store name.
     *
     * @return void
     */
    public function testStoreNamePropertyTakesPrecedenceOverConfiguredStoreName(): void
    {
        Config::set('cache.stores.custom-test', ['driver' => 'array']);
        Config::set('cache.stores.secondary', ['driver' => 'array']);
        Config::set('repositories.cache.store', 'secondary');

        assert($this->app !== null);

        $repository = $this->app->make(CustomStoreCacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        self::assertTrue($cacheManager->store('custom-test')->has(self::META_KEY));
        self::assertFalse($cacheManager->store('secondary')->has(self::META_KEY));
    }

    /**
     * Test that opting into reference mode serves mixed reads from a single
     * whole-table query rather than the per-query cache.
     *
     * @return void
     */
    public function testReferenceModeServesMixedReadsFromASingleQuery(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        DB::enableQueryLog();

        $all        = $repository->all();
        $found      = $repository->find(1); // @phpstan-ignore staticMethod.dynamicCall
        $collection = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertInstanceOf(Collection::class, $all);
        self::assertCount(2, $all);
        self::assertInstanceOf(Tag::class, $found);
        self::assertSame('php', $found->getAttribute('name'));
        self::assertInstanceOf(Collection::class, $collection);
        self::assertTrue($repository->getCacheStatus()->isPopulated());

        DB::disableQueryLog();
    }

    /**
     * Test that a reference-mode find() accepts an Arrayable of ids, mirroring
     * the array-of-ids shape rather than requiring a plain array.
     *
     * @return void
     */
    public function testReferenceModeFindAcceptsArrayableIds(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $found = $repository->find(new Collection([1, 2])); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Collection::class, $found);
        self::assertCount(2, $found);
    }

    /**
     * Test that non-reference verbs in reference mode execute through the
     * normal query pipeline rather than being served the whole-table snapshot.
     *
     * @return void
     */
    public function testReferenceModeServesNonReferenceVerbsThroughTheQueryPipeline(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $first = $repository->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Tag::class, $first);
    }

    /**
     * Test that a reference read consumes the one-shot criteria flags exactly
     * as a normal query pipeline would, so neither flag leaks onto a later,
     * unrelated read.
     *
     * @return void
     */
    public function testReferenceModeConsumesOneShotCriteriaFlags(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        // skipCriteria() is one-shot: once consumed, a criterion pushed
        // afterward must still be applied on the next read.
        $repository->skipCriteria();
        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->pushCriteria(new NamedTagsCriterion('php'));

        $filtered = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, $filtered);

        // useCriteria() is also one-shot: once consumed, disabled persistent
        // criteria must not be force-applied again on a later, unrelated read.
        $repository->disableCriteria();
        $repository->useCriteria();

        $forced = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, $forced);

        $unfiltered = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $unfiltered);
    }

    /**
     * Test that transient criteria force a real filtered query in reference
     * mode instead of the unfiltered snapshot.
     *
     * @return void
     */
    public function testReferenceModeExecutesRealQueryForTransientCriteria(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $filtered = $repository->withCriteria(new NamedTagsCriterion('php'))->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, $filtered);
    }

    /**
     * Test that active persistent criteria force a real filtered query in
     * reference mode instead of the unfiltered snapshot.
     *
     * @return void
     */
    public function testReferenceModeExecutesRealQueryForPersistentCriteria(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $repository->pushCriteria(new NamedTagsCriterion('php'));

        $filtered = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, $filtered);
    }

    /**
     * Test that skipped criteria leave reference reads on the snapshot path,
     * executing no query once the snapshot is warm.
     *
     * @return void
     */
    public function testReferenceModeServesSnapshotWhenCriteriaAreSkipped(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->pushCriteria(new NamedTagsCriterion('php'));
        $repository->skipCriteria();

        DB::enableQueryLog();

        $unfiltered = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());
        self::assertCount(2, $unfiltered);

        DB::disableQueryLog();
    }

    /**
     * Test that disabled persistent criteria leave reference reads on the
     * snapshot path, executing no query once the snapshot is warm.
     *
     * @return void
     */
    public function testReferenceModeServesSnapshotWhenCriteriaAreDisabled(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->pushCriteria(new NamedTagsCriterion('php'));
        $repository->disableCriteria();

        DB::enableQueryLog();

        $unfiltered = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());
        self::assertCount(2, $unfiltered);

        DB::disableQueryLog();
    }

    /**
     * Test that a one-shot skipCriteria() is still honoured when a
     * reference-mode find() falls back to a real query because the id
     * argument is an unsupported shape, and that the flag does not leak onto
     * a later, unrelated read.
     *
     * @return void
     */
    public function testReferenceModeSkipCriteriaAppliesToUnsupportedFindShapeFallback(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $repository->pushCriteria(new NamedTagsCriterion('laravel'));

        DB::enableQueryLog();

        $repository->skipCriteria()->find(1.5); // @phpstan-ignore staticMethod.dynamicCall

        self::assertNotContains('laravel', DB::getQueryLog()[0]['bindings']);

        DB::disableQueryLog();

        $filtered = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, $filtered);
    }

    /**
     * Test that a one-shot useCriteria() still forces disabled persistent
     * criteria on when a reference-mode find() falls back to a real query
     * because the id argument is an unsupported shape, and that the force
     * does not leak onto a later, unrelated read.
     *
     * @return void
     */
    public function testReferenceModeUseCriteriaAppliesToUnsupportedFindShapeFallback(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $repository->pushCriteria(new NamedTagsCriterion('laravel'));
        $repository->disableCriteria();

        DB::enableQueryLog();

        $repository->useCriteria()->find(1.5); // @phpstan-ignore staticMethod.dynamicCall

        self::assertContains('laravel', DB::getQueryLog()[0]['bindings']);

        DB::disableQueryLog();

        $unfiltered = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $unfiltered);
    }

    /**
     * Test that a read whose reference-mode id argument is an unsupported
     * shape logs the real-query fallback at debug level.
     *
     * @return void
     */
    public function testReferenceModeUnsupportedFindShapeLogsFallbackAtDebugLevel(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        Log::shouldReceive('debug')
            ->once()
            ->with('Reference cache bypassed for unsupported find argument', \Mockery::on(
                static fn (array $context): bool => $context['method'] === 'find'
                    && $context['arguments']                           === [1.5],
            ));

        $repository->find(1.5); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Provide the criteria-composition precedence branches that determine
     * whether the next query would differ from the unfiltered snapshot.
     *
     * @return iterable<string, array{0: \Closure(\Tests\Support\Repositories\ReferenceTableTagRepository): void, 1: bool}>
     */
    public static function compositionPrecedenceBranchProvider(): iterable
    {
        yield 'skipCriteria one-shot overrides pushed criteria' => [
            static function (ReferenceTableTagRepository $repository): void {
                $repository->pushCriteria(new NamedTagsCriterion('php'));
                $repository->skipCriteria();
            },
            false,
        ];

        yield 'forceUseCriteria one-shot forces disabled persistent criteria' => [
            static function (ReferenceTableTagRepository $repository): void {
                $repository->pushCriteria(new NamedTagsCriterion('php'));
                $repository->disableCriteria();
                $repository->useCriteria();
            },
            true,
        ];

        yield 'transient withCriteria is always applied' => [
            static function (ReferenceTableTagRepository $repository): void {
                $repository->withCriteria(new NamedTagsCriterion('php'));
            },
            true,
        ];

        yield 'pushed persistent criteria applies by default' => [
            static function (ReferenceTableTagRepository $repository): void {
                $repository->pushCriteria(new NamedTagsCriterion('php'));
            },
            true,
        ];

        yield 'an added scope is always applied' => [
            static function (ReferenceTableTagRepository $repository): void {
                $repository->addScope(static function (Builder $query): void {
                    $query->where('name', 'php');
                });
            },
            true,
        ];

        yield 'disabled persistent criteria without a force do not apply' => [
            static function (ReferenceTableTagRepository $repository): void {
                $repository->pushCriteria(new NamedTagsCriterion('php'));
                $repository->disableCriteria();
            },
            false,
        ];
    }

    /**
     * Test that reference-mode composition detection agrees with the real
     * applyCriteria() pipeline for every criteria precedence branch, locking
     * the mirror between Cacheable and ManagesCriteria in place.
     *
     * @param  \Closure(\Tests\Support\Repositories\ReferenceTableTagRepository): void  $arrange
     * @param  bool  $expectedPending
     * @return void
     */
    #[DataProvider('compositionPrecedenceBranchProvider')]
    public function testReferenceModeCompositionDetectionAgreesWithApplyCriteriaAcrossPrecedenceBranches(\Closure $arrange, bool $expectedPending): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $arrange($repository);

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $referencePending = DB::getQueryLog() !== [];

        DB::disableQueryLog();

        $arrange($repository);

        $filtered = $repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        $applyCriteriaPending = $filtered->count() !== 2;

        self::assertSame($expectedPending, $referencePending);
        self::assertSame($expectedPending, $applyCriteriaPending);
    }
}
