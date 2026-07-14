<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Carbon\Carbon;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Repositories\Concerns\CacheMiss;
use SineMacula\Repositories\Concerns\CacheSizeGuard;
use SineMacula\Repositories\Concerns\CacheStatus;
use SineMacula\Repositories\Concerns\CacheStore;
use SineMacula\Repositories\Concerns\CacheStoreOptions;
use SineMacula\Repositories\Enums\CacheKeys;
use Tests\Integration\IntegrationTestCase;

/**
 * Tests for the per-query CacheStore collaborator.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheStatus::class)]
#[CoversClass(CacheStore::class)]
final class CacheStoreTest extends IntegrationTestCase
{
    /** @var string A representative query fingerprint. */
    private const string HASH = 'abc123';

    /** @var \SineMacula\Repositories\Concerns\CacheStore The cache store instance under test. */
    private CacheStore $cacheStore;

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

        $this->cacheStore = new CacheStore(Cache::store('array'), 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));
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
     * Test that a stored result round-trips for the same fingerprint.
     *
     * @return void
     */
    public function testPutAndGetRoundTripForSameFingerprint(): void
    {
        $items = collect(['foo', 'bar', 'baz']);

        $this->cacheStore->put(self::HASH, $items, $items->count());

        $cached = $this->cacheStore->fetch(self::HASH);

        self::assertInstanceOf(Collection::class, $cached);
        self::assertSame(['foo', 'bar', 'baz'], $cached->all());
    }

    /**
     * Test that a negative entry expires after the shorter negative TTL while a
     * positive entry stored at the same moment survives on the full TTL.
     *
     * @return void
     */
    public function testPutMissExpiresAfterNegativeTtlNotFullTtl(): void
    {
        $this->cacheStore->putMiss(self::HASH);
        $this->cacheStore->put('positive', collect(['x']), 1);

        self::assertInstanceOf(CacheMiss::class, $this->cacheStore->fetch(self::HASH));

        // 11 seconds on: past the 10s negative TTL but well within the 3600s
        // TTL.
        Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:11'));

        self::assertNull($this->cacheStore->fetch(self::HASH));
        self::assertNotNull($this->cacheStore->fetch('positive'));
    }

    /**
     * Test that a negative entry is invalidated by a table flush, so a write
     * does not leave a stale "not found" behind.
     *
     * @return void
     */
    public function testPutMissIsInvalidatedByFlushTable(): void
    {
        $this->cacheStore->putMiss(self::HASH);

        $this->cacheStore->flushTable();

        self::assertNull($this->cacheStore->fetch(self::HASH));
    }

    /**
     * Test that distinct fingerprints map to distinct cache entries.
     *
     * @return void
     */
    public function testDistinctFingerprintsAreIsolated(): void
    {
        $this->cacheStore->put('hash-a', collect(['a']), 1);
        $this->cacheStore->put('hash-b', collect(['b', 'c']), 2);

        $first  = $this->cacheStore->fetch('hash-a');
        $second = $this->cacheStore->fetch('hash-b');

        self::assertInstanceOf(Collection::class, $first);
        self::assertInstanceOf(Collection::class, $second);
        self::assertSame(['a'], $first->all());
        self::assertSame(['b', 'c'], $second->all());
    }

    /**
     * Test that put records populated_at metadata.
     *
     * @return void
     */
    public function testPutRecordsPopulatedAtMetadata(): void
    {
        $this->cacheStore->put(self::HASH, collect(['item']), 1);

        $meta = $this->cacheStore->getStore()->get('repositories:repository-cache-meta:test-table');

        self::assertIsArray($meta);
        self::assertArrayHasKey('populated_at', $meta);
        self::assertSame(now()->timestamp, $meta['populated_at']);
    }

    /**
     * Test that the size guard skips storing when the row count exceeds the
     * configured ceiling, while a fetch still misses.
     *
     * @return void
     */
    public function testSizeGuardSkipsStoringWhenRowCountExceeded(): void
    {
        $store = new CacheStore(Cache::store('array'), 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(2, 262144), true, 10));

        $store->put(self::HASH, collect(['a', 'b', 'c']), 3);

        self::assertNull($store->fetch(self::HASH));
    }

    /**
     * Test that the size guard skips storing when the serialized byte size
     * exceeds the configured ceiling.
     *
     * @return void
     */
    public function testSizeGuardSkipsStoringWhenByteSizeExceeded(): void
    {
        $store = new CacheStore(Cache::store('array'), 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 8), true, 10));

        $store->put(self::HASH, collect([str_repeat('x', 256)]), 1);

        self::assertNull($store->fetch(self::HASH));
    }

    /**
     * Test that flushTable removes a stored entry on a taggable store.
     *
     * @return void
     */
    public function testFlushTableRemovesStoredEntryOnTaggableStore(): void
    {
        $this->cacheStore->put(self::HASH, collect(['item']), 1);

        $this->cacheStore->flushTable();

        self::assertNull($this->cacheStore->fetch(self::HASH));
    }

    /**
     * Test that flushTable invalidates a stored entry via a generational
     * version bump on a non-taggable store.
     *
     * @return void
     */
    public function testFlushTableInvalidatesEntryViaVersionBumpOnNonTaggableStore(): void
    {
        $store = new CacheStore(Cache::store('file'), 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $store->put(self::HASH, collect(['item']), 1);

        self::assertNotNull($store->fetch(self::HASH));

        $store->flushTable();

        self::assertNull($store->fetch(self::HASH));
    }

    /**
     * Test that flushTable bumps the table's generational version on each call,
     * orphaning entries stored under the previous version while leaving newer
     * entries reachable - an O(1) invalidation with no tracked key set.
     *
     * @return void
     */
    public function testFlushTableBumpsGenerationalVersionOnNonTaggableStore(): void
    {
        $store      = new CacheStore(Cache::store('file'), 'versioned-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));
        $versionKey = 'repositories:repository-cache-version:versioned-table';

        Log::shouldReceive('error')->never();

        $store->put(self::HASH, collect(['first']), 1);

        self::assertNull($store->getStore()->get($versionKey));

        $store->flushTable();

        self::assertSame(1, $store->getStore()->get($versionKey));
        self::assertNull($store->fetch(self::HASH));

        $store->put(self::HASH, collect(['second']), 1);

        self::assertNotNull($store->fetch(self::HASH));

        $store->flushTable();

        self::assertSame(2, $store->getStore()->get($versionKey));
        self::assertNull($store->fetch(self::HASH));
    }

    /**
     * Test that, with invalidation disabled, flushTable leaves a non-taggable
     * entry in place so staleness is governed by TTL only.
     *
     * @return void
     */
    public function testFlushTableLeavesEntryWhenRegistryDisabledOnNonTaggableStore(): void
    {
        $store = new CacheStore(Cache::store('file'), 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), false, 10));

        $store->put(self::HASH, collect(['item']), 1);

        $store->flushTable();

        self::assertNotNull($store->fetch(self::HASH));
    }

    /**
     * Test that flushTable records invalidated_at metadata.
     *
     * @return void
     */
    public function testFlushTableRecordsInvalidatedAtMetadata(): void
    {
        $this->cacheStore->flushTable();

        $meta = $this->cacheStore->getStore()->get('repositories:repository-cache-meta:test-table');

        self::assertIsArray($meta);
        self::assertArrayHasKey('invalidated_at', $meta);
        self::assertSame(now()->timestamp, $meta['invalidated_at']);
    }

    /**
     * Test that getStatus reports a populated state after a put.
     *
     * @return void
     */
    public function testGetStatusReportsPopulatedStateAfterPut(): void
    {
        $this->cacheStore->put(self::HASH, collect(['item']), 1);

        Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:30'));

        $status = $this->cacheStore->getStatus();

        self::assertTrue($status->isPopulated());
        self::assertSame(30, $status->getAge());
        self::assertNull($status->getLastInvalidatedAt());
    }

    /**
     * Test that getStatus reports an unpopulated state on a cache miss.
     *
     * @return void
     */
    public function testGetStatusReportsUnpopulatedStateOnCacheMiss(): void
    {
        $status = $this->cacheStore->getStatus();

        self::assertFalse($status->isPopulated());
        self::assertNull($status->getAge());
    }

    /**
     * Test that getStatus reports lastInvalidatedAt after a flush.
     *
     * @return void
     */
    public function testGetStatusReportsLastInvalidatedAtAfterFlush(): void
    {
        $this->cacheStore->put(self::HASH, collect(['item']), 1);
        $this->cacheStore->flushTable();

        $status = $this->cacheStore->getStatus();

        self::assertFalse($status->isPopulated());
        self::assertNotNull($status->getLastInvalidatedAt());
        self::assertSame(now()->timestamp, $status->getLastInvalidatedAt()->timestamp);
    }

    /**
     * Test that getStore returns the underlying cache repository.
     *
     * @return void
     */
    public function testGetStoreReturnsUnderlyingCacheRepository(): void
    {
        self::assertInstanceOf(CacheContract::class, $this->cacheStore->getStore());
    }

    /**
     * Test that a taggable store flushes through its tag even when the key
     * registry is disabled, proving the tag path is selected for taggable
     * stores rather than the registry.
     *
     * @return void
     */
    public function testTaggableStoreFlushesViaTagsWhenRegistryDisabled(): void
    {
        $store = new CacheStore(Cache::store('array'), 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), false, 10));

        $store->put(self::HASH, collect(['item']), 1);

        self::assertNotNull($store->fetch(self::HASH));

        $store->flushTable();

        self::assertNull($store->fetch(self::HASH));
    }

    /**
     * Test that the per-table tag isolates entries between tables, so flushing
     * one table never invalidates another table's cached entries.
     *
     * @return void
     */
    public function testTagIsolatesEntriesBetweenTables(): void
    {
        $tableA = new CacheStore(Cache::store('array'), 'table-a', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));
        $tableB = new CacheStore(Cache::store('array'), 'table-b', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $tableA->put(self::HASH, collect(['a']), 1);
        $tableB->put(self::HASH, collect(['b']), 1);

        $tableA->flushTable();

        self::assertNull($tableA->fetch(self::HASH));
        self::assertNotNull($tableB->fetch(self::HASH));
    }

    /**
     * Test that invalidateTable flushes a taggable store's table tag and,
     * because the taggable path returns early, never bumps the generational
     * version.
     *
     * @return void
     */
    public function testInvalidateTableFlushesTaggableStoreWithoutBumpingVersion(): void
    {
        $this->cacheStore->put(self::HASH, collect(['a', 'b']), 2);

        CacheStore::invalidateTable('array', 'test-table', registryEnabled: true);

        self::assertNull($this->cacheStore->fetch(self::HASH));
        self::assertNull(Cache::store('array')->get(CacheKeys::REPOSITORY_CACHE_VERSION->resolveKey(['test-table'])));
    }

    /**
     * Test that invalidateTable bumps the generational version on a
     * non-taggable store when the registry is enabled, so a previously cached
     * entry reads back as a miss.
     *
     * @return void
     */
    public function testInvalidateTableBumpsVersionOnNonTaggableStoreWhenRegistryEnabled(): void
    {
        $options = new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10);
        (new CacheStore(Cache::store('file'), 'file-table', $options))->put(self::HASH, collect(['a']), 1);

        Log::shouldReceive('error')->never();

        CacheStore::invalidateTable('file', 'file-table', registryEnabled: true);

        self::assertNull((new CacheStore(Cache::store('file'), 'file-table', $options))->fetch(self::HASH));
    }

    /**
     * Test that invalidateTable leaves a non-taggable store's entry intact when
     * the registry is disabled, because it cannot bump the version.
     *
     * @return void
     */
    public function testInvalidateTableLeavesEntryOnNonTaggableStoreWhenRegistryDisabled(): void
    {
        $options = new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10);
        (new CacheStore(Cache::store('file'), 'file-table', $options))->put(self::HASH, collect(['a']), 1);

        CacheStore::invalidateTable('file', 'file-table', registryEnabled: false);

        self::assertNotNull((new CacheStore(Cache::store('file'), 'file-table', $options))->fetch(self::HASH));
    }

    /**
     * Test that non-taggable keys embed the table, the generational version,
     * and the fingerprint in the documented order, pinning the physical key
     * layout that version bumps rely on.
     *
     * @return void
     */
    public function testNonTaggableKeysEmbedTableVersionAndFingerprint(): void
    {
        $store = new CacheStore(Cache::store('file'), 'key-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $store->put(self::HASH, collect(['item']), 1);

        self::assertNotNull($store->getStore()->get('repositories:repository-query:key-table:0:' . self::HASH));
    }

    /**
     * Test that an instance keeps serving entries under its memoised version
     * after an external version bump, honouring the request-scoped snapshot
     * contract, while a fresh instance observes the bump and misses.
     *
     * @return void
     */
    public function testServesEntriesUnderMemoisedVersionUntilInstanceDiscard(): void
    {
        $options = new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10);
        $store   = new CacheStore(Cache::store('file'), 'memo-table', $options);

        $store->put(self::HASH, collect(['item']), 1);

        CacheStore::invalidateTable('file', 'memo-table', registryEnabled: true);

        self::assertNotNull($store->fetch(self::HASH));
        self::assertNull((new CacheStore(Cache::store('file'), 'memo-table', $options))->fetch(self::HASH));
    }

    /**
     * Test that taggable entries are scoped to the canonical per-table tag, so
     * a cross-cutting flush of that tag evicts them.
     *
     * @return void
     */
    public function testEntriesAreScopedToTheCanonicalTableTag(): void
    {
        $store = new CacheStore(Cache::store('array'), 'tag-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $store->put(self::HASH, collect(['item']), 1);

        $repository = Cache::store('array');

        assert($repository instanceof Repository);

        $repository->tags(['repo-table:tag-table'])->flush();

        self::assertNull($store->fetch(self::HASH));
    }

    /**
     * Test that a failed version increment falls back to bumping the memoised
     * version locally, so the instance still stops serving pre-flush entries.
     *
     * @return void
     */
    public function testFailedVersionIncrementFallsBackToBumpingTheMemoisedVersion(): void
    {
        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('increment')->andReturn(false);
        $store->shouldReceive('get')
            ->with('repositories:repository-cache-version:increment-table')
            ->andReturn(3);
        $store->shouldReceive('get')
            ->with('repositories:repository-query:increment-table:4:' . self::HASH)
            ->andReturn('post-bump');

        Cache::extend('incrementless', fn (): Repository => new Repository($store));
        Config::set('cache.stores.incrementless', ['driver' => 'incrementless']);

        $cacheStore = new CacheStore(Cache::store('incrementless'), 'increment-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $cacheStore->flushTable();

        self::assertSame('post-bump', $cacheStore->fetch(self::HASH));
    }

    /**
     * Test that a version bump seeds the version key when the store cannot
     * increment a missing entry, so invalidation still takes effect for other
     * instances instead of silently degrading to TTL expiry.
     *
     * @return void
     */
    public function testFlushSeedsVersionKeyWhenIncrementCannotCreateIt(): void
    {
        $strictStore = $this->strictIncrementStore();

        Cache::extend('seedable', fn (): Repository => new Repository($strictStore));
        Config::set('cache.stores.seedable', ['driver' => 'seedable']);

        $cacheStore = new CacheStore(Cache::store('seedable'), 'seed-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $cacheStore->flushTable();

        self::assertSame(1, $cacheStore->getStore()->get('repositories:repository-cache-version:seed-table'));
    }

    /**
     * Test that the static table invalidation seeds the version key when the
     * store cannot increment a missing entry.
     *
     * @return void
     */
    public function testInvalidateTableSeedsVersionKeyWhenIncrementCannotCreateIt(): void
    {
        $strictStore = $this->strictIncrementStore();

        Cache::extend('seedable-static', fn (): Repository => new Repository($strictStore));
        Config::set('cache.stores.seedable-static', ['driver' => 'seedable-static']);

        CacheStore::invalidateTable('seedable-static', 'seed-table', registryEnabled: true);

        self::assertSame(1, Cache::store('seedable-static')->get('repositories:repository-cache-version:seed-table'));
    }

    /**
     * Test that a persistently failing version increment (both the initial
     * call and the post-seed retry fail, as on a genuine store outage) is
     * logged at error level from the instance flush path, so a process other
     * than the writer can discover that its invalidation was never persisted.
     *
     * @return void
     */
    public function testFlushTableLogsErrorWhenVersionIncrementPersistentlyFails(): void
    {
        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('increment')->andReturn(false);

        Cache::extend('persistently-failing', fn (): Repository => new Repository($store));
        Config::set('cache.stores.persistently-failing', ['driver' => 'persistently-failing']);

        $cacheStore = new CacheStore(Cache::store('persistently-failing'), 'failing-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        Log::shouldReceive('error')
            ->once()
            ->with('Table version increment failed after seed retry', \Mockery::on(
                static fn (array $context): bool => $context['table'] === 'failing-table'
                    && $context['version_key']                        === 'repositories:repository-cache-version:failing-table',
            ));

        $cacheStore->flushTable();
    }

    /**
     * Test that a persistently failing version increment is logged at error
     * level from the static invalidateTable() path, mirroring the instance
     * flush path's observability.
     *
     * @return void
     */
    public function testInvalidateTableLogsErrorWhenVersionIncrementPersistentlyFails(): void
    {
        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('increment')->andReturn(false);

        Cache::extend('persistently-failing-static', fn (): Repository => new Repository($store));
        Config::set('cache.stores.persistently-failing-static', ['driver' => 'persistently-failing-static']);

        Log::shouldReceive('error')
            ->once()
            ->with('Table version increment failed after seed retry', \Mockery::on(
                static fn (array $context): bool => $context['store'] === 'persistently-failing-static'
                    && $context['table']                              === 'failing-table',
            ));

        CacheStore::invalidateTable('persistently-failing-static', 'failing-table', registryEnabled: true);
    }

    /**
     * Test that fetch() distinguishes an absent entry, a negative-cache
     * marker, and a cached value in a single lookup.
     *
     * @return void
     */
    public function testFetchDistinguishesAbsentNegativeAndCachedEntries(): void
    {
        self::assertNull($this->cacheStore->fetch(self::HASH));

        $this->cacheStore->putMiss(self::HASH);

        self::assertInstanceOf(CacheMiss::class, $this->cacheStore->fetch(self::HASH));

        $this->cacheStore->put(self::HASH, 'value', 1);

        self::assertSame('value', $this->cacheStore->fetch(self::HASH));
    }

    /**
     * Build a non-taggable store stub whose increment() fails on missing keys,
     * mirroring the database driver's behaviour.
     *
     * @return \Illuminate\Contracts\Cache\Store
     */
    private function strictIncrementStore(): Store
    {
        /** @var array<string, array<string, int>|int> $storage */
        $storage = [];

        $store = \Mockery::mock(Store::class);

        $store->shouldReceive('increment')->andReturnUsing(static function (string $key, int $value = 1) use (&$storage): bool|int {
            $current = $storage[$key] ?? null;

            if (!is_int($current)) {
                return false;
            }

            $storage[$key] = $current + $value;

            return $storage[$key];
        });

        $store->shouldReceive('get')->andReturnUsing(static function (string $key) use (&$storage): array|int|null {
            return $storage[$key] ?? null;
        });

        $store->shouldReceive('put', 'forever')->andReturnUsing(static function (string $key, array|int $value) use (&$storage): bool {
            $storage[$key] = $value;

            return true;
        });

        return $store;
    }
}
