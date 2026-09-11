<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Contracts;

use Illuminate\Support\Collection;

/**
 * Declares the criteria lifecycle contract a repository must expose: register
 * criteria (persistently or for the next query only), remove them, inspect what
 * is currently active, and toggle their application at runtime.
 *
 * The contract has two halves. Configuration mutates the instance and returns
 * it: pushCriteria(), removeCriteria(), enableCriteria(), disableCriteria() and
 * resetCriteria(). Composition leaves the instance untouched and returns a copy
 * carrying the composition: withCriteria(), useCriteria() and skipCriteria(). A
 * composition only reaches a query built from that copy.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type TCriterion \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>
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
     * Each call replaces any transient criteria from a previous withCriteria()
     * call rather than appending to them; pass an array to apply several
     * criteria to the next query.
     *
     * Returns a copy carrying the criteria; the instance it was called on is
     * left untouched, so discarding the return value discards the criteria.
     *
     * @param  array<int, TCriterion>|TCriterion  $criteria
     * @return static
     *
     * @phpstan-pure
     */
    public function withCriteria(array|CriteriaInterface $criteria): static;

    /**
     * Persistently applies specified criteria to all requests.
     *
     * Add criteria that will be applied to all future operations until
     * explicitly removed or the repository is reset.
     *
     * @param  array<int, TCriterion>|TCriterion  $criteria
     * @return static
     */
    public function pushCriteria(array|CriteriaInterface $criteria): static;

    /**
     * Removes specified criteria from the repository.
     *
     * This method removes previously added criteria, either added for all
     * requests or just for the next request. It mutates the repository and
     * returns it.
     *
     * It reaches the persistent criteria and any transient criteria this
     * instance itself carries. Transient criteria travel with the copy that
     * `withCriteria()` returned, so removing one means calling this on that
     * copy.
     *
     * @param  array<int, string|TCriterion>|string|TCriterion  $criteria
     * @return static
     */
    public function removeCriteria(array|CriteriaInterface|string $criteria): static;

    /**
     * Retrieves a collection of all active criteria that will be applied in the
     * next query.
     *
     * @return \Illuminate\Support\Collection<int, TCriterion>
     */
    public function getCriteria(): Collection;

    /**
     * Permanently enables the application of criteria in queries.
     *
     * This method mutates the repository and returns it, ensuring criteria are
     * applied to all queries going forward, until explicitly disabled. Note
     * that `skipCriteria()` returns a copy, so it overrides this only for the
     * query built from that copy.
     *
     * @return static
     */
    public function enableCriteria(): static;

    /**
     * Permanently disables the application of criteria in queries.
     *
     * This method mutates the repository and returns it, turning off the use of
     * criteria in all future queries until criteria are explicitly re-enabled.
     * Note that `useCriteria()` and `withCriteria()` return a copy, so they
     * override this only for the query built from that copy.
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
     * Returns a copy carrying the flag; the instance it was called on is left
     * untouched. The one-shot flags compose the next query, so they travel with
     * the copy.
     *
     * @return static
     *
     * @phpstan-pure
     */
    public function useCriteria(): static;

    /**
     * Temporarily disables the application of criteria in queries.
     *
     * Use this method to temporarily bypass all criteria for the next query,
     * even if `enableCriteria()` has been called. This does not affect the
     * permanent enabled/disabled state.
     *
     * Returns a copy carrying the flag; the instance it was called on is left
     * untouched, so discarding the return value discards the request.
     *
     * @return static
     *
     * @phpstan-pure
     */
    public function skipCriteria(): static;

    /**
     * Clears all criteria from the repository.
     *
     * This method mutates the repository and returns it, leaving it with no
     * criteria registered. Unlike `resetScopes()`, which returns a copy with
     * its composing scopes dropped, this clears the criteria on the instance
     * itself.
     *
     * @return static
     */
    public function resetCriteria(): static;
}
