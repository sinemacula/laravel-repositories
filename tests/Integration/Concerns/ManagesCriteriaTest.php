<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Concerns\InteractsWithUserRepository;
use Tests\Support\Criteria\ActiveUsersCriterion;

/**
 * Tests for the ManagesCriteria trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(ManagesCriteria::class)]
final class ManagesCriteriaTest extends IntegrationTestCase
{
    use InteractsWithUserRepository;

    /**
     * Test that pushCriteria() sanitizes non-criterion entries out of the
     * persistent criteria collection instead of registering them as pending
     * composition.
     *
     * @return void
     */
    public function testPushCriteriaSanitizesNonCriterionEntriesFromPersistentCriteria(): void
    {
        $repository = $this->repository();

        $repository->pushCriteria([new ActiveUsersCriterion, 'invalid']);

        self::assertCount(1, $repository->getCriteria());
    }

    /**
     * Test that withCriteria() sanitizes non-criterion entries out of the
     * transient criteria collection instead of registering them as pending
     * composition.
     *
     * @return void
     */
    public function testWithCriteriaSanitizesNonCriterionEntriesFromTransientCriteria(): void
    {
        $repository = $this->repository();

        $repository->withCriteria([new ActiveUsersCriterion, 'invalid']);

        self::assertCount(1, $repository->getCriteria());
    }
}
