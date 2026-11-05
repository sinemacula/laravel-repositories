<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Repositories\Concerns\CachePayloadDecoder;
use SineMacula\Repositories\Concerns\CachePayloadEncoder;
use SineMacula\Repositories\Concerns\CacheStore;
use SineMacula\Repositories\Concerns\ReferenceCache;
use Tests\Support\Collections\AuthorCollection;
use Tests\Support\Concerns\InteractsWithAuthors;
use Tests\Support\Models\Author;
use Tests\Support\Repositories\AuthorRepository;
use Tests\Support\Repositories\ReferenceAuthorRepository;

/**
 * End-to-end tests of the repository cache under a store that unserializes no
 * class, as Laravel's serializable_classes setting refuses them by default.
 *
 * One repository warms the cache and a fresh one reads it back, as the next
 * process would, so every result the first stores must survive being read
 * without its classes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CachePayloadDecoder::class)]
#[CoversClass(CachePayloadEncoder::class)]
#[CoversClass(CacheStore::class)]
#[CoversClass(ReferenceCache::class)]
final class ClassRefusingCacheTest extends IntegrationTestCase
{
    use InteractsWithAuthors;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->useClassRefusingCacheStore();
        $this->seedAuthors();
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
     * Provide every cached read verb against each class-refusing store.
     *
     * @return iterable<string, array{string, bool, string, array<int, array<int, int|string>|int|string>}>
     */
    public static function reads(): iterable
    {
        $reads = [
            'get with relations'    => [true, 'get', []],
            'find'                  => [false, 'find', [2]],
            'find many'             => [false, 'find', [[1, 2]]],
            'find nothing'          => [false, 'find', [99]],
            'first with relations'  => [true, 'first', []],
            'first where'           => [false, 'firstWhere', ['name', 'Grace']],
            'find or fail columns'  => [false, 'findOrFail', [1, ['id', 'name']]],
            'value cast to a date'  => [false, 'value', ['born_at']],
            'value cast to an enum' => [false, 'value', ['status']],
            'pluck cast to array'   => [false, 'pluck', ['meta']],
            'pluck keyed'           => [false, 'pluck', ['status', 'name']],
        ];

        foreach (['array' => 'class-refusing', 'file' => 'file'] as $label => $store) {
            foreach ($reads as $name => [$withGraph, $method, $arguments]) {
                yield $name . ' on the ' . $label . ' store' => [$store, $withGraph, $method, $arguments];
            }
        }
    }

    /**
     * Test that a read from a warm cache in a fresh repository returns what the
     * read that warmed it returned, without querying the database.
     *
     * @param  string  $store
     * @param  bool  $withGraph
     * @param  string  $method
     * @param  array<int, array<int, int|string>|int|string>  $arguments
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[DataProvider('reads')]
    public function testAWarmReadEqualsTheReadThatWarmedIt(string $store, bool $withGraph, string $method, array $arguments): void
    {
        $this->useStore($store);

        $cold = $this->read($withGraph, $method, $arguments);

        DB::enableQueryLog();

        $warm = $this->read($withGraph, $method, $arguments);

        self::assertCount(0, DB::getQueryLog());
        self::assertEquals($cold, $warm);
        self::assertSame(get_debug_type($cold), get_debug_type($warm));
    }

    /**
     * Test that the warm read keeps the classes, relations and shared instances
     * of the graph the cold read returned.
     *
     * @return void
     */
    public function testAWarmReadKeepsTheRelationGraph(): void
    {
        $this->authors()->scopeWithGraph()->get(); // @phpstan-ignore staticMethod.dynamicCall

        $warm = $this->authors()->scopeWithGraph()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(AuthorCollection::class, $warm);

        $author = $warm->firstOrFail();

        self::assertSame(['books', 'genres', 'labels'], array_keys($author->getRelations()));
        self::assertSame($author, $author->books->firstOrFail()->getRelation('author'));
        $pivot = $author->genres->firstOrFail()->getRelation('pivot');

        self::assertInstanceOf(Pivot::class, $pivot);
        self::assertSame(1, $pivot->getAttribute('position'));
        self::assertSame('ADA', $author->toArray()['display_name'] ?? null);
        self::assertArrayNotHasKey('secret', $author->toArray());
    }

    /**
     * Test that a result holding a value the cache cannot store is still
     * returned, and is read from the database each time.
     *
     * @return void
     */
    public function testAResultTheCacheCannotStoreIsReadEachTime(): void
    {
        DB::enableQueryLog();

        $first  = $this->authors()->value('settings'); // @phpstan-ignore staticMethod.dynamicCall
        $second = $this->authors()->value('settings'); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, DB::getQueryLog());
        self::assertInstanceOf(\ArrayObject::class, $second);
        self::assertEquals($first, $second);
    }

    /**
     * Test that a per-query entry an earlier version stored as objects is read
     * as a miss, then recomputed and overwritten.
     *
     * @return void
     */
    public function testAPerQueryEntryStoredAsObjectsIsRecomputedAndOverwritten(): void
    {
        $this->useStore('file');

        $key = $this->keyWrittenBy(function (): void {
            $this->read(true, 'get', []);
        });
        $cold = $this->authorGraph();

        Cache::store('file')->getStore()->put($key, $cold, 3600);

        DB::enableQueryLog();

        $healed = $this->authors()->scopeWithGraph()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertSame(4, $this->queryCount());
        self::assertEquals($cold, $healed);
        self::assertIsArray(Cache::store('file')->getStore()->get($key));

        DB::flushQueryLog();

        self::assertEquals($cold, $this->authors()->scopeWithGraph()->get()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame(0, $this->queryCount());
    }

    /**
     * Test that a reference-mode snapshot read back in a fresh repository
     * equals the table, and serves lookups without querying the database.
     *
     * @return void
     */
    public function testAWarmReferenceSnapshotEqualsTheTable(): void
    {
        $cold = $this->referenceAuthors()->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $warm  = $this->referenceAuthors();
        $table = $warm->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(AuthorCollection::class, $table);
        self::assertEquals($cold, $table);
        self::assertEquals(Author::query()->find(2), $warm->find(2)); // @phpstan-ignore staticMethod.dynamicCall

        // The only query is the comparison read above.
        self::assertCount(1, DB::getQueryLog());
    }

    /**
     * Test that a reference-mode snapshot an earlier version stored as objects
     * is read as a miss, then reloaded and overwritten.
     *
     * @return void
     */
    public function testAReferenceSnapshotStoredAsObjectsIsReloadedAndOverwritten(): void
    {
        $key = $this->keyWrittenBy(function (): void {
            $this->referenceAuthors()->get(); // @phpstan-ignore staticMethod.dynamicCall
        });

        $cold = Author::query()->get();

        Cache::store('class-refusing')->getStore()->put($key, $cold, 3600);

        DB::enableQueryLog();

        self::assertEquals($cold, $this->referenceAuthors()->get()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertCount(1, DB::getQueryLog());
        self::assertIsArray(Cache::store('class-refusing')->getStore()->get($key));
    }

    /**
     * Point the repository cache at the given store.
     *
     * @param  string  $store
     * @return void
     */
    private function useStore(string $store): void
    {
        Config::set('repositories.cache.store', $store);
    }

    /**
     * Run a read and get the key it stored its result under.
     *
     * @param  \Closure(): void  $read
     * @return string
     */
    private function keyWrittenBy(\Closure $read): string
    {
        $keys = [];

        Event::listen(KeyWritten::class, static function (KeyWritten $event) use (&$keys): void {
            if (!is_array($event->value) || !array_key_exists('value', $event->value)) {
                return;
            }

            $keys[] = $event->key;
        });

        $read();

        self::assertCount(1, $keys);

        return $keys[0];
    }

    /**
     * Run a read verb through a fresh author repository.
     *
     * @param  bool  $withGraph
     * @param  string  $method
     * @param  array<int, array<int, int|string>|int|string>  $arguments
     * @return array<array-key, int>|object|null
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function read(bool $withGraph, string $method, array $arguments): array|object|null
    {
        $authors = $withGraph ? $this->authors()->scopeWithGraph() : $this->authors();

        /** @var array<array-key, int>|object|null */
        return $authors->__call($method, $arguments);
    }

    /**
     * Count the queries logged since the log was last flushed.
     *
     * @return int
     */
    private function queryCount(): int
    {
        return count(DB::getQueryLog());
    }

    /**
     * Resolve a fresh author repository.
     *
     * @return \Tests\Support\Repositories\AuthorRepository
     */
    private function authors(): AuthorRepository
    {
        self::assertNotNull($this->app);

        return $this->app->make(AuthorRepository::class);
    }

    /**
     * Resolve a fresh reference-mode author repository.
     *
     * @return \Tests\Support\Repositories\ReferenceAuthorRepository
     */
    private function referenceAuthors(): ReferenceAuthorRepository
    {
        self::assertNotNull($this->app);

        return $this->app->make(ReferenceAuthorRepository::class);
    }
}
