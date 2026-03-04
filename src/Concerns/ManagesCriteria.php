<?php

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use SineMacula\Repositories\Contracts\ContributesMetadata;
use SineMacula\Repositories\Contracts\CriteriaInterface;
use SineMacula\Repositories\Contracts\DeclaresEagerLoading;
use SineMacula\Repositories\Contracts\DeclaresFieldSelection;
use SineMacula\Repositories\Contracts\DeclaresRelationshipCounts;

/**
 * Shared criteria-state lifecycle for repositories, including persistent and
 * transient criteria application with runtime control toggles.
 *
 * ## Criteria Control Flag Precedence
 *
 * The criteria state machine uses four binary flags. When multiple flags are
 * active simultaneously, the following precedence rules apply:
 *
 * 1. **$skipCriteria overrides all other flags.** When true, no criteria
 *    (persistent or transient) are applied, regardless of $disableCriteria
 *    or $forceUseCriteria state. Both $skipCriteria and $forceUseCriteria
 *    are reset after the query, and transient criteria are cleared.
 *
 * 2. **$forceUseCriteria overrides $disableCriteria for persistent criteria.**
 *    When both are true, persistent criteria ARE applied. This allows
 *    useCriteria() and withCriteria() to temporarily re-enable criteria
 *    application even when criteria are globally disabled.
 *
 * 3. **$disableCriteria does NOT gate transient criteria.** When true (and
 *    $skipCriteria is false), only persistent criteria are suppressed.
 *    Transient criteria are still applied. Use skipCriteria() to suppress
 *    all criteria including transient.
 *
 * ## Flag Behavior Summary
 *
 * | dis | skip | force | trans? | persist | trans |
 * |-----|------|-------|--------|---------|-------|
 * |  F  |  F   |   F   |   no   |   yes   |  n/a  |
 * |  F  |  F   |   F   |  yes   |   yes   |  yes  |
 * |  F  |  F   |   T   |   no   |   yes   |  n/a  |
 * |  F  |  F   |   T   |  yes   |   yes   |  yes  |
 * |  F  |  T   |  any  |  any   |    no   |   no  |
 * |  T  |  F   |   F   |   no   |    no   |  n/a  |
 * |  T  |  F   |   F   |  yes   |    no   |  yes  |
 * |  T  |  F   |   T   |   no   |   yes   |  n/a  |
 * |  T  |  F   |   T   |  yes   |   yes   |  yes  |
 * |  T  |  T   |  any  |  any   |    no   |   no  |
 *
 * ## Criteria Application Ordering
 *
 * When criteria are applied (via applyCriteria()), they execute in a fixed
 * two-phase order:
 *
 * 1. **Transient criteria first** — Criteria registered via withCriteria()
 *    are applied in insertion order, then cleared.
 * 2. **Persistent criteria second** — Criteria registered via pushCriteria()
 *    are applied in insertion order. They remain registered for future queries.
 *
 * Within each phase, criteria execute in the order they were added. There is
 * no priority or dependency mechanism; insertion order is the sole determinant.
 *
 * Supplementary capability declarations (eager-loading, field selection,
 * counts, metadata) are collected from each criterion immediately after its
 * apply() method executes, in the same ordering as above.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @formatter:off
 *
 * @phpstan-type TCriterion \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>
 *
 * @formatter:on
 *
 * @internal
 */
trait ManagesCriteria
{
    /**
     * Temporarily applies specified criteria to the next request only.
     *
     * This method allows you to specify criteria that are applied once to the
     * next operation involving data retrieval or manipulation and then
     * automatically discarded.
     *
     * @param  array<int, TCriterion>|TCriterion  $criteria
     * @return static
     */
    #[\Override]
    public function withCriteria(array|CriteriaInterface $criteria): static
    {
        $criteria = is_array($criteria) ? $criteria : [$criteria];

        $this->transientCriteria = collect($this->sanitizeCriteria($criteria));

        $this->useCriteria();

        return $this;
    }

    /**
     * Temporarily enables the application of criteria in queries.
     *
     * Use this method to temporarily override a `disableCriteria()` setting,
     * allowing criteria to be applied just for the next query. This does not
     * affect the permanent enabled/disabled state.
     *
     * @return static
     */
    #[\Override]
    public function useCriteria(): static
    {
        $this->skipCriteria     = false;
        $this->forceUseCriteria = true;

        return $this;
    }

    /**
     * Persistently applies specified criteria to all requests.
     *
     * Add criteria that will be applied to all future operations until
     * explicitly removed or the repository is reset.
     *
     * @param  array<int, TCriterion>|TCriterion  $criteria
     * @return static
     */
    #[\Override]
    public function pushCriteria(array|CriteriaInterface $criteria): static
    {
        $criteria = is_array($criteria) ? $criteria : [$criteria];

        $this->persistentCriteria = $this->persistentCriteria->merge($this->sanitizeCriteria($criteria));

        return $this;
    }

    /**
     * Removes specified criteria from the repository.
     *
     * This method removes previously added criteria, either added for all
     * requests or just for the next request. It affects both persistent and
     * transient criteria settings.
     *
     * @param  array<int, string|TCriterion>|string|TCriterion  $criteria
     * @return static
     */
    #[\Override]
    public function removeCriteria(array|CriteriaInterface|string $criteria): static
    {
        $criteria = is_array($criteria) ? $criteria : [$criteria];

        $this->persistentCriteria = $this->persistentCriteria
            ->reject(fn ($persisted) => $this->criteriaMatchesRemovalRequest($persisted, $criteria));

        $this->transientCriteria = $this->transientCriteria
            ->reject(fn ($transient) => $this->criteriaMatchesRemovalRequest($transient, $criteria));

        return $this;
    }

