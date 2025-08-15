<?php

namespace SineMacula\Repositories\Contracts;

use Illuminate\Support\Collection;

/**
 * Repository criteria interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
interface RepositoryCriteriaInterface
{
    /**
     * Temporarily applies specified criteria to the next request only.
     *
     * This method allows you to specify criteria that are applied once to the
     * next operation involving data retrieval or manipulation and then
     * automatically discarded.
     *
     * @param  array|\SineMacula\Repositories\Contracts\CriteriaInterface  $criteria
     * @return static
     */
    public function withCriteria(array|CriteriaInterface $criteria): static;

    /**
     * Persistently applies specified criteria to all requests.
     *
     * Add criteria that will be applied to all future operations until
     * explicitly removed or the repository is reset.
     *
     * @param  array|\SineMacula\Repositories\Contracts\CriteriaInterface  $criteria
     * @return static
     */
    public function pushCriteria(array|CriteriaInterface $criteria): static;

    /**
     * Removes specified criteria from the repository.
     *
     * This method removes previously added criteria, either added for all
     * requests or just for the next request. It affects both persistent and
     * transient criteria settings.
     *
     * @param  array|\SineMacula\Repositories\Contracts\CriteriaInterface|string  $criteria
     * @return static
     */
    public function removeCriteria(array|CriteriaInterface|string $criteria): static;

    /**
     * Retrieves a collection of all active criteria that will be applied in the
     * next query.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getCriteria(): Collection;

    /**
     * Permanently enables the application of criteria in queries.
     *
     * This method ensures that criteria are applied to all queries going
     * forward, until explicitly disabled. Note that `skipCriteria()` will
     * override this on the next query.
     *
     * @return static
     */
    public function enableCriteria(): static;

    /**
     * Permanently disables the application of criteria in queries.
     *
     * This method turns off the use of criteria in all future queries until
     * criteria are explicitly re-enabled. Note that `useCriteria()` will
     * override this on the next query.
     *
     * @return static
     */
    public function disableCriteria(): static;

    /**
     * Temporarily enables the application of criteria in queries.
     *
     * Use this method to temporarily override a `disableCriteria()` setting,
     * allowing criteria to be applied just for the next query. This does not
     * affect the permanent enabled/disabled state.
     *
     * @return static
     */
    public function useCriteria(): static;

    /**
     * Temporarily disables the application of criteria in queries.
     *
     * Use this method to temporarily bypass all criteria for the next query,
     * even if `enableCriteria()` has been called. This does not
     * affect the permanent enabled/disabled state.
     *
     * @return static
     */
    public function skipCriteria(): static;

    /**
     * Clears all criteria from the repository.
     *
     * This method resets the repository to its original state with no criteria
     * applied.
     *
     * @return static
     */
    public function resetCriteria(): static;
}
