<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Repositories\Concerns\QueryFingerprint;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Builders\InertBuilder;
use Tests\Support\Concerns\InteractsWithNonPublicMembers;
use Tests\Support\Enums\Status;
use Tests\Support\Models\Author;
use Tests\Support\Models\Book;
use Tests\Support\Models\Post;
use Tests\Support\Models\Shelf;
use Tests\Support\Models\Tag;
use Tests\Support\Models\TagAlias;
use Tests\Support\Models\TreeNode;
use Tests\Support\Scopes\TenantScope;

/**
 * Tests for the QueryFingerprint helper's query, binding, scope and eager-load
 * folding.
 *
 * Distinct, stable and unfingerprintable comparisons are grouped behind data
 * providers so each fingerprinting concern stays covered without one method per
 * scenario.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryFingerprint::class)]
#[CoversClass(UnfingerprintableQueryException::class)]
final class QueryFingerprintTest extends IntegrationTestCase
{
    use InteractsWithNonPublicMembers;

    /** @var string A representative moment used for date-bound fingerprints. */
    private const string MOMENT = '2026-01-01 00:00:00';

    /** @var string The refusal of a builder that is not an Eloquent builder. */
    private const string NOT_ELOQUENT = 'Only an Eloquent builder can be fingerprinted.';

    /** @var string The refusal of a query whose fingerprinting failed. */
    private const string UNABLE = 'Unable to fingerprint the given query.';

    /** @var string The refusal of an eager load of an unsupported relation. */
    private const string UNSUPPORTED_RELATION = 'An eager load of this relation type cannot be fingerprinted.';

    /** @var string The refusal of a query with after-query callbacks. */
    private const string AFTER_QUERY = 'A query with after-query callbacks cannot be fingerprinted.';

    /** @var string The refusal of a relation with a callable default. */
    private const string CALLABLE_DEFAULT = 'A relation with a callable default cannot be fingerprinted.';

    /** @var string The refusal of eager loads nested past the depth limit. */
    private const string TOO_DEEP = 'Eager loads nested this deep cannot be fingerprinted.';

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        self::actAsTenant('one');
    }

    /**
     * @return iterable<string, array{\Closure(): string, \Closure(): string}>
     */
    public static function distinctFingerprintProvider(): iterable
    {
        yield from self::distinctQueryProvider();
        yield from self::distinctScopeProvider();
        yield from self::distinctEagerLoadProvider();
        yield from self::distinctRelationKeyProvider();
    }

    /**
     * Test that fingerprinting scenarios which must never collide - differing
     * SQL, bindings, verbs, arguments, connections, models, scopes, casts or
     * eager loads - each yield a distinct fingerprint.
     *
     * @param  \Closure(): string  $first
     * @param  \Closure(): string  $second
     * @return void
     */
    #[DataProvider('distinctFingerprintProvider')]
    public function testDistinctScenariosYieldDistinctFingerprints(\Closure $first, \Closure $second): void
    {
        self::assertNotSame($first(), $second());
    }

    /**
     * Test that the default, trashed-inclusive and trashed-only reads of a
     * soft-deleting model yield three distinct fingerprints.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testEachTrashedVisibilityYieldsItsOwnFingerprint(): void
    {
        $fingerprints = [
            QueryFingerprint::for(Post::query()),
            QueryFingerprint::for(Post::query()->withTrashed()), // @phpstan-ignore method.notFound
            QueryFingerprint::for(Post::query()->onlyTrashed()), // @phpstan-ignore method.notFound
        ];

        self::assertCount(3, array_unique($fingerprints));
    }

    /**
     * @return iterable<string, array{\Closure(): string, \Closure(): string}>
     */
    public static function stableFingerprintProvider(): iterable
    {
        yield 'identical queries' => [
            fn (): string => QueryFingerprint::for(Tag::query()->where('name', 'php')),
            fn (): string => QueryFingerprint::for(Tag::query()->where('name', 'php')),
        ];

        yield 'Carbon binding stability' => [
            fn (): string => QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse(self::MOMENT))),
            fn (): string => QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse(self::MOMENT))),
        ];

        yield 'enum raw value matches its backing case' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'find', [Status::ACTIVE->value]),
            fn (): string => QueryFingerprint::for(Tag::query(), 'find', [Status::ACTIVE]),
        ];

        yield 'identical verb and arguments' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'find', [1]),
            fn (): string => QueryFingerprint::for(Tag::query(), 'find', [1]),
        ];

        yield 'a date binding matches the string the connection sends for it' => [
            fn (): string => QueryFingerprint::for(Tag::query()->where('created_at', new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC')))),
            fn (): string => QueryFingerprint::for(Tag::query()->where('created_at', '2026-01-01 12:00:00')),
        ];

        yield 'eager load registration order does not affect the fingerprint' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['children', 'aliases']), 'get'),
            fn (): string => QueryFingerprint::for(Tag::query()->with(['aliases', 'children']), 'get'),
        ];

        yield 'distinct constraint closures producing the same SQL' => [
            fn (): string => QueryFingerprint::for(Book::query()->with(['posts' => static fn (Builder $query): Builder => $query->where('title', 'live')])),
            fn (): string => QueryFingerprint::for(Book::query()->with(['posts' => static fn (Builder $query): Builder => $query->where('title', 'live')])),
        ];

        yield 'a constraint that changes nothing matches the unconstrained load' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['children' => static fn (Builder $query): Builder => $query])),
            fn (): string => QueryFingerprint::for(Tag::query()->with('children')),
        ];

        yield 'a nested load without its parent is never loaded' => [
            fn (): string => QueryFingerprint::for(Tag::query()->setEagerLoads(['children.aliases' => static fn (): null => null])),
            fn (): string => QueryFingerprint::for(Tag::query()),
        ];

        yield 'a chaperoned eager load' => [
            fn (): string => QueryFingerprint::for(Author::query()->with('books')),
            fn (): string => QueryFingerprint::for(Author::query()->with('books')),
        ];
    }

    /**
     * Test that fingerprinting scenarios which must always collide - repeat
     * reads, equivalent bindings, reordered eager loads, or constraints that
     * produce the same SQL - each yield a stable fingerprint.
     *
     * @param  \Closure(): string  $first
     * @param  \Closure(): string  $second
     * @return void
     */
    #[DataProvider('stableFingerprintProvider')]
    public function testStableScenariosYieldSameFingerprint(\Closure $first, \Closure $second): void
    {
        self::assertSame($first(), $second());
    }

    /**
     * @return iterable<string, array{\Closure(): string, string}>
     */
    public static function unfingerprintableProvider(): iterable
    {
        yield 'a builder that is not an Eloquent builder' => [
            fn (): string => QueryFingerprint::for(new InertBuilder),
            self::NOT_ELOQUENT,
        ];

        yield 'a closure argument' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'firstWhere', [static fn (Builder $query): Builder => $query->where('name', 'php')]),
            self::UNABLE,
        ];

        yield 'a MorphTo eager load' => [
            fn (): string => QueryFingerprint::for(Post::query()->with('subject')),
            self::UNSUPPORTED_RELATION,
        ];

        yield 'a relation class of the application\'s own' => [
            fn (): string => QueryFingerprint::for(Post::query()->with('rankedComments')),
            self::UNSUPPORTED_RELATION,
        ];

        yield 'an after-query callback on the root' => [
            fn (): string => QueryFingerprint::for(Tag::query()->afterQuery(self::unchanged(...))),
            self::AFTER_QUERY,
        ];

        yield 'an after-query callback on the base query' => [
            function (): string {
                $query = Tag::query();
                $query->getQuery()->afterQuery(self::unchanged(...));

                return QueryFingerprint::for($query);
            },
            self::AFTER_QUERY,
        ];

        yield 'an after-query callback added by a global scope' => [
            fn (): string => QueryFingerprint::for(Tag::query()->withGlobalScope('after', static fn (Builder $query): Builder => $query->afterQuery(self::unchanged(...)))),
            self::AFTER_QUERY,
        ];

        yield 'an after-query callback on a relation query' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['children' => static fn (Builder $query): Builder => $query->afterQuery(self::unchanged(...))])),
            self::AFTER_QUERY,
        ];

        yield 'an after-query callback on a chaperoned relation query' => [
            fn (): string => QueryFingerprint::for(Author::query()->with(['books' => static fn (Builder $query): Builder => $query->afterQuery(self::unchanged(...))])),
            self::AFTER_QUERY,
        ];

        yield 'a callable relation default' => [
            fn (): string => QueryFingerprint::for(Post::query()->with([
                'book' => static fn (BelongsTo $relation): BelongsTo => $relation->withDefault(static fn (): null => null),
            ])),
            self::CALLABLE_DEFAULT,
        ];

        yield 'an unknown relation' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with('missing')), // @phpstan-ignore larastan.relationExistence
            self::UNABLE,
        ];

        yield 'a constraint that throws' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['children' => static fn (): never => throw new \RuntimeException('constraint failed')])),
            self::UNABLE,
        ];

        yield 'a model that eager loads itself' => [
            fn (): string => QueryFingerprint::for(TreeNode::query()),
            self::TOO_DEEP,
        ];
    }

    /**
     * Test that a read whose result is shaped outside SQL, or whose arguments
     * have no stable representation, cannot be fingerprinted.
     *
     * @param  \Closure(): string  $fingerprint
     * @param  string  $message
     * @return void
     */
    #[DataProvider('unfingerprintableProvider')]
    public function testUnfingerprintableScenariosThrow(\Closure $fingerprint, string $message): void
    {
        $this->expectException(UnfingerprintableQueryException::class);
        $this->expectExceptionMessage($message);

        $fingerprint();
    }

    /**
     * Test that the chaperone callback of another relation is not taken for the
     * relation's own, so the read cannot be fingerprinted.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testAChaperoneCallbackOfAnotherRelationCannotBeFingerprinted(): void
    {
        $callbacks = $this->getProperty((new Author)->books()->getQuery(), 'afterQueryCallbacks');

        self::assertIsArray($callbacks);
        self::assertInstanceOf(\Closure::class, $callbacks[0]);

        $foreign = $callbacks[0];

        $this->expectException(UnfingerprintableQueryException::class);
        $this->expectExceptionMessage(self::AFTER_QUERY);

        QueryFingerprint::for(Author::query()->with(['books' => static fn (HasMany $books): HasMany => $books->afterQuery($foreign)]));
    }

    /**
     * Test that an eager-load chain just inside the depth limit is still
     * fingerprinted.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public function testEagerLoadsNestedUpToTheDepthLimitAreFingerprinted(): void
    {
        $path = implode('.', array_fill(0, 16, 'children'));

        self::assertNotSame('', QueryFingerprint::for(Tag::query()->with($path)));

        $this->expectException(UnfingerprintableQueryException::class);
        $this->expectExceptionMessage(self::TOO_DEEP);

        QueryFingerprint::for(Tag::query()->with($path . '.children'));
    }

    /**
     * @return iterable<string, array{\Closure(): \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>}>
     */
    public static function preparedBuilderProvider(): iterable
    {
        yield 'with registered global scopes' => [static fn (): EloquentBuilder => Post::query()->with('comments')];
        yield 'without registered global scopes' => [static fn (): EloquentBuilder => Tag::query()->with('children')];
    }

    /**
     * Test that fingerprinting leaves the prepared builder as it was: its
     * scopes pending, its before-query callbacks queued and its eager loads
     * registered.
     *
     * @param  \Closure(): \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $prepare
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    #[DataProvider('preparedBuilderProvider')]
    public function testFingerprintingLeavesThePreparedBuilderUntouched(\Closure $prepare): void
    {
        $query = $prepare();
        $query->getQuery()->beforeQuery(static fn (QueryBuilder $base): QueryBuilder => $base->where('id', '>', 0));

        $eagerLoads = array_keys($query->getEagerLoads());

        QueryFingerprint::for($query);

        self::assertSame([], $query->getQuery()->wheres);
        self::assertCount(1, $query->getQuery()->beforeQueryCallbacks);
        self::assertSame($eagerLoads, array_keys($query->getEagerLoads()));
    }

    /**
     * @return iterable<string, array{\Closure(): string, \Closure(): string}>
     */
    private static function distinctQueryProvider(): iterable
    {
        yield 'differing SQL' => [
            fn (): string => QueryFingerprint::for(Tag::query()),
            fn (): string => QueryFingerprint::for(Tag::query()->where('name', 'php')),
        ];

        yield 'differing bindings' => [
            fn (): string => QueryFingerprint::for(Tag::query()->where('id', 1)),
            fn (): string => QueryFingerprint::for(Tag::query()->where('id', 2)),
        ];

        yield 'same bindings, differing SQL' => [
            fn (): string => QueryFingerprint::for(Tag::query()->where('name', 'php')),
            fn (): string => QueryFingerprint::for(Tag::query()->where('name', '!=', 'php')),
        ];

        yield 'distinct Carbon bindings' => [
            fn (): string => QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse(self::MOMENT))),
            fn (): string => QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse('2026-07-01 00:00:00'))),
        ];

        yield 'enum argument yields distinct fingerprint per case' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'find', [Status::ACTIVE]),
            fn (): string => QueryFingerprint::for(Tag::query(), 'find', [Status::INACTIVE]),
        ];

        yield 'differing read verb' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'value', ['name']),
            fn (): string => QueryFingerprint::for(Tag::query(), 'get'),
        ];

        yield 'differing read verb with identical arguments' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'first'),
            fn (): string => QueryFingerprint::for(Tag::query(), 'get'),
        ];

        yield 'differing arguments' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'find', [1]),
            fn (): string => QueryFingerprint::for(Tag::query(), 'find', [2]),
        ];

        yield 'differing column list' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'get', [['id', 'name']]),
            fn (): string => QueryFingerprint::for(Tag::query(), 'get', [['id', 'email']]),
        ];

        yield 'non-JSON-encodable bindings' => [
            fn (): string => QueryFingerprint::for(Tag::query()->where('name', "\xB1\x31")),
            fn (): string => QueryFingerprint::for(Tag::query()->where('name', "\xB2\x32")),
        ];

        yield 'differing connection database' => [
            fn (): string => QueryFingerprint::for(Tag::query()),
            fn (): string => QueryFingerprint::for(self::alternativeConnectionQuery('alternative', sys_get_temp_dir() . '/laravel-repositories-fingerprint-alt.sqlite')),
        ];

        yield 'differing connection name' => [
            fn (): string => QueryFingerprint::for(Tag::query()),
            fn (): string => QueryFingerprint::for(self::alternativeConnectionQuery('testing_two', ':memory:')),
        ];

        yield 'expression arguments' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'get', [[DB::raw('count(*) as aggregate')]]),
            fn (): string => QueryFingerprint::for(Tag::query(), 'get', [[DB::raw('sum(id) as aggregate')]]),
        ];

        yield 'differing model class on same table' => [
            fn (): string => QueryFingerprint::for(Tag::query()),
            fn (): string => QueryFingerprint::for(TagAlias::query()),
        ];

        yield 'a date binding and its ATOM string, which the connection sends apart' => [
            fn (): string => QueryFingerprint::for(Tag::query()->where('created_at', new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC')))),
            fn (): string => QueryFingerprint::for(Tag::query()->where('created_at', '2026-01-01T12:00:00+00:00')),
        ];

        yield 'differing casts' => [
            fn (): string => QueryFingerprint::for(Tag::query()),
            fn (): string => QueryFingerprint::for(Tag::query()->withCasts(['name' => 'array'])),
        ];
    }

    /**
     * @return iterable<string, array{\Closure(): string, \Closure(): string}>
     */
    private static function distinctScopeProvider(): iterable
    {
        yield 'a global scope reading a different tenant' => [
            fn (): string => QueryFingerprint::for(Post::query()),
            function (): string {
                self::actAsTenant('two');

                return QueryFingerprint::for(Post::query());
            },
        ];

        yield 'a removed global scope' => [
            fn (): string => QueryFingerprint::for(Post::query()),
            fn (): string => QueryFingerprint::for(Post::query()->withoutGlobalScope(TenantScope::class)),
        ];

        yield 'an eager load added by a global scope' => [
            fn (): string => QueryFingerprint::for(Tag::query()),
            fn (): string => QueryFingerprint::for(Tag::query()->withGlobalScope('children', static fn (Builder $query): Builder => $query->with('children'))),
        ];
    }

    /**
     * @return iterable<string, array{\Closure(): string, \Closure(): string}>
     */
    private static function distinctEagerLoadProvider(): iterable
    {
        $state        = new \stdClass;
        $state->title = 'live';

        $constraint = static fn (Builder $query): Builder => $query->where('title', $state->title);

        yield 'eager load presence' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with('children'), 'get'),
            fn (): string => QueryFingerprint::for(Tag::query(), 'get'),
        ];

        yield 'a constraint reading state at run time' => [
            fn (): string => QueryFingerprint::for(Book::query()->with(['posts' => $constraint])),
            function () use ($state, $constraint): string {
                $state->title = 'removed';

                return QueryFingerprint::for(Book::query()->with(['posts' => $constraint]));
            },
        ];

        yield 'a global scope of the related model' => [
            fn (): string => QueryFingerprint::for(Book::query()->with('posts')),
            function (): string {
                self::actAsTenant('two');

                return QueryFingerprint::for(Book::query()->with('posts'));
            },
        ];

        yield 'a nested constraint' => [
            fn (): string => QueryFingerprint::for(Book::query()->with(['posts.comments' => static fn (Builder $query): Builder => $query->where('body', 'mine')])),
            fn (): string => QueryFingerprint::for(Book::query()->with(['posts.comments' => static fn (Builder $query): Builder => $query->where('body', 'theirs')])),
        ];

        yield 'casts set inside a constraint' => [
            fn (): string => QueryFingerprint::for(Book::query()->with('posts')),
            fn (): string => QueryFingerprint::for(Book::query()->with(['posts' => static fn (Builder $query): Builder => $query->withCasts(['title' => 'array'])])),
        ];

        yield 'pivot columns selected inside a constraint' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with('related')),
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => static fn (BelongsToMany $relation): BelongsToMany => $relation->withPivot('weight')])),
        ];

        yield 'a relation default set inside a constraint' => [
            fn (): string => QueryFingerprint::for(Post::query()->with('book')),
            fn (): string => QueryFingerprint::for(Post::query()->with(['book' => static fn (BelongsTo $relation): BelongsTo => $relation->withDefault(['title' => 'none'])])),
        ];

        yield 'a chaperone removed inside a constraint' => [
            fn (): string => QueryFingerprint::for(Author::query()->with('books')),
            fn (): string => QueryFingerprint::for(Author::query()->with(['books' => static fn (HasMany $relation): HasMany => $relation->withoutChaperone()])),
        ];
    }

    /**
     * Return a query result unchanged, as an after-query callback.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $result
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function unchanged(Collection $result): Collection
    {
        return $result;
    }

    /**
     * @return iterable<string, array{\Closure(): string, \Closure(): string}>
     */
    private static function distinctRelationKeyProvider(): iterable
    {
        $relations = [
            'owner'    => ['owner_id', 'keeper_id'],
            'books'    => ['shelf_id', 'stack_id'],
            'comments' => ['shelf_id', 'stack_id'],
            'labels'   => ['labelable_type', 'labelable_kind'],
            'genres'   => ['shelf_id', 'stack_id'],
            'tags'     => ['taggable', 'markable'],
        ];

        foreach ($relations as $relation => [$first, $second]) {
            yield 'the ' . $relation . ' relation keyed on a different column' => [
                fn (): string => self::fingerprintShelfWith($relation, $first),
                fn (): string => self::fingerprintShelfWith($relation, $second),
            ];
        }
    }

    /**
     * Fingerprint a shelf query eager loading the given relation, built while
     * the relation keys on the given column.
     *
     * @param  string  $relation
     * @param  string  $key
     * @return string
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    private static function fingerprintShelfWith(string $relation, string $key): string
    {
        Config::set(Shelf::KEY, $key);

        return QueryFingerprint::for(Shelf::query()->with($relation));
    }

    /**
     * Make the given tenant the current one.
     *
     * @param  string  $tenant
     * @return void
     */
    private static function actAsTenant(string $tenant): void
    {
        Container::getInstance()->instance(TenantScope::BINDING, $tenant);
    }

    /**
     * Build a tag query on a freshly configured alternative connection.
     *
     * @param  string  $connection
     * @param  string  $database
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Support\Models\Tag>
     */
    private static function alternativeConnectionQuery(string $connection, string $database): EloquentBuilder
    {
        Config::set('database.connections.' . $connection, [
            'driver'   => 'sqlite',
            'database' => $database,
            'prefix'   => '',
        ]);

        return (new Tag)->setConnection($connection)->newQuery();
    }
}