    /**
     * Retrieves a collection of all active criteria that will be applied in the
     * next query.
     *
     * @return \Illuminate\Support\Collection<int, TCriterion>
     */
    #[\Override]
    public function getCriteria(): Collection
    {
        /** @phpstan-ignore return.type (trait template resolution — reported and actual types are identical) */
        return $this->persistentCriteria->merge($this->transientCriteria);
    }

    /**
     * Permanently enables the application of criteria in queries.
     *
     * This method ensures that criteria are applied to all queries going
     * forward, until explicitly disabled. Note that `skipCriteria()` will
     * override this on the next query.
     *
     * @return static
     */
    #[\Override]
    public function enableCriteria(): static
    {
        $this->disableCriteria = false;

        return $this;
    }

    /**
     * Permanently disables the application of criteria in queries.
     *
     * This method turns off the use of criteria in all future queries until
     * criteria are explicitly re-enabled. Note that `useCriteria()` will
     * override this on the next query.
     *
     * @return static
     */
    #[\Override]
    public function disableCriteria(): static
    {
        $this->disableCriteria = true;

        return $this;
    }

    /**
     * Temporarily disables the application of criteria in queries.
     *
     * Use this method to temporarily bypass all criteria for the next query,
     * even if `enableCriteria()` has been called. This does not affect the
     * permanent enabled/disabled state.
     *
     * @return static
     */
    #[\Override]
    public function skipCriteria(): static
    {
        $this->skipCriteria = true;

        return $this;
    }

    /**
     * Clears all criteria from the repository.
     *
     * This method resets the repository to its original state with no criteria
     * applied.
     *
     * @return static
     */
    #[\Override]
    public function resetCriteria(): static
    {
        $this->resetPersistentCriteria()
            ->resetTransientCriteria();

        return $this;
    }

    /**
     * Apply the criteria to the current query.
     *
     * Called after prepareQueryBuilder() has normalized $model to a Builder.
     *
     * @internal Use the public criteria management API
     *           (pushCriteria, withCriteria, etc.).
     *
     * @return static
     */
    protected function applyCriteria(): static
    {
        $this->resetCollectedCapabilities();

        if ($this->skipCriteria) {

            $this->skipCriteria     = false;
            $this->forceUseCriteria = false;
            $this->resetTransientCriteria();

            return $this;
        }

        if ($this->transientCriteria->isNotEmpty()) {

            foreach ($this->transientCriteria as $criterion) {

                $this->model = $criterion->apply($this->model);
                $this->collectCapabilities($criterion);
            }

            $this->resetTransientCriteria();
        }

        if (($this->forceUseCriteria || !$this->disableCriteria) && $this->persistentCriteria->isNotEmpty()) {

            foreach ($this->persistentCriteria as $criterion) {

                $this->model = $criterion->apply($this->model);
                $this->collectCapabilities($criterion);
            }
        }

        $this->forceUseCriteria = false;

        return $this;
    }

    /**
     * Collect supplementary capability declarations from a criterion.
     *
     * @internal called during applyCriteria() for each applied criterion
     *
     * @formatter:off
     *
     * @param  \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>  $criterion
     *
     * @formatter:on
     *
     * @return void
     */
    private function collectCapabilities(CriteriaInterface $criterion): void
    {
        if ($criterion instanceof DeclaresEagerLoading) {
            $this->collectedEagerLoads = array_merge($this->collectedEagerLoads, $criterion->eagerLoads());
        }

        if ($criterion instanceof DeclaresFieldSelection) {
            $this->collectedFields = array_values(array_unique(array_merge($this->collectedFields, $criterion->fields())));
        }

        if ($criterion instanceof DeclaresRelationshipCounts) {
            $this->collectedCounts = array_merge($this->collectedCounts, $criterion->withCounts());
        }

        if ($criterion instanceof ContributesMetadata) {
            $this->collectedMetadata = array_merge($this->collectedMetadata, $criterion->metadata());
        }
    }

    /**
     * Reset all collected capability declarations.
     *
     * @internal called at the start of applyCriteria()
     *
     * @return void
     */
    private function resetCollectedCapabilities(): void
    {
        $this->collectedEagerLoads = [];
        $this->collectedFields     = [];
        $this->collectedCounts     = [];
        $this->collectedMetadata   = [];
    }

    /**
     * Sanitize the given array of criteria to ensure they are valid criteria
     * instances.
     *
     * @param  array<int, mixed>  $criteria
     * @return array<int, TCriterion>
     */
    private function sanitizeCriteria(array $criteria): array
    {
        return array_filter($criteria, fn ($criterion) => $criterion instanceof CriteriaInterface);
    }

    /**
     * Determine whether a persisted criterion matches the given
     * removal request.
     *
     * @param  mixed  $persisted
     * @param  array<int, string|TCriterion>  $criteria
     * @return bool
     */
    private function criteriaMatchesRemovalRequest(mixed $persisted, array $criteria): bool
    {
        if (!is_object($persisted)) {
            return false;
        }

        foreach ($criteria as $criterion) {
            if (
                (is_object($criterion) && $persisted instanceof $criterion)
                || (is_string($criterion) && $persisted::class === $criterion)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clears all transient criteria.
     *
     * @return static
     */
    private function resetTransientCriteria(): static
    {
        $this->transientCriteria = collect();

        return $this;
    }

    /**
     * Clears all persistent criteria.
     *
     * @return static
     */
    private function resetPersistentCriteria(): static
    {
        $this->persistentCriteria = collect();

        return $this;
    }
}
