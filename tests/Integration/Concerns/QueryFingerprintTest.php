<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * Tests for the QueryFingerprint helper's query, binding, eager-load, and
 * closure-identity folding.
 *
 * Distinct and stable comparisons are grouped behind data providers so each
 * fingerprinting concern stays covered without one method per scenario.
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
     * @return iterable<string, array{\Closure(): string, \Closure(): string}>
     */
    public static function distinctFingerprintProvider(): iterable
    {
        $captureFingerprint = static fn (string $name): string => QueryFingerprint::for(Tag::query()->with(['related' => static function ($query) use ($name): void {
            $query->where('name', $name);
        }]));

        $internalConstraint = strlen(...);

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
            function (): string {
                Config::set('database.connections.alternative', [
                    'driver'   => 'sqlite',
                    'database' => sys_get_temp_dir() . '/laravel-repositories-fingerprint-alt.sqlite',
                    'prefix'   => '',
                ]);

                return QueryFingerprint::for(Tag::query());
            },
            function (): string {
                Config::set('database.connections.alternative', [
                    'driver'   => 'sqlite',
                    'database' => sys_get_temp_dir() . '/laravel-repositories-fingerprint-alt.sqlite',
                    'prefix'   => '',
                ]);

                return QueryFingerprint::for((new Tag)->setConnection('alternative')->newQuery());
            },
        ];

        yield 'differing connection name' => [
            function (): string {
                Config::set('database.connections.testing_two', [
                    'driver'   => 'sqlite',
                    'database' => ':memory:',
                    'prefix'   => '',
                ]);

                return QueryFingerprint::for(Tag::query());
            },
            function (): string {
                Config::set('database.connections.testing_two', [
                    'driver'   => 'sqlite',
                    'database' => ':memory:',
                    'prefix'   => '',
                ]);

                return QueryFingerprint::for((new Tag)->setConnection('testing_two')->newQuery());
            },
        ];

        yield 'expression arguments' => [
            fn (): string => QueryFingerprint::for(Tag::query(), 'get', [[DB::raw('count(*) as aggregate')]]),
            fn (): string => QueryFingerprint::for(Tag::query(), 'get', [[DB::raw('sum(id) as aggregate')]]),
        ];

        yield 'differing model class on same table' => [
            fn (): string => QueryFingerprint::for(Tag::query()),
            fn (): string => QueryFingerprint::for(TagAlias::query()),
        ];

        yield 'eager load presence' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with('posts'), 'get'),
            fn (): string => QueryFingerprint::for(Tag::query(), 'get'),
        ];

        yield 'eager-load constraints with differing bodies' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => static function ($query): void {
                $query->where('active', true);
            }])),
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => static function ($query): void {
                $query->where('active', false);
            }])),
        ];

        yield 'eager-load constraints with differing captures' => [
            fn (): string => $captureFingerprint('php'),
            fn (): string => $captureFingerprint('laravel'),
        ];

        yield 'eager-load constraints defined in different files on the same line' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => AlignedConstraintA::make()])),
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => AlignedConstraintB::make()])),
        ];

        yield 'eager-load constraints bound to instances with differing state' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => (new BoundConstraint('php'))->make()])),
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => (new BoundConstraint('laravel'))->make()])),
        ];

        yield 'internal function constraint differs from an unconstrained read' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => $internalConstraint])),
            fn (): string => QueryFingerprint::for(Tag::query()),
        ];
    }

    /**
     * Test that fingerprinting scenarios which must never collide - differing
     * SQL, bindings, verbs, arguments, connections, models, or eager-load
     * identity - each yield a distinct fingerprint.
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
     * @return iterable<string, array{\Closure(): string, \Closure(): string}>
     */
    public static function stableFingerprintProvider(): iterable
    {
        $sharedConstraint = static function ($query): void {
            $query->where('active', true);
        };

        $internalConstraint = strlen(...);

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

        yield 'DateTime matches its ATOM string representation' => [
            function (): string {
                $moment = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

                return QueryFingerprint::for(Tag::query()->where('created_at', $moment));
            },
            function (): string {
                $moment = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

                return QueryFingerprint::for(Tag::query()->where('created_at', $moment->format(\DateTimeInterface::ATOM)));
            },
        ];

        yield 'eager load registration order does not affect the fingerprint' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['posts', 'articles']), 'get'),
            fn (): string => QueryFingerprint::for(Tag::query()->with(['articles', 'posts']), 'get'),
        ];

        yield 'unconstrained eager loads fingerprint stably' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with('related')),
            fn (): string => QueryFingerprint::for(Tag::query()->with('related')),
        ];

        yield 'the same closure instance fingerprints stably across calls' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => $sharedConstraint])),
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => $sharedConstraint])),
        ];

        yield 'an internal function constraint fingerprints stably across calls' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => $internalConstraint])),
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => $internalConstraint])),
        ];

        $mutableCapture       = new \stdClass;
        $mutableCapture->name = 'first capture';

        $memoisedConstraint = static function ($query) use ($mutableCapture): void {
            $query->where('name', $mutableCapture->name);
        };

        yield 'a closure fingerprint is memoised against its first captured state' => [
            fn (): string => QueryFingerprint::for(Tag::query()->with(['related' => $memoisedConstraint])),
            function () use ($mutableCapture, $memoisedConstraint): string {
                $mutableCapture->name = 'second capture';

                return QueryFingerprint::for(Tag::query()->with(['related' => $memoisedConstraint]));
            },
        ];
    }

    /**
     * Test that fingerprinting scenarios which must always collide - repeat
     * reads, equivalent bindings, reordered eager loads, or the same closure
     * fingerprinted more than once - each yield a stable fingerprint.
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
     * @return iterable<string, array{\Illuminate\Container\Container, \Illuminate\Container\Container, bool}>
     */
    public static function definitionPathNormalisationProvider(): iterable
    {
        $fixtureDirectory = dirname((string) realpath(dirname(__DIR__, 2) . '/Support/Closures/AlignedConstraintA.php'));

        yield 'a doubled trailing separator on the base path collapses to none' => [
            self::containerWithBasePath($fixtureDirectory),
            self::containerWithBasePath($fixtureDirectory . '//'),
            true,
        ];

        yield 'a single trailing separator on the base path behaves like none at all' => [
            self::containerWithBasePath($fixtureDirectory),
            self::containerWithBasePath($fixtureDirectory . \DIRECTORY_SEPARATOR),
            true,
        ];

        yield 'a base path that is not a prefix of the definition site leaves it unchanged' => [
            self::containerWithBasePath($fixtureDirectory),
            self::containerWithBasePath('/definitely-not-a-real-application-base-path'),
            false,
        ];

        yield 'an empty base path strips only a leading separator, not the definition directory' => [
            self::containerWithBasePath($fixtureDirectory),
            self::containerWithBasePath(''),
            false,
        ];

        yield 'no bound application behaves like a base path that is not a prefix' => [
            new Container,
            self::containerWithBasePath('/definitely-not-a-real-application-base-path'),
            true,
        ];
    }

    /**
     * Test that a closure's definition-site file path is normalised relative to
     * the application base path, so an unchanged file fingerprints identically
     * across releases regardless of how the base path is written, while a base
     * path that does not actually prefix the definition site - or the absence
     * of a bound application altogether - leaves the path, and therefore the
     * fingerprint, unchanged.
     *
     * @param  \Illuminate\Container\Container  $first
     * @param  \Illuminate\Container\Container  $second
     * @param  bool  $expectSameFingerprint
     * @return void
     */
    #[DataProvider('definitionPathNormalisationProvider')]
    public function testDefinitionPathIsNormalisedRelativeToTheApplicationBasePath(Container $first, Container $second, bool $expectSameFingerprint): void
    {
        $previous = Container::getInstance();

        try {
            Container::setInstance($first);

            $firstFingerprint = QueryFingerprint::for(self::queryConstrainedByAlignedConstraintA());

            Container::setInstance($second);

            $secondFingerprint = QueryFingerprint::for(self::queryConstrainedByAlignedConstraintA());
        } finally {
            Container::setInstance($previous);
        }

        if ($expectSameFingerprint) {
            self::assertSame($firstFingerprint, $secondFingerprint);
        } else {
            self::assertNotSame($firstFingerprint, $secondFingerprint);
        }
    }

    /**
     * Test that a closure passed as a verb argument cannot be fingerprinted,
     * since two distinct closures have no stable representation and must never
     * collide on one cache key.
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
     * Build a container that reports a fixed value for its base path, so
     * closure definition-path normalisation can be exercised against a known
     * prefix without a bound Laravel application.
     *
     * @param  string  $path
     * @return \Illuminate\Container\Container
     */
    private static function containerWithBasePath(string $path): Container
    {
        return new class ($path) extends Container {
            /**
             * @param  string  $path
             * @return void
             */
            public function __construct(

                /** The value returned for every base path lookup. */
                private readonly string $path,
            ) {}

            /**
             * @param  string  $path
             * @return string
             */
            public function basePath(string $path = ''): string
            {
                return $this->path;
            }
        };
    }

    /**
     * Build a tag query whose only eager load is the aligned constraint
     * fixture, registered directly so the fingerprinted closure is the fixture
     * itself rather than a framework-generated wrapper around it.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    private static function queryConstrainedByAlignedConstraintA(): Builder
    {
        $query = Tag::query();
        $query->setEagerLoads(['related' => AlignedConstraintA::make()]);

        return $query;
    }
}
