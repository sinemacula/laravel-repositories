<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversTrait;
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
     * Test that a write invalidation forces a fresh query on the next read.
     *
     * @return void
     */
    public function testWriteInvalidationForcesFreshQuery(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
    }

    /**
     * Test that a non-taggable file store invalidates per-query entries via the
     * registry on a write.
     *
     * @return void
     */
    public function testFileStoreInvalidatesViaRegistryOnWrite(): void
    {
        $repository = $this->makeRepository(FileStoreTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
    }

    /**
     * Test that, with the registry disabled, a non-taggable file store keeps
     * serving the stale entry after a write (TTL-only staleness).
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
     * Test that firstOrCreate invalidates the cache when it inserts a new row.
     *
     * @return void
     */
    public function testFirstOrCreateInvalidatesCacheOnInsert(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->firstOrCreate(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
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
     * Test that createOrFirst invalidates the cache when it inserts a new row.
     *
     * @return void
     */
    public function testCreateOrFirstInvalidatesCacheOnInsert(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->createOrFirst(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
    }

    /**
     * Test that createQuietly invalidates the cache when it inserts a new row.
     *
     * @return void
     */
    public function testCreateQuietlyInvalidatesCacheOnInsert(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->createQuietly(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
    }

    /**
     * Test that forceCreateQuietly invalidates the cache when it inserts a new
     * row.
     *
     * @return void
     */
    public function testForceCreateQuietlyInvalidatesCacheOnInsert(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->forceCreateQuietly(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
    }

    /**
     * Test that incrementOrCreate invalidates the cache when it creates a new
     * row.
     *
     * @return void
     */
    public function testIncrementOrCreateInvalidatesCacheOnInsert(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->incrementOrCreate(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
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
     * Test that a pending withoutCache() is consumed by a write verb rather
     * than leaking onto a later, unrelated read.
     *
     * @return void
     */
    public function testWithoutCachePendingOnAWriteDoesNotLeakToLaterReads(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->withoutCache();
        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that the non-taggable database store invalidates per-query entries
     * on a write, seeding the version key its increment cannot create.
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
        self::assertSame(1, Cache::store('database')->get('repositories:repository-cache-version:tags'));
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
