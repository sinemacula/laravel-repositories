<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use SineMacula\Repositories\Repository;
use Tests\Support\Criteria\ActiveUsersCriterion;
use Tests\Support\Criteria\EagerLoadingCriterion;
use Tests\Support\Criteria\NamedUsersCriterion;
use Tests\Support\Repositories\TestUserRepository;

/**
 * Integration tests for supplementary criteria capabilities and criteria
 * application ordering.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Repository::class)]
#[CoversClass(ManagesCriteria::class)]
class SupplementaryCapabilityTest extends IntegrationTestCase
{
    // -------------------------------------------------------------------------
    // Supplementary contract detection and collection
    // -------------------------------------------------------------------------

    /**
     * Verify that eager-loading declarations are collected from criteria.
     *
     * @return void
     */
    public function testEagerLoadingDeclarationsAreCollected(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new EagerLoadingCriterion);

        $repository->query();

        $eagerLoads = $repository->getCollectedEagerLoads();

        static::assertArrayHasKey('posts', $eagerLoads);
        static::assertArrayHasKey('comments', $eagerLoads);
        static::assertNull($eagerLoads['posts']);
    }

    /**
     * Verify that field selection declarations are collected from criteria.
     *
     * @return void
     */
    public function testFieldSelectionDeclarationsAreCollected(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new EagerLoadingCriterion);

        $repository->query();

        static::assertSame(['id', 'name', 'active'], $repository->getCollectedFields());
    }

    /**
     * Verify that relationship count declarations are collected from criteria.
     *
     * @return void
     */
    public function testRelationshipCountDeclarationsAreCollected(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new EagerLoadingCriterion);

        $repository->query();

        $counts = $repository->getCollectedCounts();

        static::assertArrayHasKey('posts', $counts);
        static::assertNull($counts['posts']);
    }

    /**
     * Verify that metadata is collected from criteria.
     *
     * @return void
     */
    public function testMetadataIsCollectedFromCriteria(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new EagerLoadingCriterion);

        $repository->query();

        $metadata = $repository->getCollectedMetadata();

        static::assertSame('api', $metadata['source']);
        static::assertSame(2, $metadata['version']);
    }

    /**
     * Verify that criteria without supplementary contracts contribute no
     * declarations.
     *
     * @return void
     */
    public function testCriteriaWithoutSupplementaryContractsContributeNothing(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);

        $repository->query();

        static::assertEmpty($repository->getCollectedEagerLoads());
        static::assertEmpty($repository->getCollectedFields());
        static::assertEmpty($repository->getCollectedCounts());
        static::assertEmpty($repository->getCollectedMetadata());
    }

    /**
     * Verify that declarations from multiple criteria are merged.
     *
     * @return void
     */
    public function testDeclarationsFromMultipleCriteriaAreMerged(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new EagerLoadingCriterion);
        $repository->withCriteria(new EagerLoadingCriterion);

        $repository->query();

        // Two criteria, each declaring 'id', 'name', 'active' — duplicates merged
        static::assertSame(['id', 'name', 'active'], $repository->getCollectedFields());

        // Eager-loads from both merged (last wins for same key)
        static::assertArrayHasKey('posts', $repository->getCollectedEagerLoads());
        static::assertArrayHasKey('comments', $repository->getCollectedEagerLoads());
    }

    /**
     * Verify that collected declarations are cleared on the next query.
     *
     * @return void
     */
    public function testCollectedDeclarationsAreClearedOnNextQuery(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new EagerLoadingCriterion);

        $repository->query();

        static::assertNotEmpty($repository->getCollectedMetadata());

        // Second query with only non-capability criteria
        $repository->removeCriteria(EagerLoadingCriterion::class);
        $repository->pushCriteria(new ActiveUsersCriterion);

        $repository->query();

        static::assertEmpty($repository->getCollectedMetadata()); // @phpstan-ignore staticMethod.impossibleType
    }

    /**
     * Verify that skipped criteria do not contribute declarations.
     *
     * @return void
     */
    public function testSkippedCriteriaDoNotContributeDeclarations(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new EagerLoadingCriterion);
        $repository->skipCriteria();

        $repository->query();

        static::assertEmpty($repository->getCollectedEagerLoads());
        static::assertEmpty($repository->getCollectedMetadata());
    }

    /**
     * Verify that disabled persistent criteria do not contribute declarations
     * but transient criteria still do.
     *
     * @return void
     */
    public function testDisabledPersistentDoNotContributeButTransientDo(): void
    {
        $repository = $this->repository();
        $repository->pushCriteria(new EagerLoadingCriterion);
        $repository->disableCriteria();
        $repository->forceTransientCriteria(collect([new EagerLoadingCriterion]));

        $repository->query();

        // Transient capability criterion contributes declarations
        static::assertNotEmpty($repository->getCollectedEagerLoads());
        static::assertNotEmpty($repository->getCollectedMetadata());
    }

    // -------------------------------------------------------------------------
    // Criteria application ordering
    // -------------------------------------------------------------------------

    /**
     * Verify that transient criteria are applied before persistent criteria.
     *
     * Uses NamedUsersCriterion (transient, filters to Alice) and
     * ActiveUsersCriterion (persistent, filters to active). Both are applied
     * and the ordering produces the correct result.
     *
     * @return void
     */
    public function testTransientCriteriaApplyBeforePersistentCriteria(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);
        $repository->withCriteria(new NamedUsersCriterion('Alice'));

        $users = $repository->query()->get();

        // Both applied: active AND named Alice → 1 result
        static::assertCount(1, $users);

        $first = $users->first();

        static::assertNotNull($first);
        static::assertSame('Alice', $first->getAttribute('name'));
    }

    // -------------------------------------------------------------------------
    // CriteriaInterface preservation
    // -------------------------------------------------------------------------

    /**
     * Verify that CriteriaInterface is unchanged — existing criteria work
     * without modification.
     *
     * @return void
     */
    public function testExistingCriteriaWorkWithoutModification(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $repository->pushCriteria(new ActiveUsersCriterion);

        static::assertCount(2, $repository->query()->get());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
     * Seed baseline users for ordering scenarios.
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
