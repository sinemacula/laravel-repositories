<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Carbon\Carbon;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Repositories\Concerns\CachePayloadEncoder;
use SineMacula\Repositories\Concerns\CacheSizeGuard;
use SineMacula\Repositories\Concerns\CacheStatus;
use SineMacula\Repositories\Concerns\QueryFingerprint;
use SineMacula\Repositories\Concerns\ReferenceCache;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Concerns\InteractsWithPosts;
use Tests\Support\Models\Post;
use Tests\Support\Models\Tag;
use Tests\Support\Models\TreeNode;
use Tests\Support\Repositories\ReferencePostRepository;

/**
 * Tests for the ReferenceCache collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheStatus::class)]
#[CoversClass(ReferenceCache::class)]
final class ReferenceCacheTest extends IntegrationTestCase
{
    use InteractsWithPosts;

    /** @var string The resolved reference metadata cache key for the tags table. */
    private const string META_KEY = 'repositories:repository-cache-meta:tags';

    /** @var \SineMacula\Repositories\Concerns\ReferenceCache The reference cache under test. */
    private ReferenceCache $referenceCache;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:00'));

        Tag::create(['name' => 'php']);
        Tag::create(['name' => 'laravel']);

        $this->referenceCache = new ReferenceCache(Cache::store('array'), 'tags', 3600, new CacheSizeGuard(null, null));
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Test that repeated reads load the table with a single query.
     *
     * @return void
     */
    public function testLoadsTableOnceForRepeatedReads(): void
    {
        DB::enableQueryLog();

        $this->referenceCache->all(new Tag);
        $this->referenceCache->all(new Tag);
        $this->referenceCache->all(new Tag);

        self::assertCount(1, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that all returns the full collection from memory.
     *
     * @return void
     */
    public function testAllReturnsFullCollectionFromMemory(): void
    {
        $result = $this->referenceCache->all(new Tag);

        self::assertCount(2, $result);
    }

    /**
     * Test that repeated reads are served from the instance memo after
     * eviction.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testReusesInstanceMemoAfterBackingStoreEviction(): void
    {
        $first = $this->referenceCache->all(new Tag);

        // flushTable() would also clear the memo, so evict the store directly.
        $this->referenceCache->getStore()->forget(self::snapshotKey());

        DB::enableQueryLog();

        $second = $this->referenceCache->all(new Tag);

        self::assertSame($first, $second);
        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that a fresh instance remembers and indexes an existing snapshot.
     *
     * @return void
     */
    public function testAllRemembersAnExistingCachedSnapshot(): void
    {
        $this->referenceCache->all(new Tag);

        $fresh = new ReferenceCache(Cache::store('array'), 'tags', 3600, new CacheSizeGuard(null, null));

        DB::enableQueryLog();

        $result = $fresh->all(new Tag);

        self::assertCount(2, $result);
        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();

        DB::enableQueryLog();

        $tag = $fresh->find(new Tag, 1);

        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame('php', $tag->getAttribute('name'));
        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that find resolves a record from a table too large to index.
     *
     * @return void
     */
    public function testFindResolvesFromAnOverLargeTableWithoutAKeyIndex(): void
    {
        $reference = new ReferenceCache(Cache::store('array'), 'tags', 3600, new CacheSizeGuard(1, null));

        $tag = $reference->find(new Tag, 1);

        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame('php', $tag->getAttribute('name'));
    }

    /**
     * Test that a table exceeding the size guard is returned in full but never
     * cached.
     *
     * @return void
     */
    public function testDoesNotCacheTableExceedingSizeGuard(): void
    {
        $reference = new ReferenceCache(Cache::store('array'), 'tags', 3600, new CacheSizeGuard(1, null));

        DB::enableQueryLog();

        $first  = $reference->all(new Tag);
        $second = $reference->all(new Tag);

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertCount(2, DB::getQueryLog());
        self::assertFalse($reference->getStatus()->isPopulated());

        DB::disableQueryLog();
    }

    /**
     * Test that a table the cache cannot store is neither cached nor memoised.
     *
     * @return void
     */
    public function testDoesNotCacheATableTheCacheCannotStore(): void
    {
        Tag::retrieved(static function (Tag $tag): void {
            $tag->setRawAttributes(['label' => new \ArrayObject] + $tag->getAttributes(), true);
        });

        DB::enableQueryLog();

        $first  = $this->referenceCache->all(new Tag);
        $second = $this->referenceCache->all(new Tag);

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertCount(2, DB::getQueryLog());
        self::assertFalse($this->referenceCache->getStatus()->isPopulated());

        DB::disableQueryLog();
    }

    /**
     * Test that a snapshot stored as objects by an earlier version is reloaded.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testReloadsASnapshotThatDoesNotReadBack(): void
    {
        Cache::store('file')->getStore()->put(self::snapshotKey(store: 'file'), Tag::query()->get(), 3600);

        $reference = new ReferenceCache(Cache::store('file'), 'tags', 3600, new CacheSizeGuard(null, null));

        DB::enableQueryLog();

        $tags = $reference->all(new Tag);

        self::assertInstanceOf(Collection::class, $tags);
        self::assertSame(['php', 'laravel'], $tags->pluck('name')->all());
        self::assertCount(1, DB::getQueryLog());
        self::assertIsArray(Cache::store('file')->get(self::snapshotKey(store: 'file')));

        DB::disableQueryLog();
    }

    /**
     * Test that a non-collection payload in the snapshot key is read as a miss.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testReloadsASnapshotThatIsNotACollection(): void
    {
        Cache::store('array')->put(self::snapshotKey(), CachePayloadEncoder::miss(), 3600);

        DB::enableQueryLog();

        self::assertCount(2, $this->referenceCache->all(new Tag));
        self::assertCount(1, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that find resolves a record from the in-memory snapshot without a
     * query.
     *
     * @return void
     */
    public function testFindResolvesRecordFromMemoryWithoutQuery(): void
    {
        $this->referenceCache->all(new Tag);

        DB::enableQueryLog();

        $tag = $this->referenceCache->find(new Tag, 1);

        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame('php', $tag->getAttribute('name'));
        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Test that find returns null for an unknown key.
     *
     * @return void
     */
    public function testFindReturnsNullForUnknownKey(): void
    {
        self::assertNull($this->referenceCache->find(new Tag, 999));
    }

    /**
     * Test that a flush forces the table to reload on the next read.
     *
     * @return void
     */
    public function testFlushForcesReloadOnNextRead(): void
    {
        $this->referenceCache->all(new Tag);

        Tag::create(['name' => 'vue']);

        $this->referenceCache->flushTable();

        $result = $this->referenceCache->all(new Tag);

        self::assertCount(3, $result);
    }

    /**
     * Test that getStatus reports a populated state after the table loads.
     *
     * @return void
     */
    public function testGetStatusReportsPopulatedStateAfterLoad(): void
    {
        self::assertFalse($this->referenceCache->getStatus()->isPopulated());

        $this->referenceCache->all(new Tag);

        self::assertTrue($this->referenceCache->getStatus()->isPopulated());
    }

    /**
     * Test that the snapshot and metadata cache keys are scoped to the table.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testCacheKeysAreScopedToTable(): void
    {
        $this->referenceCache->all(new Tag);

        self::assertTrue($this->referenceCache->getStore()->has(self::snapshotKey()));
        self::assertTrue($this->referenceCache->getStore()->has(self::META_KEY));
    }

    /**
     * Test that a snapshot is keyed by table, current generation and
     * fingerprint.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testASnapshotIsKeyedByTheCurrentGeneration(): void
    {
        $this->referenceCache->flushTable();
        $this->referenceCache->all(new Tag);

        $generation = $this->referenceCache->getStore()->get('repositories:repository-cache-version:tags');

        self::assertIsString($generation);
        self::assertSame(32, strlen($generation));
        self::assertTrue($this->referenceCache->getStore()->has('repositories:repository-cache:tags:' . $generation . ':' . QueryFingerprint::for(Tag::query())));
    }

    /**
     * Test that one table qualified by distinct connections never shares a
     * snapshot.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testDistinctConnectionsYieldDistinctReferenceSnapshotKeys(): void
    {
        $connectionA = new ReferenceCache(Cache::store('array'), 'tags:mysql:tenant_a', 3600, new CacheSizeGuard(null, null));
        $connectionB = new ReferenceCache(Cache::store('array'), 'tags:pgsql:tenant_b', 3600, new CacheSizeGuard(null, null));

        $connectionA->all(new Tag);

        self::assertTrue(Cache::store('array')->has(self::snapshotKey('tags:mysql:tenant_a')));
        self::assertFalse(Cache::store('array')->has(self::snapshotKey('tags:pgsql:tenant_b')));
        self::assertFalse($connectionB->getStatus()->isPopulated());
    }

    /**
     * Test that loading the table records populated_at metadata.
     *
     * @return void
     */
    public function testLoadRecordsPopulatedAtMetadata(): void
    {
        $this->referenceCache->all(new Tag);

        $meta = $this->referenceCache->getStore()->get(self::META_KEY);

        self::assertIsArray($meta);
        self::assertArrayHasKey('populated_at', $meta);
        self::assertSame(now()->timestamp, $meta['populated_at']);
    }

    /**
     * Test that a flush records invalidated_at metadata.
     *
     * @return void
     */
    public function testFlushRecordsInvalidatedAtMetadata(): void
    {
        $this->referenceCache->all(new Tag);
        $this->referenceCache->flushTable();

        $meta = $this->referenceCache->getStore()->get(self::META_KEY);

        self::assertIsArray($meta);
        self::assertArrayHasKey('invalidated_at', $meta);
        self::assertSame(now()->timestamp, $meta['invalidated_at']);
    }

    /**
     * Test that find with an array of ids returns the matching models.
     *
     * @return void
     */
    public function testFindWithArrayOfIdsReturnsMatchingModels(): void
    {
        $found = $this->referenceCache->find(new Tag, [1, 2, 999]);

        self::assertInstanceOf(Collection::class, $found);
        self::assertCount(2, $found);
    }

    /**
     * Test that a flush drops the key index so find never serves stale entries.
     *
     * @return void
     */
    public function testFlushedIndexIsNotServedAfterSizeGuardTransition(): void
    {
        $guarded = new ReferenceCache(Cache::store('array'), 'tags', 3600, new CacheSizeGuard(2, null));

        $guarded->all(new Tag);
        $guarded->flushTable();

        Tag::create(['name' => 'vue']);

        $found = $guarded->find(new Tag, 3);

        self::assertInstanceOf(Tag::class, $found);
        self::assertSame('vue', $found->getAttribute('name'));
    }

    /**
     * Test that each global-scope variant of the table holds its own snapshot.
     *
     * @return void
     */
    public function testEachScopeVariantHoldsItsOwnSnapshot(): void
    {
        $this->seedPosts();

        $reference = new ReferenceCache(Cache::store('array'), 'posts', 3600, new CacheSizeGuard(null, null));

        self::assertSame(['live'], $reference->all(new Post)->pluck('title')->all());

        $this->actAsTenant('two');

        self::assertSame(['other'], $reference->all(new Post)->pluck('title')->all());
        self::assertNull($reference->find(new Post, 1));

        $this->actAsTenant('one');

        DB::enableQueryLog();

        self::assertInstanceOf(Post::class, $reference->find(new Post, 1));
        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'a taggable store' => ['array'];
        yield 'a non-taggable store' => ['file'];
    }

    /**
     * Test that a flush invalidates every variant for every instance.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('stores')]
    public function testAFlushInvalidatesEveryVariantAcrossInstances(string $store): void
    {
        $this->seedPosts();

        $reference = static fn (): ReferenceCache => new ReferenceCache(Cache::store($store), 'posts', 3600, new CacheSizeGuard(null, null));

        $warm = $reference();
        $warm->all(new Post);
        $this->actAsTenant('two');
        $warm->all(new Post);

        DB::table('posts')->insert([
            ['id' => 4, 'tenant' => 'one', 'title' => 'new one'],
            ['id' => 5, 'tenant' => 'two', 'title' => 'new two'],
        ]);

        $reference()->flushTable();

        $fresh = $reference();

        self::assertSame(['other', 'new two'], $fresh->all(new Post)->pluck('title')->all());

        $this->actAsTenant('one');

        self::assertSame(['live', 'new one'], $fresh->all(new Post)->pluck('title')->all());
    }

    /**
     * Test that every flush invalidates the snapshot, not only the first.
     *
     * @return void
     */
    public function testEveryFlushInvalidatesTheSnapshot(): void
    {
        $reference = static fn (): ReferenceCache => new ReferenceCache(Cache::store('file'), 'tags', 3600, new CacheSizeGuard(null, null));

        foreach (['vue', 'react'] as $name) {

            $reference()->all(new Tag);

            Tag::create(['name' => $name]);

            $reference()->flushTable();
        }

        self::assertSame(['php', 'laravel', 'vue', 'react'], $reference()->all(new Tag)->pluck('name')->all());
    }

    /**
     * Test that an evicted generation never revives an older snapshot.
     *
     * @return void
     */
    public function testAnEvictedGenerationNeverRevivesAnOlderSnapshot(): void
    {
        $this->referenceCache->all(new Tag);

        Tag::create(['name' => 'vue']);

        $this->referenceCache->flushTable();
        $this->referenceCache->getStore()->forget('repositories:repository-cache-version:tags');

        $fresh = new ReferenceCache(Cache::store('array'), 'tags', 3600, new CacheSizeGuard(null, null));

        self::assertSame(['php', 'laravel', 'vue'], $fresh->all(new Tag)->pluck('name')->all());
    }

    /**
     * Test that a write invalidates every variant with the registry disabled.
     *
     * @return void
     */
    public function testWriteInvalidatesEveryVariantWithRegistryDisabled(): void
    {
        $this->seedPosts();

        Config::set('repositories.cache.store', 'file');
        Config::set('repositories.cache.registry_enabled', false);

        self::assertNotNull($this->app);

        $posts = fn (): ReferencePostRepository => $this->app->make(ReferencePostRepository::class);

        $posts()->get(); // @phpstan-ignore staticMethod.dynamicCall
        $this->actAsTenant('two');
        $posts()->get(); // @phpstan-ignore staticMethod.dynamicCall

        $posts()->create(['tenant' => 'two', 'title' => 'new two']); // @phpstan-ignore staticMethod.dynamicCall

        DB::table('posts')->insert(['tenant' => 'one', 'title' => 'new one']);

        self::assertSame(['other', 'new two'], $this->titles($posts()->get())); // @phpstan-ignore staticMethod.dynamicCall

        $this->actAsTenant('one');

        self::assertSame(['live', 'new one'], $this->titles($posts()->get())); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that an unfingerprintable variant is queried on every read, never
     * stored.
     *
     * @return void
     */
    public function testUnfingerprintableVariantIsNeverStored(): void
    {
        $this->seedPosts();

        $reference = new ReferenceCache(Cache::store('array'), 'tree_nodes', 3600, new CacheSizeGuard(null, null));

        Log::shouldReceive('debug')
            ->twice()
            ->with('Reference snapshot fingerprinting unavailable; executing read uncached', \Mockery::on(
                static fn (array $context): bool => $context['table'] === 'tree_nodes' && $context['exception'] instanceof UnfingerprintableQueryException,
            ));

        $reference->all(new TreeNode);

        DB::enableQueryLog();

        self::assertInstanceOf(TreeNode::class, $reference->find(new TreeNode, 2));
        self::assertNotEmpty(DB::getQueryLog());
        self::assertFalse($reference->getStatus()->isPopulated());

        DB::disableQueryLog();
    }

    /**
     * Test that a flush logs a generation the store refuses to replace.
     *
     * @return void
     */
    public function testFlushLogsAGenerationTheStoreRefusesToReplace(): void
    {
        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('forever')->andReturn(false); // @phpstan-ignore method.notFound

        Log::shouldReceive('error')
            ->once()
            ->with('Reference snapshot generation could not be replaced', [
                'table'          => 'tags',
                'generation_key' => 'repositories:repository-cache-version:tags',
            ]);

        (new ReferenceCache(new Repository($store), 'tags', 3600, new CacheSizeGuard(null, null)))->flushTable();
    }

    /**
     * Get the titles of the given posts.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $posts
     * @return array<int, string>
     */
    private function titles(Collection $posts): array
    {
        /** @var array<int, string> */
        return $posts->pluck('title')->all();
    }

    /**
     * Resolve the snapshot key of the unscoped tags table under the current
     * generation, seeding one when the store holds none.
     *
     * @param  string  $table
     * @param  string  $store
     * @return string
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    private static function snapshotKey(string $table = 'tags', string $store = 'array'): string
    {
        $generationKey = 'repositories:repository-cache-version:' . $table;
        $generation    = Cache::store($store)->get($generationKey);

        if (!is_string($generation)) {
            $generation = 'seeded';

            Cache::store($store)->forever($generationKey, $generation);
        }

        return 'repositories:repository-cache:' . $table . ':' . $generation . ':' . QueryFingerprint::for(Tag::query());
    }
}
