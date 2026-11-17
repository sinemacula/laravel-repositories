<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use SineMacula\Repositories\Repository;
use Tests\Support\Concerns\InteractsWithUserRepository;
use Tests\Support\Criteria\ActiveUsersCriterion;
use Tests\Support\Criteria\NamedUsersCriterion;

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
    use InteractsWithUserRepository;

    /**
     * Verify persistent criteria behavior across enable, disable, and use flow.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testPersistentCriteriaBehaviorAcrossEnableDisableAndUseFlow(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);

        self::assertCount(2, $repository->query()->get());

        $repository->disableCriteria();

        self::assertCount(3, $repository->query()->get());

        $repository = $repository->useCriteria();
        self::assertCount(2, $repository->query()->get());

        $repository->enableCriteria();

        self::assertFalse($repository->isCriteriaDisabled());
        self::assertFalse($repository->isForceUsingCriteria());
    }

    /**
     * Verify skipCriteria bypasses criteria for one query and resets flags.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testSkipCriteriaBypassesCriteriaForOneQuery(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);

        $composed = $repository
            ->withCriteria(new NamedUsersCriterion('Alice'))
            ->skipCriteria();

        self::assertTrue($composed->isCriteriaSkipped());
        self::assertTrue($composed->isForceUsingCriteria());

        // The flags are set on a copy, leaving the original untouched.
        self::assertFalse($repository->isCriteriaSkipped());
        self::assertFalse($repository->isForceUsingCriteria());

        self::assertCount(3, $composed->query()->get());
        self::assertFalse($composed->isCriteriaSkipped());
        self::assertFalse($composed->isForceUsingCriteria());
        self::assertSame(0, $composed->transientCriteriaCount());
        self::assertCount(2, $composed->query()->get());
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
        $repository = $repository->withCriteria(new NamedUsersCriterion('Bob'));

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
    public function testRemoveCriteriaIgnoresNonObjectsAndNonMatches(): void
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
        $repository = $repository->withCriteria(new NamedUsersCriterion('Alice'));

        self::assertSame(1, $repository->persistentCriteriaCount());
        self::assertSame(1, $repository->transientCriteriaCount());

        $repository->resetCriteria();

        self::assertSame(0, $repository->persistentCriteriaCount());
        self::assertSame(0, $repository->transientCriteriaCount());
    }
}
