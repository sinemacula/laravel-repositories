<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use SineMacula\Repositories\Repository;
use Tests\Support\Criteria\ActiveUsersCriterion;
use Tests\Support\Criteria\NamedUsersCriterion;
use Tests\Support\Repositories\TestUserRepository;

/**
 * Criteria flag state machine tests.
 *
 * Verifies the behavioral specification of the four-flag criteria state machine
 * across all 16 combinations of {$disableCriteria, $skipCriteria,
 * $forceUseCriteria, transient criteria present}.
 *
 * Flag precedence rules under test:
 * 1. $skipCriteria overrides all other flags (no criteria applied)
 * 2. $forceUseCriteria overrides $disableCriteria for persistent criteria
 * 3. $disableCriteria does NOT gate transient criteria
 *
 * Test data: 3 users seeded — Alice (active), Bob (inactive), Carol (active).
 * - No criteria applied → 3 results
 * - ActiveUsersCriterion (persistent) only → 2 results (Alice, Carol)
 * - NamedUsersCriterion('Alice') (transient) only → 1 result (Alice)
 * - Both criteria → 1 result (Alice)
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Repository::class)]
#[CoversClass(ManagesCriteria::class)]
class CriteriaFlagStateTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Group A: Criteria enabled ($disableCriteria=false), no skip
    // -------------------------------------------------------------------------

    /**
     * State 1: D=false, S=false, F=false, T=empty.
     *
     * Persistent criteria applied. No transient criteria present.
     *
     * @return void
     */
    public function testState01EnabledNoSkipNoForceNoTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);

        static::assertCount(2, $repository->query()->get());
    }

    /**
     * State 2: D=false, S=false, F=false, T=present.
     *
     * Both persistent and transient criteria applied.
     *
     * @return void
     */
    public function testState02EnabledNoSkipNoForceWithTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->forceTransientCriteria(collect([new NamedUsersCriterion('Alice')]));

        // Both applied: active AND named Alice → 1 result
        static::assertCount(1, $repository->query()->get());
    }

    // -------------------------------------------------------------------------
    // Group B: Skip active ($skipCriteria=true) — overrides everything
    // -------------------------------------------------------------------------

    /**
     * State 3: D=false, S=false, F=true, T=empty.
     *
     * Persistent criteria applied. $forceUseCriteria is redundant when
     * criteria are not disabled.
     *
     * @return void
     */
    public function testState03EnabledNoSkipForceNoTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->useCriteria();

        static::assertCount(2, $repository->query()->get());
    }

    /**
     * State 4: D=false, S=false, F=true, T=present.
     *
     * Both persistent and transient criteria applied. This is the typical
     * state after calling withCriteria().
     *
     * @return void
     */
    public function testState04EnabledNoSkipForceWithTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->withCriteria(new NamedUsersCriterion('Alice'));

        static::assertCount(1, $repository->query()->get());
    }

    /**
     * State 5: D=false, S=true, F=false, T=empty.
     *
     * Skip overrides: no criteria applied despite persistent being present.
     *
     * @return void
     */
    public function testState05EnabledSkipNoForceNoTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->skipCriteria();

        static::assertCount(3, $repository->query()->get());
    }

    /**
     * State 6: D=false, S=true, F=false, T=present.
     *
     * Skip overrides: both persistent and transient are skipped.
     *
     * @return void
     */
    public function testState06EnabledSkipNoForceWithTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->forceTransientCriteria(collect([new NamedUsersCriterion('Alice')]));
        $repository->skipCriteria();

        static::assertCount(3, $repository->query()->get());
    }

    // -------------------------------------------------------------------------
    // Group C: Criteria disabled ($disableCriteria=true), no skip
    // -------------------------------------------------------------------------

    /**
     * State 7: D=false, S=true, F=true, T=empty.
     *
     * Skip overrides force: no criteria applied.
     *
     * @return void
     */
    public function testState07EnabledSkipForceNoTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->useCriteria();
        $repository->skipCriteria();

        static::assertCount(3, $repository->query()->get());
    }

    /**
     * State 8: D=false, S=true, F=true, T=present.
     *
     * Skip overrides everything: all criteria skipped.
     *
     * @return void
     */
    public function testState08EnabledSkipForceWithTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->withCriteria(new NamedUsersCriterion('Alice'));
        $repository->skipCriteria();

        static::assertCount(3, $repository->query()->get());
    }

    /**
     * State 9: D=true, S=false, F=false, T=empty.
     *
     * Persistent criteria NOT applied (disabled). No transient present.
     *
     * @return void
     */
    public function testState09DisabledNoSkipNoForceNoTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();

        static::assertCount(3, $repository->query()->get());
    }

    /**
     * State 10: D=true, S=false, F=false, T=present.
     *
     * KEY BEHAVIOR: $disableCriteria does NOT gate transient criteria.
     * Persistent NOT applied, but transient IS applied.
     *
     * @return void
     */
    public function testState10DisabledNoSkipNoForceWithTransientTransientStillApplied(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();
        $repository->forceTransientCriteria(collect([new NamedUsersCriterion('Alice')]));

        // Transient applied (Alice only), persistent NOT applied → 1 result
        static::assertCount(1, $repository->query()->get());
    }

    // -------------------------------------------------------------------------
    // Group D: Disabled + Skip ($disableCriteria=true, $skipCriteria=true)
    // -------------------------------------------------------------------------

    /**
     * State 11: D=true, S=false, F=true, T=empty.
     *
     * $forceUseCriteria overrides $disableCriteria for persistent criteria.
     *
     * @return void
     */
    public function testState11DisabledNoSkipForceNoTransientForceOverridesDisable(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();
        $repository->useCriteria();

        static::assertCount(2, $repository->query()->get());
    }

    /**
     * State 12: D=true, S=false, F=true, T=present.
     *
     * Force overrides disable for persistent; transient always applied when not
     * skipped. Both criteria applied.
     *
     * @return void
     */
    public function testState12DisabledNoSkipForceWithTransientBothApplied(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();
        $repository->withCriteria(new NamedUsersCriterion('Alice'));

        static::assertCount(1, $repository->query()->get());
    }

    /**
     * State 13: D=true, S=true, F=false, T=empty.
     *
     * Skip overrides: no criteria applied. Disable is redundant.
     *
     * @return void
     */
    public function testState13DisabledSkipNoForceNoTransient(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();
        $repository->skipCriteria();

        static::assertCount(3, $repository->query()->get());
    }

    /**
     * State 14: D=true, S=true, F=false, T=present.
     *
     * Skip overrides disable and suppresses transient criteria.
     *
     * @return void
     */
    public function testState14DisabledSkipNoForceWithTransientSkipSuppressesAll(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();
        $repository->forceTransientCriteria(collect([new NamedUsersCriterion('Alice')]));
        $repository->skipCriteria();

        static::assertCount(3, $repository->query()->get());
    }

    // -------------------------------------------------------------------------
    // Flag reset verification
    // -------------------------------------------------------------------------

    /**
     * State 15: D=true, S=true, F=true, T=empty.
     *
     * Skip overrides both disable and force: no criteria applied.
     *
     * @return void
     */
    public function testState15DisabledSkipForceNoTransientSkipOverridesBoth(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();
        $repository->useCriteria();
        $repository->skipCriteria();

        static::assertCount(3, $repository->query()->get());
    }

    /**
     * State 16: D=true, S=true, F=true, T=present.
     *
     * All flags active. Skip wins: no criteria applied.
     *
     * @return void
     */
    public function testState16AllFlagsActiveSkipWins(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->disableCriteria();
        $repository->withCriteria(new NamedUsersCriterion('Alice'));
        $repository->skipCriteria();

        static::assertCount(3, $repository->query()->get());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Verify transient flags ($skipCriteria, $forceUseCriteria) reset after
     * each query while $disableCriteria persists.
     *
     * @return void
     */
    public function testTransientFlagsResetAfterQuery(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);

        // Skip for one query
        $repository->skipCriteria();
        static::assertTrue($repository->isCriteriaSkipped());
        $repository->query();
        static::assertFalse($repository->isCriteriaSkipped());

        // Force for one query
        $repository->disableCriteria();
        $repository->useCriteria();
        static::assertTrue($repository->isForceUsingCriteria());
        $repository->query();
        static::assertFalse($repository->isForceUsingCriteria());

        // disableCriteria persists
        static::assertTrue($repository->isCriteriaDisabled());
    }

    /**
     * Verify transient criteria are cleared after each query.
     *
     * @return void
     */
    public function testTransientCriteriaClearedAfterQuery(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->withCriteria(new NamedUsersCriterion('Alice'));

        static::assertSame(1, $repository->transientCriteriaCount());

        $repository->query();

        static::assertSame(0, $repository->transientCriteriaCount());
    }

    /**
     * Seed baseline users for flag state scenarios.
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
}
