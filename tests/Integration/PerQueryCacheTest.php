<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;
use Tests\Support\Criteria\NamedTagsCriterion;
use Tests\Support\Models\Tag;
use Tests\Support\Repositories\CacheableTagRepository;
use Tests\Support\Repositories\CustomStoreCacheableTagRepository;
use Tests\Support\Repositories\ReferenceTableTagRepository;
use Tests\Support\Repositories\SizeGuardedTagRepository;

/**
 * Integration tests for per-query repository caching against a real database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(Cacheable::class)]
final class PerQueryCacheTest extends IntegrationTestCase
{
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
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        DB::disableQueryLog();

        parent::tearDown();
    }

    /**
     * Test that a cache miss executes exactly one database query.
     *
     * @return void
     */
    public function testCacheMissExecutesSingleQuery(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
    }

    /**
     * Test that a cache hit executes no database queries.
     *
     * @return void
     */
    public function testCacheHitExecutesZeroQueries(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a scoped cache hit returns the scoped row without querying.
     *
     * @return void
     */
    public function testScopedCacheHitExecutesZeroQueries(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $tag = $repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());
        self::assertSame('php', $tag?->getAttribute('name'));
    }

    /**
     * Test that a result too large to cache always hits the database.
     *
     * @return void
     */
    public function testSizeGuardedResultAlwaysHitsDatabase(): void
    {
        $repository = $this->makeRepository(SizeGuardedTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
    }

    /**
     * Test that reference mode loads the whole table once for mixed reads.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testReferenceModeLoadsTableOnceForMixedReads(): void
    {
        $repository = $this->makeRepository(ReferenceTableTagRepository::class);

        DB::enableQueryLog();

        $repository->all();
        $repository->find(1); // @phpstan-ignore staticMethod.dynamicCall
        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
    }

    /**
     * Test that a repeated null find is served from the negative cache without
     * re-querying the database.
     *
     * @return void
     */
    public function testNullFindIsServedFromNegativeCache(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->find(999); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->find(999); // @phpstan-ignore staticMethod.dynamicCall

        self::assertNull($result);
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a cached null find does not hide a row created afterwards.
     *
     * @return void
     */
    public function testNullFindReflectsRowOnceCreated(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        self::assertNull($repository->find(999)); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(3, $result);
    }

    /**
     * Test that flushing the default store leaves a dedicated store intact.
     *
     * @return void
     */
    public function testDefaultStoreFlushDoesNotEvictDedicatedRepositoryCache(): void
    {
        Config::set('cache.stores.custom-test', ['driver' => 'array']);

        $repository = $this->makeRepository(CustomStoreCacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($repository->getCacheStatus()->isPopulated());

        Cache::store('array')->clear();

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());
        self::assertTrue($repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that all() is served from the per-query cache.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testAllServesAndCachesInPerQueryMode(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $first = $repository->all();

        DB::enableQueryLog();

        $second = $repository->all();

        self::assertCount(0, DB::getQueryLog());
        self::assertInstanceOf(Collection::class, $first);
        self::assertInstanceOf(Collection::class, $second);
        self::assertCount(2, $first);
        self::assertCount(2, $second);
    }

    /**
     * Test that reads with closure arguments run uncached.
     *
     * @return void
     */
    public function testDistinctClosureArgumentsExecuteUncachedReads(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        // @phpstan-ignore staticMethod.dynamicCall
        $php = $repository->firstWhere(static function ($query): void {
            $query->where('name', 'php');
        });

        DB::enableQueryLog();

        // @phpstan-ignore staticMethod.dynamicCall
        $laravel = $repository->firstWhere(static function ($query): void {
            $query->where('name', 'laravel');
        });

        self::assertCount(1, DB::getQueryLog());
        self::assertInstanceOf(Tag::class, $php);
        self::assertInstanceOf(Tag::class, $laravel);
        self::assertSame('php', $php->getAttribute('name'));
        self::assertSame('laravel', $laravel->getAttribute('name'));
    }

    /**
     * Test that an unfingerprintable read logs its uncached fallback.
     *
     * @return void
     */
    public function testUnfingerprintableReadLogsFallbackAtDebugLevel(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        Log::shouldReceive('debug')
            ->once()
            ->with('Query fingerprinting unavailable; executing read uncached', \Mockery::on(
                static fn (array $context): bool => $context['method'] === 'firstWhere'
                    && $context['exception'] instanceof UnfingerprintableQueryException,
            ));

        // @phpstan-ignore staticMethod.dynamicCall
        $repository->firstWhere(static function ($query): void {
            $query->where('name', 'php');
        });
    }

    /**
     * Test that reference mode queries the database when criteria are active.
     *
     * @return void
     */
    public function testReferenceModeExecutesRealQueryWhenCriteriaAreActive(): void
    {
        $repository = $this->makeRepository(ReferenceTableTagRepository::class);

        $filtered = $repository->withCriteria(new NamedTagsCriterion('php'))->get(); // @phpstan-ignore staticMethod.dynamicCall
        $first    = $filtered->first();

        self::assertCount(1, $filtered);
        self::assertInstanceOf(Tag::class, $first);
        self::assertSame('php', $first->getAttribute('name'));

        $unfiltered = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $unfiltered);
    }

    /**
     * Test that reference mode honours scopes by executing a real query.
     *
     * @return void
     */
    public function testReferenceModeExecutesRealQueryWhenScopesAreActive(): void
    {
        $repository = $this->makeRepository(ReferenceTableTagRepository::class);

        // @phpstan-ignore staticMethod.dynamicCall
        $scoped = $repository->addScope(static function ($query): void {
            $query->where('name', 'laravel');
        })->get();

        $first = $scoped->first();

        self::assertCount(1, $scoped);
        self::assertInstanceOf(Tag::class, $first);
        self::assertSame('laravel', $first->getAttribute('name'));
    }

    /**
     * Test that a reference-mode find() with several ids returns each match.
     *
     * @return void
     */
    public function testReferenceModeFindWithArrayOfIdsReturnsMatchingModels(): void
    {
        $repository = $this->makeRepository(ReferenceTableTagRepository::class);

        $found = $repository->find([1, 2]); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Collection::class, $found);
        self::assertCount(2, $found);
    }

    /**
     * Resolve a fresh cacheable repository from the container.
     *
     * @template TRepository of object
     *
     * @param  class-string<TRepository>  $class
     * @return TRepository
     */
    private function makeRepository(string $class): object
    {
        assert($this->app !== null);

        Cache::store('file')->clear();

        return $this->app->make($class);
    }
}
