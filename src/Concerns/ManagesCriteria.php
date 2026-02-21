<?php

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Override;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * Criteria lifecycle and application behavior for repositories.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type TCriterion \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>
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
    #[Override]
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
    #[Override]
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
    #[Override]
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
    #[Override]
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
    #[Override]
    public function getCriteria(): Collection
    {
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
    #[Override]
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
    #[Override]
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
    #[Override]
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
    #[Override]
    public function resetCriteria(): static
    {
        $this->resetPersistentCriteria()
            ->resetTransientCriteria();

        return $this;
    }

    /**
     * Apply the criteria to the current query.
     *
     * @return static
     */
    protected function applyCriteria(): static
    {
        if (!$this->model instanceof Builder && !$this->model instanceof Model) {
            $this->resetModel();
        }

        if ($this->skipCriteria) {

            $this->skipCriteria     = false;
            $this->forceUseCriteria = false;
            $this->resetTransientCriteria();

            return $this;
        }

        if ($this->transientCriteria->isNotEmpty()) {

            foreach ($this->transientCriteria as $criterion) {
                $this->model = $criterion->apply($this->model);
            }

            $this->resetTransientCriteria();
        }

        if (($this->forceUseCriteria || !$this->disableCriteria) && $this->persistentCriteria->isNotEmpty()) {
            foreach ($this->persistentCriteria as $criterion) {
                $this->model = $criterion->apply($this->model);
            }
        }

        $this->forceUseCriteria = false;

        return $this;
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
     * Determine whether a persisted criterion matches the given removal request.
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
