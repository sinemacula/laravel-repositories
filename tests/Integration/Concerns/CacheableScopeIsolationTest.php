<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Repositories\Concerns\Cacheable;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Concerns\InteractsWithPosts;
use Tests\Support\Repositories\PostRepository;

/**
 * Tests that cached reads which Eloquent runs as different SQL, through global
 * scopes or run-time eager-load constraints, never share a cache entry.
 *
 * @phpstan-type TenantRead \Closure(\Tests\Support\Repositories\PostRepository): (array<int, string|null>|string|null)
 * @phpstan-type Titles array<int, string>|string|null
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(Cacheable::class)]
final class CacheableScopeIsolationTest extends IntegrationTestCase
{
    use InteractsWithPosts;

    /** @var string The container binding an eager-load constraint reads when it runs. */
    private const string BODY = 'tests.comment-body';

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

        $this->seedPosts();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function orders(): iterable
    {
        yield 'trashed first' => [true];
        yield 'default first' => [false];
    }

    /**
     * Test that a read including trashed rows and the default read are cached
     * apart, whichever runs first, and each is then served from the cache.
     *
     * @param  bool  $trashedFirst
     * @return void
     */
    #[DataProvider('orders')]
    public function testTrashedAndDefaultReadsAreCachedApart(bool $trashedFirst): void
    {
        $reads = [
            'trashed' => fn (): array => self::titles($this->withTrashed()->get()), // @phpstan-ignore staticMethod.dynamicCall
            'default' => fn (): array => self::titles($this->posts()->get()), // @phpstan-ignore staticMethod.dynamicCall
        ];

        foreach ($trashedFirst ? ['trashed', 'default'] : ['default', 'trashed'] as $read) {
            $reads[$read]();
        }

        DB::enableQueryLog();

        self::assertSame(['live', 'removed'], $reads['trashed']());
        self::assertSame(['live'], $reads['default']());
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a cached miss for a trashed row is not served to a read that
     * includes trashed rows, and that the row it caches is not served to the
     * default read, whichever runs first.
     *
     * @param  bool  $trashedFirst
     * @return void
     */
    #[DataProvider('orders')]
    public function testACachedMissIsNotServedAcrossTrashedVisibility(bool $trashedFirst): void
    {
        if ($trashedFirst) {
            self::assertInstanceOf(Model::class, $this->withTrashed()->firstWhere('title', 'removed')); // @phpstan-ignore staticMethod.dynamicCall
        }

        self::assertNull($this->posts()->firstWhere('title', 'removed')); // @phpstan-ignore staticMethod.dynamicCall
        self::assertInstanceOf(Model::class, $this->withTrashed()->firstWhere('title', 'removed')); // @phpstan-ignore staticMethod.dynamicCall
        self::assertNull($this->posts()->firstWhere('title', 'removed')); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a date binding and a string the database compares differently
     * are cached apart, although both describe the same moment.
     *
     * @return void
     */
    public function testADateBindingAndItsAtomStringAreCachedApart(): void
    {
        $read = fn (\DateTimeInterface|string $deletedAt): array => self::titles($this->withTrashed()->addScope(static function (Builder $query) use ($deletedAt): void {
            $query->where('deleted_at', $deletedAt);
        })->get()); // @phpstan-ignore staticMethod.dynamicCall

        self::assertSame(['removed'], $read(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))));
        self::assertSame([], $read('2026-01-01T00:00:00+00:00'));
    }

    /**
     * @return iterable<string, array{TenantRead, Titles, Titles}>
     */
    public static function tenantReads(): iterable
    {
        yield 'get' => [static fn (PostRepository $posts): array => self::titles($posts->get()), ['live'], ['other']]; // @phpstan-ignore staticMethod.dynamicCall
        yield 'find' => [static fn (PostRepository $posts): ?string => self::title($posts->find(1)), 'live', null]; // @phpstan-ignore staticMethod.dynamicCall
        yield 'first' => [static fn (PostRepository $posts): ?string => self::title($posts->first()), 'live', 'other']; // @phpstan-ignore staticMethod.dynamicCall
        yield 'pluck' => [static fn (PostRepository $posts): string => $posts->pluck('title')->implode(','), 'live', 'other']; // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a read under a tenant global scope is cached per tenant, so one
     * tenant is never served another's rows.
     *
     * @param  TenantRead  $read
     * @param  Titles  $tenantOne
     * @param  Titles  $tenantTwo
     * @return void
     */
    #[DataProvider('tenantReads')]
    public function testTenantsAreCachedApart(\Closure $read, array|string|null $tenantOne, array|string|null $tenantTwo): void
    {
        self::assertSame($tenantOne, $read($this->posts()));

        $this->actAsTenant('two');

        self::assertSame($tenantTwo, $read($this->posts()));

        $this->actAsTenant('one');

        DB::enableQueryLog();

        self::assertSame($tenantOne, $read($this->posts()));
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that an eager-load constraint reading the container when it runs, as
     * one reading the authenticated user does, is cached per value it reads.
     *
     * @return void
     */
    public function testARunTimeEagerLoadConstraintIsCachedPerValue(): void
    {
        $read = function (): string {

            $post = $this->posts()->addScope(static function (Builder $query): void {
                $query->with(['comments' => static fn (Builder $comments): Builder => $comments->where('body', Container::getInstance()->make(self::BODY))]);
            })->first(); // @phpstan-ignore staticMethod.dynamicCall

            self::assertInstanceOf(Model::class, $post);

            $comments = $post->getRelation('comments');

            self::assertInstanceOf(Collection::class, $comments);

            return $comments->pluck('body')->implode(',');
        };

        Container::getInstance()->instance(self::BODY, 'mine');

        self::assertSame('mine', $read());

        Container::getInstance()->instance(self::BODY, 'none');

        self::assertSame('', $read());
    }

    /**
     * Test that a read eager loading a MorphTo relation is never cached.
     *
     * @return void
     */
    public function testAMorphToReadQueriesOnEveryCall(): void
    {
        $read = function (): void {
            $this->posts()->addScope(static function (Builder $query): void {
                $query->with('subject');
            })->get(); // @phpstan-ignore staticMethod.dynamicCall
        };

        $read();

        DB::enableQueryLog();

        $read();

        self::assertNotEmpty(DB::getQueryLog());
    }

    /**
     * Resolve the repository under test.
     *
     * @return \Tests\Support\Repositories\PostRepository
     */
    private function posts(): PostRepository
    {
        self::assertNotNull($this->app);

        return $this->app->make(PostRepository::class);
    }

    /**
     * Resolve the repository under test, composed to include trashed rows.
     *
     * @return \Tests\Support\Repositories\PostRepository
     */
    private function withTrashed(): PostRepository
    {
        return $this->posts()->addScope(static function (Builder $query): void {
            $query->withTrashed(); // @phpstan-ignore method.notFound
        });
    }

    /**
     * Get the titles of the given posts.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $posts
     * @return array<int, string|null>
     */
    private static function titles(Collection $posts): array
    {
        return $posts->map(self::title(...))->values()->all();
    }

    /**
     * Get the title of a post, or null when there is none.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $post
     * @return string|null
     */
    private static function title(?Model $post): ?string
    {
        $title = $post?->getAttribute('title');

        return is_string($title) ? $title : null;
    }
}
