<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Illuminate\Contracts\Container\BindingResolutionException;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Concerns\InteractsWithUserRepository;
use Tests\Support\Criteria\ActiveUsersCriterion;
use Tests\Support\Criteria\EagerLoadingCriterion;
use Tests\Support\Criteria\FailingUsersCriterion;
use Tests\Support\Criteria\NamedUsersCriterion;
use Tests\Support\Exceptions\CompositionFailure;
use Tests\Support\Models\TestUser;

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

    /** @var string The message carried by a binding that cannot resolve. */
    private const string RESOLUTION_FAILURE = 'Simulated resolution failure.';

    /**
     * Test that a failed composition consumes the one-shot force flag, so a
     * surviving useCriteria() cannot override a standing disableCriteria() on
     * the next, unrelated query.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testFailedCompositionConsumesTheOneShotForceCriteriaFlag(): void
    {
        $this->seedUsers();

        $repository = $this->repository();

        $repository->pushCriteria(new NamedUsersCriterion('Bob'));
        $repository->pushCriteria(new FailingUsersCriterion);
        $repository->disableCriteria();
        $repository->useCriteria();

        try {

            $repository->query();
            self::fail('Expected the failing criterion to propagate out of query().');
        } catch (CompositionFailure $exception) {
            self::assertSame(FailingUsersCriterion::MESSAGE, $exception->getMessage());
        }

        self::assertFalse($repository->isForceUsingCriteria());

        $repository->removeCriteria(FailingUsersCriterion::class);

        // Criteria remain disabled and the force flag was consumed by the
        // failed attempt, so neither the name criterion nor the half-applied
        // builder it left behind may narrow this query.
        self::assertCount(3, $repository->query()->get());
    }

    /**
     * Test that a composition failure raised before criteria are applied still
     * consumes a pending skipCriteria(), which would otherwise silently drop
     * every criterion from the next, unrelated query.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testFailedCompositionConsumesAPendingSkipCriteriaFlag(): void
    {
        $this->seedUsers();

        $repository = $this->repository();
        $calls      = 0;

        self::assertNotNull($this->app);

        $this->app->bind(TestUser::class, function () use (&$calls): TestUser {

            $calls++;

            if ($calls === 1) {
                throw new BindingResolutionException(self::RESOLUTION_FAILURE);
            }

            return new TestUser;
        });

        $repository->pushCriteria(new NamedUsersCriterion('Bob'));
        $repository->skipCriteria();

        try {

            $repository->query();
            self::fail('Expected the resolution failure to propagate out of query().');
        } catch (BindingResolutionException $exception) {
            self::assertSame(self::RESOLUTION_FAILURE, $exception->getMessage());
        }

        self::assertFalse($repository->isCriteriaSkipped());
        self::assertCount(1, $repository->query()->get());
    }

    /**
     * Test that a failed composition discards the capability declarations
     * collected from the criteria that ran before it failed, so a later read of
     * the collected declarations cannot describe a query that was never built.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testFailedCompositionDiscardsCollectedCapabilityDeclarations(): void
    {
        $this->seedUsers();

        $repository = $this->repository();

        $repository->withCriteria([new EagerLoadingCriterion, new FailingUsersCriterion]);

        try {

            $repository->query();
            self::fail('Expected the failing criterion to propagate out of query().');
        } catch (CompositionFailure $exception) {
            self::assertSame(FailingUsersCriterion::MESSAGE, $exception->getMessage());
        }

        self::assertSame([], $repository->getCollectedEagerLoads());
        self::assertSame([], $repository->getCollectedFields());
        self::assertSame([], $repository->getCollectedCounts());
        self::assertSame([], $repository->getCollectedMetadata());
    }

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
