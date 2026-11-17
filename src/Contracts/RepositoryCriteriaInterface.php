<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Contracts;

use Illuminate\Support\Collection;

/**
 * The criteria contract a repository exposes.
 *
 * pushCriteria(), removeCriteria(), enableCriteria(), disableCriteria() and
 * resetCriteria() mutate the instance; withCriteria(), useCriteria() and
 * skipCriteria() return a copy and leave it untouched.
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
     * Return a copy that applies the given criteria to the next query only.
     *
     * Each call replaces the transient criteria rather than appending to them.
     *
     * @param  array<int, TCriterion>|TCriterion  $criteria
     * @return static
     *
     * @phpstan-pure
     */
    public function withCriteria(array|CriteriaInterface $criteria): static;

    /**
     * Apply the given criteria to every query until removed.
     *
     * @param  array<int, TCriterion>|TCriterion  $criteria
     * @return static
     */
    public function pushCriteria(array|CriteriaInterface $criteria): static;

    /**
     * Remove the criteria matching the given instances or class names from this
     * handle.
     *
     * Transient criteria live on the copy withCriteria() returned, so remove
     * them through that copy.
     *
     * @param  array<int, string|TCriterion>|string|TCriterion  $criteria
     * @return static
     */
    public function removeCriteria(array|CriteriaInterface|string $criteria): static;

    /**
     * Get the persistent and transient criteria registered on this handle.
     *
     * @return \Illuminate\Support\Collection<int, TCriterion>
     */
    public function getCriteria(): Collection;

    /**
     * Enable persistent criteria for every query.
     *
     * @return static
     */
    public function enableCriteria(): static;

    /**
     * Disable persistent criteria for every query.
     *
     * @return static
     */
    public function disableCriteria(): static;

    /**
     * Return a copy that applies criteria to the next query even when they are
     * disabled.
     *
     * @return static
     *
     * @phpstan-pure
     */
    public function useCriteria(): static;

    /**
     * Return a copy whose next query applies no criteria.
     *
     * @return static
     *
     * @phpstan-pure
     */
    public function skipCriteria(): static;

    /**
     * Clear every criterion from the repository.
     *
     * @return static
     */
    public function resetCriteria(): static;
}
