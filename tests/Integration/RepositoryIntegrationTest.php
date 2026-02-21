<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
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
#[CoversClass(ManagesCriteria::class)]
class RepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Verify constructor initialization and boot behavior.
     *
     * @return void
     */
    public function testConstructorInitializesStateAndBootsRepository(): void
    {
        $repository = $this->repository();

        static::assertTrue($repository->booted);
        static::assertSame(0, $repository->persistentCriteriaCount());
        static::assertSame(0, $repository->transientCriteriaCount());
        static::assertSame(0, $repository->scopesCount());
        static::assertInstanceOf(TestUser::class, $repository->getModel());
    }

    /**
     * Verify the base boot() method executes when a child does not override it.
     *
     * @return void
     */
    public function testBaseBootMethodIsExecutedOnConstruction(): void
    {
        static::assertNotNull($this->app);

        $repository = $this->app->make(PlainTestUserRepository::class);

        static::assertInstanceOf(TestUser::class, $repository->getModel());
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

        static::assertInstanceOf(TestUser::class, $model);
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

        static::assertInstanceOf(BuilderContract::class, $query);
        static::assertSame(0, $repository->transientCriteriaCount());
        static::assertSame(0, $repository->scopesCount());
        static::assertInstanceOf(BuilderContract::class, $repository->newQuery());
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

        static::assertInstanceOf(BuilderContract::class, $repository->query());
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

        static::assertCount(1, $users);
        static::assertSame(0, $repository->transientCriteriaCount());
        static::assertSame(0, $repository->scopesCount());
        static::assertInstanceOf(TestUser::class, $repository->getModel());
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

        static::assertInstanceOf(TestUser::class, $user);
        static::assertSame('Alice', $user->getAttribute('name'));
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

        static::assertCount(2, $repository->query()->get());

        $repository->disableCriteria();

        static::assertCount(3, $repository->query()->get());

        $repository->useCriteria();
        static::assertCount(2, $repository->query()->get());

        $repository->enableCriteria();

        static::assertFalse($repository->isCriteriaDisabled());
        static::assertFalse($repository->isForceUsingCriteria());
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

        static::assertTrue($repository->isCriteriaSkipped());
        static::assertTrue($repository->isForceUsingCriteria());
        static::assertCount(3, $repository->query()->get());
        static::assertFalse($repository->isCriteriaSkipped());
        static::assertFalse($repository->isForceUsingCriteria());
        static::assertSame(0, $repository->transientCriteriaCount());
        static::assertCount(2, $repository->query()->get());
    }

    /**
     * Verify criteria can be removed by object instance and class-string.
     *
     * @return void
     */
    public function testRemoveCriteriaSupportsObjectAndClassStringRemoval(): void
    {
        $repository      = $this->repository();
        $active_criteria = new ActiveUsersCriterion;

        $repository->pushCriteria([$active_criteria, new NamedUsersCriterion('Alice')]);
        $repository->withCriteria(new NamedUsersCriterion('Bob'));

        static::assertCount(3, $repository->getCriteria());

        $repository->removeCriteria($active_criteria);
        static::assertCount(2, $repository->getCriteria());

        $repository->removeCriteria(NamedUsersCriterion::class);
        static::assertCount(0, $repository->getCriteria());
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
        static::assertSame(2, $repository->persistentCriteriaCount());

        $repository->removeCriteria(ActiveUsersCriterion::class);
        static::assertSame(1, $repository->persistentCriteriaCount());
    }

    /**
     * Verify direct scope application recovers from an invalid model reference.
     *
     * @return void
     */
    public function testApplyScopesRecoversFromInvalidInternalModelReference(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->forceModel(null);
        $repository->addScope(static function (BuilderContract $query): void {
            $query->where('active', true);
        });
        $repository->invokeApplyScopes();

        $model = $repository->currentModel();

        static::assertInstanceOf(BuilderContract::class, $model);
        static::assertCount(2, $model->get());
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

        static::assertSame('result', $result);
        static::assertSame(0, $repository->transientCriteriaCount());
        static::assertSame(0, $repository->scopesCount());
        static::assertInstanceOf(TestUser::class, $repository->getModel());
    }

    /**
     * Resolve the repository under test.
     *
     * @return \Tests\Support\Repositories\TestUserRepository
     */
    private function repository(): TestUserRepository
    {
        static::assertNotNull($this->app);

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
