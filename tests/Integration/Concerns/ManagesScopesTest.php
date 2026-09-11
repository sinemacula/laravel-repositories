<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Repositories\Concerns\ManagesScopes;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Concerns\InteractsWithUserRepository;
use Tests\Support\Criteria\FailingUsersCriterion;
use Tests\Support\Exceptions\CompositionFailure;
use Tests\Support\Repositories\ScopedTestUserRepository;

/**
 * Tests for the ManagesScopes trait: the two scope lifetimes, and the three
 * places a composition can be abandoned.
 *
 * A composition can be abandoned at exactly three points, and each has a named
 * test here so none can be addressed in isolation again:
 *
 * 1. testScopeAbandonedInAConsumerMethodOutlivesTheCallButNotTheNextQuery - the
 *    throw is in a consumer's own scope method, before any query entry point.
 *    The package is not on the stack, so no guard can see it, and the composed
 *    state stays registered until a query consumes it.
 * 2. testScopeAbandonedInsideAScopeClosureIsDiscarded - the throw is in a scope
 *    closure, which runs inside query(), so the guard discards it.
 * 3. testCompositionAbandonedInsideQueryIsDiscarded - the throw is raised while
 *    query() applies criteria; the guard discards it too.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(ManagesScopes::class)]
final class ManagesScopesTest extends IntegrationTestCase
{
    use InteractsWithUserRepository;

    /** @var string The column a composed per-query scope constrains. */
    private const string COMPOSED_COLUMN = '"active"';

    /** @var string The ordering a registered scope establishes. */
    private const string REGISTERED_ORDER = 'order by "name" asc';

    /**
     * Verify a scope registered with pushScope() during boot() applies to every
     * query, unlike one added with addScope(), which the first query consumes.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRegisteredScopeAppliesToEveryQuery(): void
    {
        $repository = $this->scopedRepository();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertStringContainsString(self::REGISTERED_ORDER, $repository->query()->toSql());
        }
    }

    /**
     * Verify a registered scope survives resetScopes(), which clears only the
     * scopes composing the next query.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRegisteredScopeSurvivesAScopeReset(): void
    {
        $repository = $this->scopedRepository();

        $repository->scopeActive()->resetScopes();

        $sql = $repository->query()->toSql();

        self::assertStringContainsString(self::REGISTERED_ORDER, $sql);
        self::assertStringNotContainsString(self::COMPOSED_COLUMN, $sql);
    }

    /**
     * Verify a per-query scope applies after a registered one, so it can
     * override an ordering the registered set established.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testPerQueryScopeAppliesAfterARegisteredScope(): void
    {
        $repository = $this->scopedRepository();

        $sql = $repository
            ->addScope(static function (BuilderContract $query): void {
                $query->reorder('id', 'desc');
            })
            ->query()
            ->toSql();

        self::assertStringContainsString('order by "id" desc', $sql);
        self::assertStringNotContainsString('"name" asc', $sql);
    }

    /**
     * Verify resetScopes() returns a copy without the composing scopes and
     * leaves the handle it was called on carrying its own.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testResetScopesReturnsACopyWithoutTheComposingScopes(): void
    {
        $composed = $this->scopedRepository()
            ->scopeActive()
            ->addScope(static function (BuilderContract $query): void {
                $query->where('name', 'Alice');
            });

        self::assertSame(2, $composed->scopesCount());
        self::assertSame(0, $composed->resetScopes()->scopesCount());
        self::assertSame(2, $composed->scopesCount());
    }

    /**
     * Abandonment site 1: the throw is in a consumer's own scope method, before
     * any query entry point is reached.
     *
     * query() is never entered, so no guard can see the failure. Nothing needs
     * to: composition returns a copy, so the two scopes the aborted chain built
     * live only on copies the failed statement discards.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testScopeAbandonedInAConsumerMethodNeverReachesTheInstance(): void
    {
        $this->seedUsers();

        $repository = $this->scopedRepository();

        try {

            $repository->scopeActive()->scopeByNameThenAbort('Bob');
            self::fail('Expected the aborted scope method to throw.');
        } catch (CompositionFailure $exception) {
            self::assertSame('Scope composition aborted.', $exception->getMessage());
            self::assertSame(2, $exception->getCode());
        }

        self::assertSame(0, $repository->scopesCount());

        $sql = $repository->query()->toSql();

        self::assertStringNotContainsString(self::COMPOSED_COLUMN, $sql);
        self::assertStringNotContainsString('where', $sql);
        self::assertStringContainsString(self::REGISTERED_ORDER, $sql);
    }

    /**
     * Abandonment site 2: the throw is inside a scope closure, which runs
     * within query(), so the guard discards the composition.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testScopeAbandonedInsideAScopeClosureIsDiscarded(): void
    {
        $this->seedUsers();

        $repository = $this->scopedRepository();

        $composed = $repository
            ->scopeActive()
            ->addScope(static function (BuilderContract $query): void {

                $query->where('name', 'Bob');

                throw new CompositionFailure('Scope closure failed.');
            });

        try {

            $composed->query();
            self::fail('Expected the failing scope closure to propagate.');
        } catch (CompositionFailure $exception) {
            self::assertSame('Scope closure failed.', $exception->getMessage());
        }

        // The guard cleans the copy, so even a caller who kept it composes
        // cleanly next time, and the instance it came from never held anything.
        self::assertSame(0, $composed->scopesCount());
        self::assertSame(0, $repository->scopesCount());
        self::assertStringNotContainsString(self::COMPOSED_COLUMN, $repository->query()->toSql());
    }

    /**
     * Abandonment site 3: query() raises while applying criteria, before any
     * scope runs, and the guard discards the whole composition.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testCompositionAbandonedInsideQueryIsDiscarded(): void
    {
        $this->seedUsers();

        $repository = $this->scopedRepository();

        $composed = $repository->scopeActive()->withCriteria(new FailingUsersCriterion);

        self::assertSame(1, $composed->scopesCount());
        self::assertSame(1, $composed->transientCriteriaCount());
        self::assertSame(0, $repository->scopesCount());
        self::assertSame(0, $repository->transientCriteriaCount());

        try {

            $composed->query();
            self::fail('Expected the failing criterion to propagate.');
        } catch (CompositionFailure $exception) {
            self::assertSame(FailingUsersCriterion::MESSAGE, $exception->getMessage());
        }

        self::assertSame(0, $composed->scopesCount());
        self::assertSame(0, $composed->transientCriteriaCount());
        self::assertStringNotContainsString(self::COMPOSED_COLUMN, $repository->query()->toSql());
    }

    /**
     * Verify a subclass can drive scope application itself, which is why
     * applyScopes() is protected rather than private.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testApplyScopesIsCallableFromASubclassDrivingItsOwnComposition(): void
    {
        $repository = $this->scopedRepository()->scopeActive();

        $repository->forceModel($repository->getModel()->newQuery());

        $sql = $repository->composeInPlace()->toSql();

        self::assertStringContainsString(self::COMPOSED_COLUMN, $sql);
        self::assertStringContainsString(self::REGISTERED_ORDER, $sql);
    }

    /**
     * Resolve the scoped repository under test.
     *
     * @return \Tests\Support\Repositories\ScopedTestUserRepository
     */
    private function scopedRepository(): ScopedTestUserRepository
    {
        self::assertNotNull($this->app);

        return $this->app->make(ScopedTestUserRepository::class);
    }
}
