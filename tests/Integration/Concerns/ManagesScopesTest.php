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
 * Tests for the ManagesScopes trait: the two scope lifetimes and the three
 * places a composition can be abandoned.
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
     * Test that a scope registered with pushScope() applies to every query.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRegisteredScopeAppliesToEveryQuery(): void
    {
        $repository = $this->scopedRepository();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertStringContainsString(self::REGISTERED_ORDER, $repository->query()->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        }
    }

    /**
     * Test that a registered scope survives resetScopes().
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRegisteredScopeSurvivesAScopeReset(): void
    {
        $repository = $this->scopedRepository();

        $repository->scopeActive()->resetScopes();

        $sql = $repository->query()->toSql(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertStringContainsString(self::REGISTERED_ORDER, $sql);
        self::assertStringNotContainsString(self::COMPOSED_COLUMN, $sql);
    }

    /**
     * Test that a per-query scope applies after a registered one, so it can
     * override its ordering.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testPerQueryScopeAppliesAfterARegisteredScope(): void
    {
        $repository = $this->scopedRepository();

        $composed = $repository->addScope(static function (BuilderContract $query): void {
            $query->reorder('id', 'desc'); // @phpstan-ignore staticMethod.dynamicCall
        });

        $sql = $composed->query()->toSql(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertStringContainsString('order by "id" desc', $sql);
        self::assertStringNotContainsString('"name" asc', $sql);
    }

    /**
     * Test that resetScopes() returns a copy without the composing scopes.
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

        self::assertSame(1, $composed->persistentScopesCount());
        self::assertSame(1, $composed->resetScopes()->persistentScopesCount());
    }

    /**
     * Test that a scope abandoned in a consumer's scope method never reaches
     * the instance.
     *
     * query() is never entered, so the scopes live only on discarded copies.
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

        $sql = $repository->query()->toSql(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertStringNotContainsString(self::COMPOSED_COLUMN, $sql);
        self::assertStringNotContainsString('where', $sql);
        self::assertStringContainsString(self::REGISTERED_ORDER, $sql);
    }

    /**
     * Test that a scope closure throwing inside query() has its composition
     * discarded.
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

        // The guard cleans even the copy a caller kept.
        self::assertSame(0, $composed->scopesCount());
        self::assertSame(0, $repository->scopesCount());
        self::assertStringNotContainsString(self::COMPOSED_COLUMN, $repository->query()->toSql()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a composition abandoned while query() applies criteria is
     * discarded.
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
        self::assertStringNotContainsString(self::COMPOSED_COLUMN, $repository->query()->toSql()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a subclass can drive scope application itself.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testASubclassCanApplyScopesItself(): void
    {
        $repository = $this->scopedRepository()->scopeActive();

        $repository->forceModel($repository->getModel()->newQuery());

        $sql = $repository->composeInPlace()->toSql(); // @phpstan-ignore staticMethod.dynamicCall

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
