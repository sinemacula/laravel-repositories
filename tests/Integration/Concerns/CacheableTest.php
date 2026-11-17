<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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
use SineMacula\Repositories\Concerns\QueryFingerprint;
use SineMacula\Repositories\Concerns\ReferenceCache;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Builders\InertBuilder;
use Tests\Support\Concerns\InteractsWithNonPublicMembers;
use Tests\Support\Criteria\NamedTagsCriterion;
use Tests\Support\Models\Tag;
use Tests\Support\Repositories\ByteSizeGuardedTagRepository;
use Tests\Support\Repositories\CacheableInertBuilderRepository;
use Tests\Support\Repositories\CacheableTagRepository;
use Tests\Support\Repositories\CustomPrefixCacheableTagRepository;
use Tests\Support\Repositories\CustomStoreCacheableTagRepository;
use Tests\Support\Repositories\MistypedOverrideTagRepository;
use Tests\Support\Repositories\ReferenceTableTagRepository;
use Tests\Support\Repositories\RegistryDisabledFileStoreTagRepository;
use Tests\Support\Repositories\ScopedReferenceTableTagRepository;
use Tests\Support\Repositories\ShortReferenceTtlTagRepository;
use Tests\Support\Repositories\ShortTtlTagRepository;
use Tests\Support\Repositories\SizeGuardedTagRepository;
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

    /** @var string The per-query metadata cache key for the tags table. */
    private const string META_KEY = 'repositories:repository-cache-meta:tags';

    /** @var string The message carried by a failing cache store. */
    private const string STORE_FAILURE = 'cache down';

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
     * Test that a second read is served from the cache.
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
     * Test that a cacheable read refuses a verb the prepared builder cannot
     * take.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testCachedReadRefusesAVerbThePreparedBuilderCannotTake(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage(sprintf('Call to undefined method %s::get()', InertBuilder::class));

        self::assertNotNull($this->app);

        $this->app->make(CacheableInertBuilderRepository::class)->__call('get', []);
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
     * Test that a failed post-write flush is logged and swallowed, so the
     * committed write still returns and a retrying caller cannot duplicate it.
     *
     * @return void
     */
    public function testWriteSwallowsAndLogsAPostWriteFlushFailure(): void
    {
        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('put')->andThrow(new \RuntimeException(self::STORE_FAILURE)); // @phpstan-ignore method.notFound

        Cache::extend('throwing', fn (): Repository => new Repository($store));
        Config::set('cache.stores.throwing', ['driver' => 'throwing']);

        $failing = new CacheStore(Cache::store('throwing'), 'tags', new CacheStoreOptions(3600, new CacheSizeGuard(null, null), false, 0));
        $this->setProperty($this->repository, 'cacheStore', $failing);

        Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof \RuntimeException
                    && $context['exception']->getMessage() === self::STORE_FAILURE,
            ));

        $created = $this->repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

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

        Tag::create(['name' => 'vue']);

        $result = $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(3, $result);
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
     * Test that getCacheStatus reports whether the cache is populated.
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
     * @return iterable<string, array{class-string<\Tests\Support\Repositories\ShortReferenceTtlTagRepository|\Tests\Support\Repositories\ShortTtlTagRepository>}>
     */
    public static function shortTtlRepositories(): iterable
    {
        yield 'per-query' => [ShortTtlTagRepository::class];
        yield 'reference' => [ShortReferenceTtlTagRepository::class];
    }

    /**
     * Test that a repository's TTL override expires the cache.
     *
     * @param  class-string<\Tests\Support\Repositories\ShortReferenceTtlTagRepository|\Tests\Support\Repositories\ShortTtlTagRepository>  $class
     * @return void
     */
    #[DataProvider('shortTtlRepositories')]
    public function testTtlOverrideExpiresTheCache(string $class): void
    {
        assert($this->app !== null);

        $repository = $this->app->make($class);

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
     * Test that withoutCache reads fresh data from the database.
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
     * Test that boot still runs the subclass boot hook.
     *
     * @return void
     */
    public function testBootInvokesParentBootChain(): void
    {
        self::assertTrue($this->repository->booted);
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
     * Test that distinct scoped reads are cached apart.
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
     * Test that a repeated scoped read is served its own scope's rows.
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
     * Test that a by-id read is never served the cached full-table collection.
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
     * Test that find() caches distinct ids apart, although the id constraint is
     * applied after the builder is fingerprinted.
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
     * Test that value() and get() over the same builder are cached apart.
     *
     * @return void
     */
    public function testScalarValueReadDoesNotCollideWithCachedGet(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $cached = $this->repository->value('name'); // @phpstan-ignore staticMethod.dynamicCall
        $fresh  = $this->repository->withoutCache()->value('name'); // @phpstan-ignore staticMethod.dynamicCall

        self::assertIsString($cached);
        self::assertSame($fresh, $cached);
    }

    /**
     * Test that create() invalidates the cache.
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
     * Test that delete() invalidates the cache.
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
     * Test that a cached miss is invalidated once the record is created.
     *
     * @return void
     */
    public function testCachedFindMissIsInvalidatedOnWrite(): void
    {
        $missing = $this->repository->scopeById(999)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertNull($missing);

        $this->repository->create(['name' => 'svelte']); // @phpstan-ignore staticMethod.dynamicCall

        $created = Tag::query()->where('name', 'svelte')->first();

        $found = $this->repository->scopeById($created?->id)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Tag::class, $found);
    }

    /**
     * Test that a repeated read of a missing key is served from the cache.
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
     * Test that a cached read which throws leaves no criteria or scopes behind
     * for the next read to inherit.
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
        } catch (ModelNotFoundException $exception) {
            self::assertSame(Tag::class, $exception->getModel());
        }

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $result);
    }

    /**
     * Test that a registered scope keeps reference reads on a real query, since
     * the snapshot is built straight from the model.
     *
     * @return void
     */
    public function testRegisteredScopeKeepsAReferenceReadOffTheSnapshot(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ScopedReferenceTableTagRepository::class);

        $tags = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, $tags);
        self::assertSame('php', $tags->first()?->getAttribute('name'));
        self::assertCount(1, $repository->get()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * @return iterable<string, array{\Closure(\Tests\Support\Repositories\CacheableTagRepository): object}>
     */
    public static function cacheBypasses(): iterable
    {
        yield 'discarded' => [static fn (CacheableTagRepository $repository): object => $repository->withoutCache()];
        yield 'composed' => [static fn (CacheableTagRepository $repository): object => $repository->withoutCache()->query()];
    }

    /**
     * Test that a withoutCache() copy, discarded or composed, never uncaches a
     * later read through the instance it came from.
     *
     * @param  \Closure(\Tests\Support\Repositories\CacheableTagRepository): object  $bypass
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('cacheBypasses')]
    public function testCacheBypassNeverReachesTheInstance(\Closure $bypass): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $bypass($this->repository);

        DB::enableQueryLog();

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::disableQueryLog();

        self::assertCount(2, $result);
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a reference read which throws leaves no transient criteria
     * behind to filter the next read.
     *
     * @return void
     */
    public function testFailedReferenceReadLeavesTransientStateClean(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('get')->andThrow(new \RuntimeException(self::STORE_FAILURE)); // @phpstan-ignore method.notFound

        Cache::extend('throwing-reference', fn (): Repository => new Repository($store));
        Config::set('cache.stores.throwing-reference', ['driver' => 'throwing-reference']);

        $this->setProperty($repository, 'referenceCache', new ReferenceCache(Cache::store('throwing-reference'), 'tags', 3600, new CacheSizeGuard(null, null)));

        // Skipping hides the criterion from the composition check, so the read
        // takes the snapshot branch and the store failure strands it.
        $repository = $repository->withCriteria(new NamedTagsCriterion('php'))->skipCriteria();

        try {

            $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
            self::fail('Expected the reference read to propagate the store failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame(self::STORE_FAILURE, $exception->getMessage());
        }

        $this->setProperty($repository, 'referenceCache', new ReferenceCache(Cache::store('array'), 'tags', 3600, new CacheSizeGuard(null, null)));

        self::assertCount(2, $repository->get()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * @return iterable<string, array{string, array<int, int|string>}>
     */
    public static function readsWithinAOneRowCeiling(): iterable
    {
        yield 'single model' => ['find', [1]];
        yield 'scalar value' => ['value', ['name']];
    }

    /**
     * Test that the size guard counts a single model as one row and a scalar as
     * none, so both are cached under a one-row ceiling.
     *
     * @param  string  $method
     * @param  array<int, int|string>  $arguments
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[DataProvider('readsWithinAOneRowCeiling')]
    public function testReadsWithinTheRowCeilingAreCached(string $method, array $arguments): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(SizeGuardedTagRepository::class);

        $repository->__call($method, $arguments);

        DB::enableQueryLog();

        $repository->__call($method, $arguments);

        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that a reference-mode find() of many ids drops any id that is not an
     * int or string.
     *
     * @return void
     */
    public function testReferenceModeFindDropsUnsupportedIds(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $found = $repository->find([1, 3.5, 2]); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Collection::class, $found);
        self::assertCount(2, $found);
    }

    /**
     * Test that the configured store is preferred over the application default.
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
     * Test that a repository's store name overrides the configured store.
     *
     * @return void
     */
    public function testStoreNamePropertyOverridesConfiguredStore(): void
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
     * Test that reference mode serves mixed reads from one whole-table query.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
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
     * @return iterable<string, array{string, array<int, array<int, int|string>|int|string>}>
     */
    public static function referenceReadsSelectingEveryColumn(): iterable
    {
        yield 'get with no column list' => ['get', []];
        yield 'get with a wildcard string' => ['get', ['*']];
        yield 'get with a wildcard list' => ['get', [['*']]];
        yield 'find with no column list' => ['find', [1]];
        yield 'find with a wildcard string' => ['find', [1, '*']];
        yield 'find with a wildcard list' => ['find', [1, ['*']]];
    }

    /**
     * Test that a reference read selecting every column is served from the
     * snapshot, however the column list is written.
     *
     * @param  string  $method
     * @param  array<int, array<int, int|string>|int|string>  $arguments
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[DataProvider('referenceReadsSelectingEveryColumn')]
    public function testReferenceModeServesFullColumnReadsFromTheSnapshot(string $method, array $arguments): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);
        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        self::assertNotNull($repository->__call($method, $arguments));
        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * @return iterable<string, array{string, array<array-key, array<int, int|string>|int|string>, array<int, string>}>
     */
    public static function referenceReadsTheSnapshotCannotServe(): iterable
    {
        $every = ['id', 'name', 'created_at', 'updated_at'];

        yield 'get with a column list' => ['get', [['id']], ['id']];
        yield 'get with a column string' => ['get', ['id'], ['id']];
        yield 'get with a named column list' => ['get', ['columns' => ['id']], ['id']];
        yield 'get with an extra argument' => ['get', [['*'], 'extra'], $every];
        yield 'find with a column list' => ['find', [1, ['id']], ['id']];
        yield 'find of many with a column list' => ['find', [[1, 2], ['id']], ['id']];
        yield 'find with a named column list' => ['find', ['id' => 1, 'columns' => ['id']], ['id']];
    }

    /**
     * Test that a reference read selecting only some columns, or with an
     * argument shape the snapshot does not recognise, runs a real query.
     *
     * @param  string  $method
     * @param  array<array-key, array<int, int|string>|int|string>  $arguments
     * @param  array<int, string>  $attributes
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[DataProvider('referenceReadsTheSnapshotCannotServe')]
    public function testReferenceModeQueriesReadsTheSnapshotCannotServe(string $method, array $arguments, array $attributes): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);
        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->__call($method, $arguments);
        $models = $result instanceof Tag ? [$result] : ($result instanceof Collection ? $result->all() : []);

        self::assertCount(1, DB::getQueryLog());
        self::assertNotEmpty($models);

        foreach ($models as $model) {
            self::assertInstanceOf(Tag::class, $model);
            self::assertSame($attributes, array_keys($model->getAttributes()));
        }

        DB::disableQueryLog();
    }

    /**
     * Test that an explicit null column list reaches the real query, which
     * rejects it, rather than being answered with every column.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testReferenceModeLeavesAnExplicitNullColumnListToTheQuery(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);
        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $this->expectException(QueryException::class);

        $repository->__call('get', [null]);
    }

    /**
     * Test that a reference-mode find() accepts an Arrayable of ids.
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
     * Test that reference mode runs verbs the snapshot does not serve as normal
     * queries.
     *
     * @return void
     */
    public function testReferenceModeQueriesNonReferenceVerbs(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $first = $repository->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Tag::class, $first);
    }

    /**
     * Test that a reference read consumes the one-shot criteria flags, so
     * neither leaks onto a later read.
     *
     * @return void
     */
    public function testReferenceModeConsumesOneShotCriteriaFlags(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $repository = $repository->skipCriteria();
        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->pushCriteria(new NamedTagsCriterion('php'));

        $filtered = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, $filtered);

        $repository->disableCriteria();
        $repository = $repository->useCriteria();

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
        $repository = $repository->skipCriteria();

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
     * Test that skipCriteria() applies to a reference find() that falls back to
     * a real query, without leaking onto a later read.
     *
     * @return void
     */
    public function testSkipCriteriaAppliesToReferenceFindFallback(): void
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
     * Test that useCriteria() applies to a reference find() that falls back to
     * a real query, without leaking onto a later read.
     *
     * @return void
     */
    public function testUseCriteriaAppliesToReferenceFindFallback(): void
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
     * Test that a reference find() with an unsupported id logs its fallback at
     * debug level.
     *
     * @return void
     */
    public function testReferenceFindFallbackLogsAtDebugLevel(): void
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
     * Provide each criteria precedence branch and whether it filters the query.
     *
     * @return iterable<string, array{0: \Closure(\Tests\Support\Repositories\ReferenceTableTagRepository): \Tests\Support\Repositories\ReferenceTableTagRepository, 1: bool}>
     */
    public static function compositionPrecedenceBranchProvider(): iterable
    {
        yield 'skipCriteria one-shot overrides pushed criteria' => [
            static function (ReferenceTableTagRepository $repository): ReferenceTableTagRepository {
                $repository->pushCriteria(new NamedTagsCriterion('php'));

                return $repository->skipCriteria();
            },
            false,
        ];

        yield 'forceUseCriteria one-shot forces disabled persistent criteria' => [
            static function (ReferenceTableTagRepository $repository): ReferenceTableTagRepository {
                $repository->pushCriteria(new NamedTagsCriterion('php'));
                $repository->disableCriteria();

                return $repository->useCriteria();
            },
            true,
        ];

        yield 'transient withCriteria is always applied' => [
            static fn (ReferenceTableTagRepository $repository): ReferenceTableTagRepository => $repository->withCriteria(new NamedTagsCriterion('php')),
            true,
        ];

        yield 'pushed persistent criteria applies by default' => [
            static function (ReferenceTableTagRepository $repository): ReferenceTableTagRepository {
                $repository->pushCriteria(new NamedTagsCriterion('php'));

                return $repository;
            },
            true,
        ];

        yield 'an added scope is always applied' => [
            static function (ReferenceTableTagRepository $repository): ReferenceTableTagRepository {
                return $repository->addScope(static function (Builder $query): void {
                    $query->where('name', 'php');
                });
            },
            true,
        ];

        yield 'disabled persistent criteria without a force do not apply' => [
            static function (ReferenceTableTagRepository $repository): ReferenceTableTagRepository {
                $repository->pushCriteria(new NamedTagsCriterion('php'));
                $repository->disableCriteria();

                return $repository;
            },
            false,
        ];
    }

    /**
     * Test that reference-mode composition detection agrees with
     * applyCriteria() on every precedence branch.
     *
     * @param  \Closure(\Tests\Support\Repositories\ReferenceTableTagRepository): \Tests\Support\Repositories\ReferenceTableTagRepository  $arrange
     * @param  bool  $expectedPending
     * @return void
     */
    #[DataProvider('compositionPrecedenceBranchProvider')]
    public function testReferenceModeCompositionMatchesApplyCriteria(\Closure $arrange, bool $expectedPending): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $composed = $arrange($repository);

        DB::enableQueryLog();

        $composed->get(); // @phpstan-ignore staticMethod.dynamicCall

        $referencePending = DB::getQueryLog() !== [];

        DB::disableQueryLog();

        $composed = $arrange($repository);

        $filtered = $composed->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        $applyCriteriaPending = $filtered->count() !== 2;

        self::assertSame($expectedPending, $referencePending);
        self::assertSame($expectedPending, $applyCriteriaPending);
    }

    /**
     * Test that a negative-lookup TTL override keeps a miss cached past the
     * packaged default.
     *
     * @return void
     */
    public function testNegativeTtlOverrideOutlivesThePackagedDefaultDuration(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(TunedCacheableTagRepository::class);

        self::assertNull($repository->find(999)); // @phpstan-ignore staticMethod.dynamicCall

        // Past the packaged 10s default, within the repository's 30s override.
        $this->travel(15)->seconds();

        DB::enableQueryLog();

        self::assertNull($repository->find(999)); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * @return iterable<string, array{class-string<\Tests\Support\Repositories\ByteSizeGuardedTagRepository|\Tests\Support\Repositories\SizeGuardedTagRepository>}>
     */
    public static function sizeCeilingRepositories(): iterable
    {
        yield 'row ceiling' => [SizeGuardedTagRepository::class];
        yield 'byte ceiling' => [ByteSizeGuardedTagRepository::class];
    }

    /**
     * Test that a result over a repository's size ceiling override is never
     * cached.
     *
     * @param  class-string<\Tests\Support\Repositories\ByteSizeGuardedTagRepository|\Tests\Support\Repositories\SizeGuardedTagRepository>  $class
     * @return void
     */
    #[DataProvider('sizeCeilingRepositories')]
    public function testCeilingOverrideKeepsOversizedResultsUncached(string $class): void
    {
        assert($this->app !== null);

        $repository = $this->app->make($class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertFalse($repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that disabling the registry on a non-taggable store leaves a cached
     * entry reachable after a write.
     *
     * @return void
     */
    public function testDisabledRegistryLeavesStaleEntryAfterWrite(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(RegistryDisabledFileStoreTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $result);
        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that the reference snapshot key is the prefix, connection, database,
     * generation and query fingerprint, in that order.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testReferenceModeCacheKeyIncludesPrefixConnectionAndDatabase(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        $generation = $cacheManager->store('array')->get('repositories:repository-cache-version:tags:testing::memory:');

        self::assertIsString($generation);
        self::assertTrue($cacheManager->store('array')->has('repositories:repository-cache:tags:testing::memory::' . $generation . ':' . QueryFingerprint::for(Tag::query())));
    }

    /**
     * Test that a reference-mode find() consumes the one-shot criteria flags.
     *
     * @return void
     */
    public function testReferenceModeFindConsumesOneShotCriteriaFlags(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ReferenceTableTagRepository::class);

        $this->setProperty($repository, 'skipCriteria', true);
        $this->setProperty($repository, 'forceUseCriteria', true);

        $repository->find(1); // @phpstan-ignore staticMethod.dynamicCall

        self::assertFalse($this->getProperty($repository, 'skipCriteria'));
        self::assertFalse($this->getProperty($repository, 'forceUseCriteria'));
    }

    /**
     * Test that resolveProperty() ignores an override of the wrong type and
     * passes each valid one through unchanged.
     *
     * @return void
     */
    public function testResolvePropertyIgnoresMistypedOverride(): void
    {
        self::assertNotNull($this->app);

        $mistyped = $this->app->make(MistypedOverrideTagRepository::class);
        $tuned    = $this->app->make(TunedCacheableTagRepository::class);

        self::assertNull($this->invokeMethod($mistyped, 'resolveProperty', 'cacheTtl'));
        self::assertSame(120, $this->invokeMethod($tuned, 'resolveProperty', 'cacheTtl'));
        self::assertFalse($this->invokeMethod($tuned, 'resolveProperty', 'cacheRegistryEnabled'));
        self::assertSame('custom-prefix', $this->invokeMethod($this->app->make(CustomPrefixCacheableTagRepository::class), 'resolveProperty', 'cacheKeyPrefix'));
    }

    /**
     * Test that referenceId() keeps only the int and string ids of an array.
     *
     * @return void
     */
    public function testReferenceIdFiltersArrayToSupportedKeyShapesOnly(): void
    {
        $ids = $this->invokeMethod($this->repository, 'referenceId', [[1, 3.5, 'two', null, true, [3]]]);

        self::assertSame([1, 'two'], $ids);
    }

    /**
     * Test that rowCount() counts a collection's items, a model as one row and
     * anything else as none.
     *
     * @return void
     */
    public function testRowCountReturnsExactCountsForEachResultShape(): void
    {
        $model = new Tag(['name' => 'php']);

        self::assertSame(3, $this->invokeMethod($this->repository, 'rowCount', collect([1, 2, 3])));
        self::assertSame(1, $this->invokeMethod($this->repository, 'rowCount', $model));
        self::assertSame(0, $this->invokeMethod($this->repository, 'rowCount', 'scalar-value'));
        self::assertSame(0, $this->invokeMethod($this->repository, 'rowCount', null));
    }
}
