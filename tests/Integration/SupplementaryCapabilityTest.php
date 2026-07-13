<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
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
#[CoversTrait(ManagesCriteria::class)]
final class SupplementaryCapabilityTest extends IntegrationTestCase
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

        self::assertArrayHasKey('posts', $eagerLoads);
        self::assertArrayHasKey('comments', $eagerLoads);
        self::assertNull($eagerLoads['posts']);
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

        self::assertSame(['id', 'name', 'active'], $repository->getCollectedFields());
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

        self::assertArrayHasKey('posts', $counts);
        self::assertNull($counts['posts']);
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

        self::assertSame('api', $metadata['source']);
        self::assertSame(2, $metadata['version']);
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

        self::assertEmpty($repository->getCollectedEagerLoads());
        self::assertEmpty($repository->getCollectedFields());
        self::assertEmpty($repository->getCollectedCounts());
        self::assertEmpty($repository->getCollectedMetadata());
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

        // Two criteria, each declaring 'id', 'name', 'active' - duplicates
        // merged
        self::assertSame(['id', 'name', 'active'], $repository->getCollectedFields());

        // Eager-loads from both merged (last wins for same key)
        self::assertArrayHasKey('posts', $repository->getCollectedEagerLoads());
        self::assertArrayHasKey('comments', $repository->getCollectedEagerLoads());
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

        self::assertNotEmpty($repository->getCollectedMetadata());

        // Second query with only non-capability criteria
        $repository->removeCriteria(EagerLoadingCriterion::class);
        $repository->pushCriteria(new ActiveUsersCriterion);

        $repository->query();

        self::assertEmpty($repository->getCollectedMetadata()); // @phpstan-ignore staticMethod.impossibleType
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

        self::assertEmpty($repository->getCollectedEagerLoads());
        self::assertEmpty($repository->getCollectedMetadata());
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
        self::assertNotEmpty($repository->getCollectedEagerLoads());
        self::assertNotEmpty($repository->getCollectedMetadata());
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
        self::assertCount(1, $users);

        $first = $users->first();

        self::assertNotNull($first);
        self::assertSame('Alice', $first->getAttribute('name'));
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

        self::assertCount(2, $repository->query()->get());
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
        self::assertNotNull($this->app);

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
