<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Repositories\Concerns\QueryFingerprint;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Closures\AlignedConstraintA;
use Tests\Support\Closures\AlignedConstraintB;
use Tests\Support\Closures\BoundConstraint;
use Tests\Support\Models\Tag;

/**
 * Tests for the QueryFingerprint helper's eager-load constraint closure
 * identity.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryFingerprint::class)]
#[CoversClass(UnfingerprintableQueryException::class)]
final class QueryFingerprintClosureTest extends IntegrationTestCase
{
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
     * Test that closure arguments cannot be fingerprinted: two distinct
     * closures have no stable representation, so fingerprinting must refuse
     * rather than let them collide on one cache key.
     *
     * @return void
     */
    public function testClosureArgumentsCannotBeFingerprinted(): void
    {
        $this->expectException(UnfingerprintableQueryException::class);
        $this->expectExceptionMessage('Unable to fingerprint the given query.');

        QueryFingerprint::for(Tag::query(), 'firstWhere', [static function ($query): void {
            $query->where('name', 'php');
        }]);
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

    /**
     * Test that a constraint wrapping an internal function, which has no
     * definition site to reflect, still fingerprints deterministically and
     * remains distinct from an unconstrained read.
     *
     * @return void
     */
    public function testInternalFunctionConstraintFingerprintsWithoutADefinitionSite(): void
    {
        $constraint = strlen(...);

        $first  = QueryFingerprint::for(Tag::query()->with(['related' => $constraint]));
        $second = QueryFingerprint::for(Tag::query()->with(['related' => $constraint]));
        $plain  = QueryFingerprint::for(Tag::query());

        self::assertSame($first, $second);
        self::assertNotSame($first, $plain);
    }
}
