<?php

declare(strict_types = 1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use SineMacula\Repositories\Repository;
use Tests\Support\Concerns\InteractsWithUserRepository;
use Tests\Support\Criteria\ActiveUsersCriterion;
use Tests\Support\Criteria\NamedUsersCriterion;

/**
 * Tests for the criteria flag state machine across every flag combination.
 *
 * Skip overrides every other flag, force overrides disable for persistent
 * criteria, and disable never gates transient criteria.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Repository::class)]
#[CoversTrait(ManagesCriteria::class)]
final class CriteriaFlagStateTest extends IntegrationTestCase
{
    use InteractsWithUserRepository;

    /**
     * Provide every disable, skip, force and transient combination with the
     * number of users it returns.
     *
     * @return iterable<string, array{bool, bool, bool, bool, int}>
     */
    public static function flagStates(): iterable
    {
        yield 'no flags' => [false, false, false, false, 2];
        yield 'transient' => [false, false, false, true, 1];
        yield 'force' => [false, false, true, false, 2];
        yield 'force, transient' => [false, false, true, true, 1];
        yield 'skip' => [false, true, false, false, 3];
        yield 'skip, transient' => [false, true, false, true, 3];
        yield 'skip, force' => [false, true, true, false, 3];
        yield 'skip, force, transient' => [false, true, true, true, 3];
        yield 'disable' => [true, false, false, false, 3];
        yield 'disable, transient' => [true, false, false, true, 1];
        yield 'disable, force' => [true, false, true, false, 2];
        yield 'disable, force, transient' => [true, false, true, true, 1];
        yield 'disable, skip' => [true, true, false, false, 3];
        yield 'disable, skip, transient' => [true, true, false, true, 3];
        yield 'disable, skip, force' => [true, true, true, false, 3];
        yield 'every flag' => [true, true, true, true, 3];
    }

    /**
     * Test that the flags decide which criteria apply to the query.
     *
     * @param  bool  $disable
     * @param  bool  $skip
     * @param  bool  $force
     * @param  bool  $transient
     * @param  int  $expected
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[DataProvider('flagStates')]
    public function testFlagsDecideWhichCriteriaApply(bool $disable, bool $skip, bool $force, bool $transient, int $expected): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);

        if ($disable) {
            $repository->disableCriteria();
        }

        if ($force && $transient) {
            $repository = $repository->withCriteria(new NamedUsersCriterion('Alice'));
        } elseif ($force) {
            $repository = $repository->useCriteria();
        } elseif ($transient) {
            $repository->forceTransientCriteria($this->criteria(new NamedUsersCriterion('Alice')));
        }

        if ($skip) {
            $repository = $repository->skipCriteria();
        }

        self::assertCount($expected, $repository->query()->get());
    }

    /**
     * Verify the skip criteria flag resets to false after a query executes.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testSkipCriteriaFlagResetsAfterQuery(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository = $repository->skipCriteria();

        self::assertTrue($repository->isCriteriaSkipped());

        $repository->query();

        self::assertFalse($repository->isCriteriaSkipped());
    }

    /**
     * Verify the force-use criteria flag resets after a query executes.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testForceUseCriteriaFlagResetsAfterQuery(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();
        $repository = $repository->useCriteria();

        self::assertTrue($repository->isForceUsingCriteria());

        $repository->query();

        self::assertFalse($repository->isForceUsingCriteria());
    }

    /**
     * Verify the disable criteria flag persists across queries.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDisableCriteriaPersistsAfterQuery(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();
        $repository = $repository->useCriteria();

        $repository->query();

        self::assertTrue($repository->isCriteriaDisabled());
    }

    /**
     * Verify transient criteria are cleared after each query.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testTransientCriteriaClearedAfterQuery(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository = $repository->withCriteria(new NamedUsersCriterion('Alice'));

        self::assertSame(1, $repository->transientCriteriaCount());

        $repository->query();

        self::assertSame(0, $repository->transientCriteriaCount());
    }
}
