<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Repositories\Concerns\Cacheable;
use Tests\Support\Models\Tag;
use Tests\Support\Repositories\CacheableTagRepository;
use Tests\Support\Repositories\DatabaseStoreTagRepository;
use Tests\Support\Repositories\FileStoreTagRepository;

/**
 * Integration tests for per-query repository cache invalidation on write verbs,
 * against a real database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(Cacheable::class)]
final class PerQueryCacheInvalidationTest extends IntegrationTestCase
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
     * Provide each inserting write verb with the repository that runs it.
     *
     * @return iterable<string, array{class-string<\Tests\Support\Repositories\CacheableTagRepository|\Tests\Support\Repositories\FileStoreTagRepository>, string}>
     */
    public static function inserts(): iterable
    {
        yield 'create' => [CacheableTagRepository::class, 'create'];
        yield 'create on a file store' => [FileStoreTagRepository::class, 'create'];
        yield 'firstOrCreate' => [CacheableTagRepository::class, 'firstOrCreate'];
        yield 'createOrFirst' => [CacheableTagRepository::class, 'createOrFirst'];
        yield 'createQuietly' => [CacheableTagRepository::class, 'createQuietly'];
        yield 'forceCreateQuietly' => [CacheableTagRepository::class, 'forceCreateQuietly'];
        yield 'incrementOrCreate' => [CacheableTagRepository::class, 'incrementOrCreate'];
    }

    /**
     * Test that an insert forces a fresh query on the next read.
     *
     * @param  class-string<\Tests\Support\Repositories\CacheableTagRepository|\Tests\Support\Repositories\FileStoreTagRepository>  $class
     * @param  string  $verb
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[DataProvider('inserts')]
    public function testAnInsertForcesAFreshQuery(string $class, string $verb): void
    {
        $repository = $this->makeRepository($class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $repository->__call($verb, [['name' => 'vue']]);

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
    }

    /**
     * Test that a registry-less file store serves a stale entry after a write.
     *
     * @return void
     */
    public function testFileStoreWithRegistryDisabledServesStaleEntry(): void
    {
        Config::set('repositories.cache.registry_enabled', false);

        $repository = $this->makeRepository(FileStoreTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());
        self::assertCount(2, $result);
    }

    /**
     * Test that updateOrCreate invalidates the cache when it upserts a row.
     *
     * @return void
     */
    public function testUpdateOrCreateInvalidatesCache(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->updateOrCreate(['name' => 'php'], ['name' => 'php8']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertSame('php8', $result->firstWhere('name', 'php8')?->getAttribute('name'));
    }

    /**
     * Test that a mass touch() invalidates the cache.
     *
     * @return void
     */
    public function testTouchInvalidatesCache(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->touch(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
    }

    /**
     * Test that a pending withoutCache() is consumed by the write it precedes.
     *
     * @return void
     */
    public function testWithoutCachePendingOnAWriteDoesNotLeakToLaterReads(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository = $repository->withoutCache();
        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a write invalidates a database store, seeding the version key
     * its increment cannot create.
     *
     * @return void
     */
    public function testDatabaseStoreInvalidatesViaRegistryOnWrite(): void
    {
        Schema::create('cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        $repository = $this->makeRepository(DatabaseStoreTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(3, $result);
        self::assertIsString(Cache::store('database')->get('repositories:repository-cache-version:tags'));
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
