<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use SineMacula\Repositories\Repository;
use Tests\Support\Criteria\ActiveUsersCriterion;
use Tests\Support\Criteria\NamedUsersCriterion;
use Tests\Support\Repositories\TestUserRepository;

/**
 * Integration tests for repository persistent, transient, and one-shot criteria
 * management.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Repository::class)]
#[CoversTrait(ManagesCriteria::class)]
final class RepositoryCriteriaIntegrationTest extends IntegrationTestCase
{
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
        $repository      = $this->repository();
        $activeCriterion = new ActiveUsersCriterion;

        $repository->pushCriteria([$activeCriterion, new NamedUsersCriterion('Alice')]);
        $repository->withCriteria(new NamedUsersCriterion('Bob'));

        self::assertCount(3, $repository->getCriteria());

        $repository->removeCriteria($activeCriterion);
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
