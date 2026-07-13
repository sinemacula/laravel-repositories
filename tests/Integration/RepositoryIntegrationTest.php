<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use SineMacula\Repositories\Repository;
use Tests\Support\Criteria\ActiveUsersCriterion;
use Tests\Support\Criteria\NamedUsersCriterion;
use Tests\Support\Models\TestUser;
use Tests\Support\Repositories\PlainTestUserRepository;
use Tests\Support\Repositories\TestUserRepository;

/**
 * Integration tests for repository query, scope, and criteria behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Repository::class)]
#[CoversTrait(ManagesCriteria::class)]
final class RepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Verify constructor initialization and boot behavior.
     *
     * @return void
     */
    public function testConstructorInitializesStateAndBootsRepository(): void
    {
        $repository = $this->repository();

        self::assertTrue($repository->booted);
        self::assertSame(0, $repository->persistentCriteriaCount());
        self::assertSame(0, $repository->transientCriteriaCount());
        self::assertSame(0, $repository->scopesCount());
        self::assertInstanceOf(TestUser::class, $repository->getModel());
    }

    /**
     * Verify the base boot() method executes when a child does not override it.
     *
     * @return void
     */
    public function testBaseBootMethodIsExecutedOnConstruction(): void
    {
        self::assertNotNull($this->app);

        $repository = $this->app->make(PlainTestUserRepository::class);

        self::assertInstanceOf(TestUser::class, $repository->getModel());
    }

    /**
     * Verify getModel restores a valid model when state is corrupted.
     *
     * @return void
     */
    public function testGetModelRestoresModelWhenInternalReferenceIsInvalid(): void
    {
        $repository = $this->repository();
        $repository->forceModel(null);

        $model = $repository->getModel();

        self::assertInstanceOf(TestUser::class, $model);
    }

    /**
     * Verify query() and newQuery() return builders and reset transient state.
     *
     * @return void
     */
    public function testQueryAndNewQueryReturnBuildersAndResetTransientState(): void
    {
        $repository = $this->repository();
        $repository
            ->withCriteria([new ActiveUsersCriterion, 'invalid'])
            ->addScope(static function (BuilderContract $query): void {
                $query->where('id', '>', 0);
            });

        $query = $repository->query();

        self::assertInstanceOf(BuilderContract::class, $query);
        self::assertSame(0, $repository->transientCriteriaCount());
        self::assertSame(0, $repository->scopesCount());
        self::assertInstanceOf(BuilderContract::class, $repository->newQuery());
    }

    /**
     * Verify applyCriteria restores the model when its internal state is null.
     *
     * @return void
     */
    public function testQueryRestoresModelWhenInternalModelIsNull(): void
    {
        $repository = $this->repository();
        $repository->forceModel(null);

        self::assertInstanceOf(BuilderContract::class, $repository->query());
    }

    /**
     * Verify __call forwards to the query builder and resets transient state.
     *
     * @return void
     */
    public function testCallForwardsMethodCallsToQueryBuilder(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $users      = $repository
            ->withCriteria(new ActiveUsersCriterion)
            ->addScope(static function (BuilderContract $query): void {
                $query->where('name', '!=', 'Carol');
            })
            ->__call('get', []);

        self::assertCount(1, $users);
        self::assertSame(0, $repository->transientCriteriaCount());
        self::assertSame(0, $repository->scopesCount());
        self::assertInstanceOf(TestUser::class, $repository->getModel());
    }

    /**
     * Verify __callStatic resolves the repository from the application.
     *
     * @return void
     */
    public function testStaticCallsResolveRepositoryFromApplicationContainer(): void
    {
        $this->seedUsers();

        $user = TestUserRepository::find(1);

        self::assertInstanceOf(TestUser::class, $user);
        self::assertSame('Alice', $user->getAttribute('name'));
    }

    /**
     * Verify persistent criteria behavior across enable, disable, and use flow.
     *
     * @return void
     */
    public function testPersistentCriteriaBehaviorAcrossEnableDisableAndUseFlow(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria([new ActiveUsersCriterion, 'invalid']);

        self::assertCount(2, $repository->query()->get());

        $repository->disableCriteria();

        self::assertCount(3, $repository->query()->get());

        $repository->useCriteria();
        self::assertCount(2, $repository->query()->get());

        $repository->enableCriteria();

        self::assertFalse($repository->isCriteriaDisabled());
        self::assertFalse($repository->isForceUsingCriteria());
    }

    /**
     * Verify skipCriteria bypasses criteria for one query and resets flags.
     *
     * @return void
     */
    public function testSkipCriteriaBypassesCriteriaForSingleQueryAndResetsFlags(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository
            ->pushCriteria(new ActiveUsersCriterion)
            ->withCriteria(new NamedUsersCriterion('Alice'))
            ->skipCriteria();

        self::assertTrue($repository->isCriteriaSkipped());
        self::assertTrue($repository->isForceUsingCriteria());
        self::assertCount(3, $repository->query()->get());
        self::assertFalse($repository->isCriteriaSkipped());
        self::assertFalse($repository->isForceUsingCriteria());
        self::assertSame(0, $repository->transientCriteriaCount());
        self::assertCount(2, $repository->query()->get());
    }

    /**
     * Verify criteria can be removed by object instance and class-string.
     *
     * @return void
     */
    public function testRemoveCriteriaSupportsObjectAndClassStringRemoval(): void
    {
        $repository     = $this->repository();
        $activeCriteria = new ActiveUsersCriterion;

        $repository->pushCriteria([$activeCriteria, new NamedUsersCriterion('Alice')]);
        $repository->withCriteria(new NamedUsersCriterion('Bob'));

        self::assertCount(3, $repository->getCriteria());

        $repository->removeCriteria($activeCriteria);
        self::assertCount(2, $repository->getCriteria());

        $repository->removeCriteria(NamedUsersCriterion::class);
        self::assertCount(0, $repository->getCriteria());
    }

    /**
     * Verify removal logic ignores non-objects and non-matching requests.
     *
     * @return void
     */
    public function testRemoveCriteriaGracefullyHandlesNonObjectAndNonMatchingValues(): void
    {
        $repository = $this->repository();

        /** @var \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\TestUser>> $persistent */
        $persistent = new Collection(['invalid', new ActiveUsersCriterion]);
        /** @var \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\TestUser>> $transient */
        $transient = new Collection(['invalid']);

        $repository->forcePersistentCriteria($persistent);
        $repository->forceTransientCriteria($transient);

        $repository->removeCriteria([new NamedUsersCriterion('Alice')]);
        self::assertSame(2, $repository->persistentCriteriaCount());

        $repository->removeCriteria(ActiveUsersCriterion::class);
        self::assertSame(1, $repository->persistentCriteriaCount());
    }

    /**
     * Verify query recovers from an invalid model reference and applies scopes.
     *
     * @return void
     */
    public function testQueryRecoversFromInvalidModelAndAppliesScopes(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->forceModel(null);
        $repository->addScope(static function (BuilderContract $query): void {
            $query->where('active', true);
        });

        $query = $repository->query();

        self::assertInstanceOf(BuilderContract::class, $query);
        self::assertCount(2, $query->get());
    }

    /**
     * Verify resetAndReturn clears transient state and preserves result value.
     *
     * @return void
     */
    public function testResetAndReturnClearsTransientState(): void
    {
        $repository = $this->repository();
        $repository
            ->withCriteria([new ActiveUsersCriterion, 'invalid'])
            ->addScope(static function (BuilderContract $query): void {
                $query->where('id', '>', 0);
            });

        $result = $repository->invokeResetAndReturn('result');

        self::assertSame('result', $result);
        self::assertSame(0, $repository->transientCriteriaCount());
        self::assertSame(0, $repository->scopesCount());
        self::assertInstanceOf(TestUser::class, $repository->getModel());
    }

    /**
     * Verify resetCriteria() clears both persistent and transient criteria.
     *
     * @return void
     */
    public function testResetCriteriaClearsBothPersistentAndTransientCriteria(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->withCriteria(new NamedUsersCriterion('Alice'));

        self::assertSame(1, $repository->persistentCriteriaCount());
        self::assertSame(1, $repository->transientCriteriaCount());

        $repository->resetCriteria();

        self::assertSame(0, $repository->persistentCriteriaCount());
        self::assertSame(0, $repository->transientCriteriaCount());
    }

    /**
     * Verify resetModel() restores a fresh model instance from the container.
     *
     * @return void
     */
    public function testResetModelRestoresFreshModelInstance(): void
    {
        $repository = $this->repository();
        $repository->forceModel(null);

        self::assertNull($repository->currentModel());

        $repository->resetModel();

        self::assertInstanceOf(TestUser::class, $repository->currentModel());
    }

    /**
     * Verify resetScopes() clears all registered scopes.
     *
     * @return void
     */
    public function testResetScopesClearsAllRegisteredScopes(): void
    {
        $repository = $this->repository();
        $repository->addScope(static function (BuilderContract $query): void {
            $query->where('active', true);
        });
        $repository->addScope(static function (BuilderContract $query): void {
            $query->where('name', 'Alice');
        });

        self::assertSame(2, $repository->scopesCount());

        $repository->resetScopes();

        self::assertSame(0, $repository->scopesCount());
    }

    /**
     * Test that a forwarded call which throws leaves no dirty builder or scope
     * state behind, so the next query composes from a clean slate instead of
     * inheriting the failed call's constraints.
     *
     * @return void
     */
    public function testFailedForwardedCallLeavesTransientStateClean(): void
    {
        $this->seedUsers();

        $repository = $this->repository();

        $repository
            ->withCriteria(new NamedUsersCriterion('Bob'))
            ->addScope(static function (BuilderContract $query): void {
                $query->where('active', false);
            });

        try {
            $repository->findOrFail(999); // @phpstan-ignore staticMethod.dynamicCall
            self::fail('Expected the forwarded findOrFail() to throw.');
        } catch (ModelNotFoundException) {
            // The dirty builder and scopes must not survive the failure.
        }

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(3, $result);
    }

    /**
     * Resolve the repository under test.
     *
     * @return \Tests\Support\Repositories\TestUserRepository
     */
    private function repository(): TestUserRepository
    {
        self::assertNotNull($this->app);

        return $this->app->make(TestUserRepository::class);
    }

    /**
     * Seed baseline users for repository integration scenarios.
     *
     * @return void
     */
    private function seedUsers(): void
    {
        DB::table('test_users')->insert([
            ['id' => 1, 'name' => 'Alice', 'active' => true],
            ['id' => 2, 'name' => 'Bob', 'active' => false],
            ['id' => 3, 'name' => 'Carol', 'active' => true],
        ]);
    }
}
