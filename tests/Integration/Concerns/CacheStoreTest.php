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
use SineMacula\Repositories\Concerns\CachePayloadEncoder;
use SineMacula\Repositories\Concerns\CacheSizeGuard;
use SineMacula\Repositories\Concerns\CacheStatus;
use SineMacula\Repositories\Concerns\CacheStore;
use SineMacula\Repositories\Concerns\CacheStoreOptions;
use SineMacula\Repositories\Enums\CacheKeys;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Models\TestUser;

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

    /** @var string The message carried by a failing cache store. */
    private const string STORE_FAILURE = 'cache down';

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
     * Test that the size guard weighs the payload the store holds, which can be
     * admitted under a ceiling the result itself would exceed.
     *
     * @return void
     */
    public function testSizeGuardWeighsTheStoredPayload(): void
    {
        $items   = new TestUser(['name' => 'Alice']);
        $payload = CachePayloadEncoder::encode($items);
        $ceiling = strlen(serialize($payload));

        self::assertGreaterThan($ceiling, strlen(serialize($items)));

        $store = new CacheStore(Cache::store('array'), 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, $ceiling), true, 10));

        $store->put(self::HASH, $items, 1);

        self::assertEquals($items, $store->fetch(self::HASH));
    }

    /**
     * Test that a result holding a value the cache cannot store is left
     * uncached and the skip is logged.
     *
     * @return void
     */
    public function testPutLeavesAResultTheCacheCannotStoreUncached(): void
    {
        Log::shouldReceive('debug')
            ->once()
            ->with('Query result holds a value the cache cannot store; read left uncached', ['table' => 'test-table', 'hash' => self::HASH]);

        $this->cacheStore->put(self::HASH, collect([new \ArrayObject]), 1);

        self::assertNull($this->cacheStore->fetch(self::HASH));
        self::assertFalse($this->cacheStore->getStatus()->isPopulated());
    }

    /**
     * Test that an entry which does not read back, such as one an earlier
     * version stored as objects, is fetched as absent.
     *
     * @return void
     */
    public function testFetchTreatsAnUnreadableEntryAsAbsent(): void
    {
        $store = new CacheStore(Cache::store('file'), 'legacy-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        Cache::store('file')->getStore()->put('repositories:repository-query:legacy-table:0:' . self::HASH, collect(['a']), 3600);

        self::assertNull($store->fetch(self::HASH));

        $store->put(self::HASH, collect(['b']), 1);

        self::assertEquals(collect(['b']), $store->fetch(self::HASH));
    }

    /**
     * Test that a negative entry is stored as a plain payload under the table's
     * generation.
     *
     * @return void
     */
    public function testPutMissStoresAPlainPayload(): void
    {
        $store = new CacheStore(Cache::store('file'), 'miss-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $store->putMiss(self::HASH);

        $key = 'repositories:repository-query:miss-table:' . self::generation('file', 'miss-table') . ':' . self::HASH;

        self::assertSame(CachePayloadEncoder::miss(), Cache::store('file')->get($key));
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
     * Test that flushTable replaces the table's generation token on each call
     * with one never used before, orphaning entries stored under the previous
     * token while leaving newer entries reachable.
     *
     * @return void
     */
    public function testFlushTableReplacesTheGenerationOnNonTaggableStore(): void
    {
        $store = new CacheStore(Cache::store('file'), 'versioned-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        Log::shouldReceive('error')->never();

        $store->put(self::HASH, collect(['first']), 1);

        $first = self::generation('file', 'versioned-table');

        $store->flushTable();

        $second = self::generation('file', 'versioned-table');

        self::assertNull($store->fetch(self::HASH));

        $store->put(self::HASH, collect(['second']), 1);

        self::assertNotNull($store->fetch(self::HASH));

        $store->flushTable();

        self::assertNull($store->fetch(self::HASH));
        self::assertCount(3, array_unique([$first, $second, self::generation('file', 'versioned-table')]));
        self::assertSame(32, strlen($second));
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
     * Test that non-taggable keys embed the table, the generation token, and
     * the fingerprint in the documented order, pinning the physical key layout
     * that replacing the token relies on.
     *
     * @return void
     */
    public function testNonTaggableKeysEmbedTableGenerationAndFingerprint(): void
    {
        $store = new CacheStore(Cache::store('file'), 'key-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $store->put(self::HASH, collect(['item']), 1);

        self::assertNotNull($store->getStore()->get('repositories:repository-query:key-table:' . self::generation('file', 'key-table') . ':' . self::HASH));
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
     * Test that a generation the store refuses to replace is still used by this
     * instance, so it stops serving pre-flush entries, and the refusal is
     * logged since other processes never observe it.
     *
     * @return void
     */
    public function testARefusedGenerationIsUsedLocallyAndLogged(): void
    {
        $cacheStore = $this->generationStore('refusing', static function (mixed $store): void {
            $store->shouldReceive('forever')->andReturn(false);
        });

        self::assertSame('pre-flush', $cacheStore->fetch(self::HASH));

        Log::shouldReceive('error')
            ->once()
            ->with('Table generation could not be replaced', [
                'table'          => 'generation-table',
                'generation_key' => 'repositories:repository-cache-version:generation-table',
            ]);

        $cacheStore->flushTable();

        self::assertNull($cacheStore->fetch(self::HASH));
    }

    /**
     * Test that a store which throws on replacing the generation takes the same
     * local fallback as one that refuses it, so a post-write flush still stops
     * this instance serving the entries it was meant to drop.
     *
     * @return void
     */
    public function testAGenerationTheStoreFailsToWriteIsUsedLocallyAndLogged(): void
    {
        $cacheStore = $this->generationStore('throwing', static function (mixed $store): void {
            $store->shouldReceive('forever')->andThrow(new \RuntimeException(self::STORE_FAILURE));
        });

        self::assertSame('pre-flush', $cacheStore->fetch(self::HASH));

        Log::shouldReceive('error')
            ->once()
            ->with('Table generation could not be stored due to a store error', \Mockery::on(
                static fn (array $context): bool => $context['table'] === 'generation-table'
                    && $context['generation_key']                     === 'repositories:repository-cache-version:generation-table'
                    && $context['exception'] instanceof \RuntimeException,
            ));

        $cacheStore->flushTable();

        self::assertNull($cacheStore->fetch(self::HASH));
    }

    /**
     * Test that an evicted generation token is replaced with a fresh one rather
     * than read as a fixed value, so entries stored before an earlier write
     * never become reachable again.
     *
     * @return void
     */
    public function testAnEvictedGenerationNeverRevivesOlderEntries(): void
    {
        $options = new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10);

        (new CacheStore(Cache::store('file'), 'evicted-table', $options))->put(self::HASH, collect(['stale']), 1);

        CacheStore::invalidateTable('file', 'evicted-table', registryEnabled: true);
        Cache::store('file')->forget('repositories:repository-cache-version:evicted-table');

        self::assertNull((new CacheStore(Cache::store('file'), 'evicted-table', $options))->fetch(self::HASH));
    }

    /**
     * Test that a generation minted for a table that has none is stored before
     * this instance uses it, so other instances share it.
     *
     * @return void
     */
    public function testAMintedGenerationIsSharedWithOtherInstances(): void
    {
        $options = new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10);

        (new CacheStore(Cache::store('file'), 'shared-table', $options))->put(self::HASH, collect(['item']), 1);

        self::assertNotNull((new CacheStore(Cache::store('file'), 'shared-table', $options))->fetch(self::HASH));
    }

    /**
     * Test that the static table invalidation stores a fresh generation token.
     *
     * @return void
     */
    public function testInvalidateTableStoresAFreshGeneration(): void
    {
        CacheStore::invalidateTable('file', 'static-table', registryEnabled: true);

        $first = self::generation('file', 'static-table');

        CacheStore::invalidateTable('file', 'static-table', registryEnabled: true);

        self::assertSame(32, strlen($first));
        self::assertNotSame($first, self::generation('file', 'static-table'));
    }

    /**
     * Test that a generation the store refuses to replace is logged from the
     * static invalidateTable() path, mirroring the instance flush path.
     *
     * @return void
     */
    public function testInvalidateTableLogsAGenerationTheStoreRefuses(): void
    {
        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('forever')->andReturn(false); // @phpstan-ignore method.notFound

        Cache::extend('refusing-static', fn (): Repository => new Repository($store));
        Config::set('cache.stores.refusing-static', ['driver' => 'refusing-static']);

        Log::shouldReceive('error')
            ->once()
            ->with('Table generation could not be replaced', [
                'store'          => 'refusing-static',
                'table'          => 'failing-table',
                'generation_key' => 'repositories:repository-cache-version:failing-table',
            ]);

        CacheStore::invalidateTable('refusing-static', 'failing-table', registryEnabled: true);
    }

    /**
     * Test that fetch() distinguishes an absent entry, a negative-cache marker,
     * and a cached value in a single lookup.
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
     * Read the generation token a non-taggable store holds for a table.
     *
     * @param  string  $store
     * @param  string  $table
     * @return string
     */
    private static function generation(string $store, string $table): string
    {
        $generation = Cache::store($store)->get('repositories:repository-cache-version:' . $table);

        self::assertIsString($generation);

        return $generation;
    }

    /**
     * Build a cache store over a stub holding a pre-flush entry under the
     * "current" generation, with its generation writes configured by the
     * caller.
     *
     * @param  string  $driver
     * @param  \Closure(\Mockery\MockInterface): void  $configure
     * @return \SineMacula\Repositories\Concerns\CacheStore
     */
    private function generationStore(string $driver, \Closure $configure): CacheStore
    {
        $entries = [
            'repositories:repository-cache-version:generation-table'               => 'current',
            'repositories:repository-query:generation-table:current:' . self::HASH => CachePayloadEncoder::encode('pre-flush'),
        ];

        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('get')->andReturnUsing(static fn (string $key): mixed => $entries[$key] ?? null); // @phpstan-ignore method.notFound

        $configure($store);

        Cache::extend($driver, fn (): Repository => new Repository($store));
        Config::set('cache.stores.' . $driver, ['driver' => $driver]);

        return new CacheStore(Cache::store($driver), 'generation-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));
    }
}
