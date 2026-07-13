<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Repositories\Concerns\QueryFingerprint;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Closures\AlignedConstraintA;
use Tests\Support\Closures\AlignedConstraintB;
use Tests\Support\Closures\BoundConstraint;
use Tests\Support\Enums\Status;
use Tests\Support\Models\Tag;
use Tests\Support\Models\TagAlias;

/**
 * Tests for the QueryFingerprint helper.
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
    /** @var string A representative moment used for date-bound fingerprints. */
    private const string MOMENT = '2026-01-01 00:00:00';

    /**
     * Test that an identical query yields a stable fingerprint.
     *
     * @return void
     */
    public function testIdenticalQueriesYieldStableFingerprint(): void
    {
        $first  = QueryFingerprint::for(Tag::query()->where('name', 'php'));
        $second = QueryFingerprint::for(Tag::query()->where('name', 'php'));

        self::assertSame($first, $second);
    }

    /**
     * Test that differing SQL yields a distinct fingerprint.
     *
     * @return void
     */
    public function testDifferingSqlYieldsDistinctFingerprint(): void
    {
        $unfiltered = QueryFingerprint::for(Tag::query());
        $filtered   = QueryFingerprint::for(Tag::query()->where('name', 'php'));

        self::assertNotSame($unfiltered, $filtered);
    }

    /**
     * Test that differing bindings yield a distinct fingerprint.
     *
     * @return void
     */
    public function testDifferingBindingsYieldDistinctFingerprint(): void
    {
        $one = QueryFingerprint::for(Tag::query()->where('id', 1));
        $two = QueryFingerprint::for(Tag::query()->where('id', 2));

        self::assertNotSame($one, $two);
    }

    /**
     * Test that two queries with identical bindings but differing SQL yield
     * distinct fingerprints, proving the compiled SQL is part of the digest
     * independently of the bindings.
     *
     * @return void
     */
    public function testSameBindingsDifferentSqlYieldDistinctFingerprint(): void
    {
        $equals    = QueryFingerprint::for(Tag::query()->where('name', 'php'));
        $notEquals = QueryFingerprint::for(Tag::query()->where('name', '!=', 'php'));

        self::assertNotSame($equals, $notEquals);
    }

    /**
     * Test that a Carbon binding produces a stable fingerprint across
     * equivalent instances.
     *
     * @return void
     */
    public function testCarbonBindingProducesStableFingerprint(): void
    {
        $first  = QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse(self::MOMENT)));
        $second = QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse(self::MOMENT)));

        self::assertSame($first, $second);
    }

    /**
     * Test that distinct Carbon bindings yield distinct fingerprints.
     *
     * @return void
     */
    public function testDistinctCarbonBindingsYieldDistinctFingerprints(): void
    {
        $january = QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse(self::MOMENT)));
        $july    = QueryFingerprint::for(Tag::query()->where('created_at', '>', Carbon::parse('2026-07-01 00:00:00')));

        self::assertNotSame($january, $july);
    }

    /**
     * Test that a backed enum passed as a verb argument yields a distinct
     * fingerprint per case, proving the enum normalisation branch is actually
     * reached (Laravel scalarises enum bindings before they ever reach the
     * fingerprint, so the binding path alone cannot exercise it).
     *
     * @return void
     */
    public function testEnumArgumentYieldsDistinctFingerprintPerCase(): void
    {
        $active   = QueryFingerprint::for(Tag::query(), 'find', [Status::ACTIVE]);
        $inactive = QueryFingerprint::for(Tag::query(), 'find', [Status::INACTIVE]);

        self::assertNotSame($active, $inactive);
    }

    /**
     * Test that the read verb is folded into the fingerprint, so two reads on
     * an identical base builder (e.g. value() vs get()) do not collide.
     *
     * @return void
     */
    public function testDifferingReadVerbYieldsDistinctFingerprint(): void
    {
        $value = QueryFingerprint::for(Tag::query(), 'value', ['name']);
        $get   = QueryFingerprint::for(Tag::query(), 'get');

        self::assertNotSame($value, $get);
    }

    /**
     * Test that the read verb alone discriminates the fingerprint, so two reads
     * with identical arguments but a different verb (e.g. first() vs get()) do
     * not collide.
     *
     * @return void
     */
    public function testDifferingReadVerbWithIdenticalArgumentsYieldsDistinctFingerprint(): void
    {
        $first = QueryFingerprint::for(Tag::query(), 'first');
        $get   = QueryFingerprint::for(Tag::query(), 'get');

        self::assertNotSame($first, $get);
    }

    /**
     * Test that the read verb arguments are folded into the fingerprint, so
     * find(1) and find(2) on an identical base builder do not collide - the
     * by-id read whose constraint is applied at execution time.
     *
     * @return void
     */
    public function testDifferingArgumentsYieldDistinctFingerprint(): void
    {
        $one = QueryFingerprint::for(Tag::query(), 'find', [1]);
        $two = QueryFingerprint::for(Tag::query(), 'find', [2]);

        self::assertNotSame($one, $two);
    }

    /**
     * Test that a differing column projection (the array argument to get())
     * yields a distinct fingerprint, so get(['id','name']) does not collide
     * with get(['id','email']).
     *
     * @return void
     */
    public function testDifferingColumnListYieldsDistinctFingerprint(): void
    {
        $name  = QueryFingerprint::for(Tag::query(), 'get', [['id', 'name']]);
        $email = QueryFingerprint::for(Tag::query(), 'get', [['id', 'email']]);

        self::assertNotSame($name, $email);
    }

    /**
     * Test that an identical verb and arguments on an identical base builder
     * yield a stable fingerprint, so a repeat read still hits the cache.
     *
     * @return void
     */
    public function testIdenticalVerbAndArgumentsYieldStableFingerprint(): void
    {
        $first  = QueryFingerprint::for(Tag::query(), 'find', [1]);
        $second = QueryFingerprint::for(Tag::query(), 'find', [1]);

        self::assertSame($first, $second);
    }

    /**
     * Test that the registered eager loads are folded into the fingerprint, so
     * with('posts')->get() does not collide with a plain get() (the eager loads
     * are invisible to the compiled base SQL).
     *
     * @return void
     */
    public function testDifferingEagerLoadsYieldDistinctFingerprint(): void
    {
        $eager = QueryFingerprint::for(Tag::query()->with('posts'), 'get');
        $plain = QueryFingerprint::for(Tag::query(), 'get');

        self::assertNotSame($eager, $plain);
    }

    /**
     * Test that the eager load ordering does not affect the fingerprint, so two
     * reads requesting the same relations in a different order still share a
     * cache entry.
     *
     * @return void
     */
    public function testEagerLoadOrderIsIgnored(): void
    {
        $first  = QueryFingerprint::for(Tag::query()->with('posts')->with('articles'), 'get');
        $second = QueryFingerprint::for(Tag::query()->with('articles')->with('posts'), 'get');

        self::assertSame($first, $second);
    }

    /**
     * Test that a DateTime binding is normalised to its ATOM representation, so
     * a query bound with a DateTime and one bound with the equivalent ATOM
     * string resolve to the same fingerprint.
     *
     * @return void
     */
    public function testDateTimeBindingMatchesItsAtomStringRepresentation(): void
    {
        $moment = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

        $object = QueryFingerprint::for(Tag::query()->where('created_at', $moment));
        $string = QueryFingerprint::for(Tag::query()->where('created_at', $moment->format(\DateTimeInterface::ATOM)));

        self::assertSame($object, $string);
    }

    /**
     * Test that bindings which cannot be JSON-encoded (e.g. invalid UTF-8) fall
     * back to a stable serialised representation that still distinguishes
     * distinct values.
     *
     * @return void
     */
    public function testNonJsonEncodableBindingsYieldDistinctFingerprints(): void
    {
        $one = QueryFingerprint::for(Tag::query()->where('name', "\xB1\x31"));
        $two = QueryFingerprint::for(Tag::query()->where('name', "\xB2\x32"));

        self::assertNotSame($one, $two);
    }

    /**
     * Test that the connection's database name is folded into the fingerprint,
     * so identical SQL against different databases never shares a cache entry.
     *
     * @return void
     */
    public function testDifferingConnectionDatabaseYieldsDistinctFingerprint(): void
    {
        Config::set('database.connections.alternative', [
            'driver'   => 'sqlite',
            'database' => sys_get_temp_dir() . '/laravel-repositories-fingerprint-alt.sqlite',
            'prefix'   => '',
        ]);

        $default     = QueryFingerprint::for(Tag::query());
        $alternative = QueryFingerprint::for((new Tag)->setConnection('alternative')->newQuery());

        self::assertNotSame($default, $alternative);
    }

    /**
     * Test that the connection name is folded into the fingerprint, so two
     * connections sharing a database name never share a cache entry.
     *
     * @return void
     */
    public function testDifferingConnectionNameYieldsDistinctFingerprint(): void
    {
        Config::set('database.connections.testing_two', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $default     = QueryFingerprint::for(Tag::query());
        $alternative = QueryFingerprint::for((new Tag)->setConnection('testing_two')->newQuery());

        self::assertNotSame($default, $alternative);
    }

    /**
     * Test that closure arguments cannot be fingerprinted: two distinct
     * closures have no stable representation, so fingerprinting must refuse
     * rather than let them collide on one cache key.
     *
     * @return void
     */
    public function testClosureArgumentsCannotBeFingerprinted(): void
    {
        $this->expectException(UnfingerprintableQueryException::class);

        QueryFingerprint::for(Tag::query(), 'firstWhere', [static function ($query): void {
            $query->where('name', 'php');
        }]);
    }

    /**
     * Test that raw expression arguments yield distinct fingerprints rather
     * than collapsing to an identical empty representation.
     *
     * @return void
     */
    public function testExpressionArgumentsYieldDistinctFingerprints(): void
    {
        $count = QueryFingerprint::for(Tag::query(), 'get', [[DB::raw('count(*) as aggregate')]]);
        $sum   = QueryFingerprint::for(Tag::query(), 'get', [[DB::raw('sum(id) as aggregate')]]);

        self::assertNotSame($count, $sum);
    }

    /**
     * Test that eager loads sharing a relation name but carrying different
     * constraint closures yield distinct fingerprints.
     *
     * @return void
     */
    public function testEagerLoadConstraintsYieldDistinctFingerprints(): void
    {
        $active = QueryFingerprint::for(Tag::query()->with(['related' => static function ($query): void {
            $query->where('active', true);
        }]));

        $inactive = QueryFingerprint::for(Tag::query()->with(['related' => static function ($query): void {
            $query->where('active', false);
        }]));

        self::assertNotSame($active, $inactive);
    }

    /**
     * Test that an eager-load constraint defined at one site but capturing
     * different values yields distinct fingerprints.
     *
     * @return void
     */
    public function testEagerLoadConstraintCapturesYieldDistinctFingerprints(): void
    {
        $fingerprints = [];

        foreach (['php', 'laravel'] as $name) {
            $fingerprints[] = QueryFingerprint::for(Tag::query()->with(['related' => static function ($query) use ($name): void {
                $query->where('name', $name);
            }]));
        }

        self::assertNotSame($fingerprints[0], $fingerprints[1]);
    }

    /**
     * Test that unconstrained eager loads keep a stable fingerprint across
     * separately built queries.
     *
     * @return void
     */
    public function testUnconstrainedEagerLoadsYieldStableFingerprint(): void
    {
        $first  = QueryFingerprint::for(Tag::query()->with('related'));
        $second = QueryFingerprint::for(Tag::query()->with('related'));

        self::assertSame($first, $second);
    }

    /**
     * Test that eager-load constraints defined in different files on the same
     * line with identical captures yield distinct fingerprints, proving the
     * defining file is part of a constraint's identity.
     *
     * @return void
     */
    public function testEagerLoadConstraintFilesYieldDistinctFingerprints(): void
    {
        $first  = QueryFingerprint::for(Tag::query()->with(['related' => AlignedConstraintA::make()]));
        $second = QueryFingerprint::for(Tag::query()->with(['related' => AlignedConstraintB::make()]));

        self::assertNotSame($first, $second);
    }

    /**
     * Test that two models sharing a table yield distinct fingerprints, so a
     * cache entry populated for one model is never served to the other.
     *
     * @return void
     */
    public function testDifferingModelClassOnSameTableYieldsDistinctFingerprint(): void
    {
        $tag      = QueryFingerprint::for(Tag::query());
        $tagAlias = QueryFingerprint::for(TagAlias::query());

        self::assertNotSame($tag, $tagAlias);
    }

    /**
     * Test that two eager-load constraint closures produced from the same
     * definition site but bound to instances with different state yield
     * distinct fingerprints.
     *
     * @return void
     */
    public function testEagerLoadConstraintBoundInstanceStateYieldsDistinctFingerprints(): void
    {
        $php     = QueryFingerprint::for(Tag::query()->with(['related' => (new BoundConstraint('php'))->make()]));
        $laravel = QueryFingerprint::for(Tag::query()->with(['related' => (new BoundConstraint('laravel'))->make()]));

        self::assertNotSame($php, $laravel);
    }

    /**
     * Test that fingerprinting the same closure instance twice yields a
     * stable fingerprint, covering the per-request memoisation of the
     * closure's reflected and serialised state.
     *
     * @return void
     */
    public function testSameClosureInstanceYieldsStableFingerprintAcrossCalls(): void
    {
        $constraint = static function ($query): void {
            $query->where('active', true);
        };

        $first  = QueryFingerprint::for(Tag::query()->with(['related' => $constraint]));
        $second = QueryFingerprint::for(Tag::query()->with(['related' => $constraint]));

        self::assertSame($first, $second);
    }

    /**
     * Test that a closure's definition-site path still fingerprints when no
     * Laravel application is bound, falling back gracefully rather than
     * failing the read.
     *
     * @return void
     */
    public function testDefinitionPathFallsBackGracefullyWithoutABoundApplication(): void
    {
        $container = Container::getInstance();

        Container::setInstance(new Container);

        try {
            $fingerprint = QueryFingerprint::for(Tag::query()->with(['related' => AlignedConstraintA::make()]));
        } finally {
            Container::setInstance($container);
        }

        self::assertIsString($fingerprint);
    }
}
