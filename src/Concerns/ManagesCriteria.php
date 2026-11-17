<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use SineMacula\Repositories\Contracts\ContributesMetadata;
use SineMacula\Repositories\Contracts\CriteriaInterface;
use SineMacula\Repositories\Contracts\DeclaresEagerLoading;
use SineMacula\Repositories\Contracts\DeclaresFieldSelection;
use SineMacula\Repositories\Contracts\DeclaresRelationshipCounts;

/**
 * Manages the persistent and transient criteria a repository applies.
 *
 * $skipCriteria suppresses every criterion, $forceUseCriteria overrides
 * $disableCriteria, and $disableCriteria suppresses persistent criteria only.
 * Transient criteria apply before persistent ones, each in insertion order.
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
     * Return a copy that applies the given criteria to the next query only.
     *
     * Each call replaces the transient criteria rather than appending to them.
     *
     * @param  array<int, TCriterion>|TCriterion  $criteria
     * @return static
     *
     * @phpstan-pure
     */
    #[\Override]
    public function withCriteria(array|CriteriaInterface $criteria): static
    {
        $criteria = is_array($criteria) ? $criteria : [$criteria];

        $composed = $this->useCriteria();

        // @phpstan-ignore impure.propertyAssign, possiblyImpure.functionCall, possiblyImpure.methodCall
        $composed->transientCriteria = collect($this->sanitizeCriteria($criteria));

        return $composed;
    }

    /**
     * Return a copy that applies criteria to the next query even when they are
     * disabled.
     *
     * @return static
     *
     * @phpstan-pure
     */
    #[\Override]
    public function useCriteria(): static
    {
        $composed = clone $this;

        // @phpstan-ignore impure.propertyAssign
        $composed->skipCriteria = false;

        // @phpstan-ignore impure.propertyAssign
        $composed->forceUseCriteria = true;

        return $composed;
    }

    /**
     * Apply the given criteria to every query until removed.
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
     * Remove the criteria matching the given instances or class names from this
     * handle.
     *
     * @param  array<int, string|TCriterion>|string|TCriterion  $criteria
     * @return static
     */
    #[\Override]
    public function removeCriteria(array|CriteriaInterface|string $criteria): static
    {
        $requested = collect(is_array($criteria) ? $criteria : [$criteria]);

        $matches = static fn (mixed $candidate): bool => is_object($candidate)
            && $requested->contains(static fn (object|string $criterion): bool => is_object($criterion) ? $candidate instanceof $criterion : $candidate::class === $criterion);

        $this->persistentCriteria = $this->persistentCriteria->reject($matches);
        $this->transientCriteria  = $this->transientCriteria->reject($matches);

        return $this;
    }

    /**
     * Get the criteria the next query applies.
     *
     * @return \Illuminate\Support\Collection<int, TCriterion>
     */
    #[\Override]
    public function getCriteria(): Collection
    {
        /** @phpstan-ignore return.type */
        return $this->persistentCriteria->merge($this->transientCriteria);
    }

    /**
     * Enable persistent criteria for every query.
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
     * Disable persistent criteria for every query.
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
     * Return a copy whose next query applies no criteria.
     *
     * @return static
     *
     * @phpstan-pure
     */
    #[\Override]
    public function skipCriteria(): static
    {
        $composed = clone $this;

        // @phpstan-ignore impure.propertyAssign
        $composed->skipCriteria = true;

        return $composed;
    }

    /**
     * Clear every criterion from the repository.
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
     * Apply the criteria to the current query, consuming the one-shot flags.
     *
     * @return static
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     *
     * @internal
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

                $this->model = $criterion->apply($this->criteriaQuery());
                $this->collectCapabilities($criterion);
            }

            $this->resetTransientCriteria();
        }

        if (($this->forceUseCriteria || !$this->disableCriteria) && $this->persistentCriteria->isNotEmpty()) {

            foreach ($this->persistentCriteria as $criterion) {

                $this->model = $criterion->apply($this->criteriaQuery());
                $this->collectCapabilities($criterion);
            }
        }

        $this->forceUseCriteria = false;

        return $this;
    }

    /**
     * Determine whether applyCriteria() would change the next query, without
     * consuming the one-shot flags.
     *
     * @return bool
     */
    protected function hasPendingComposition(): bool
    {
        if ($this->skipCriteria) {
            return false;
        }

        return $this->transientCriteria->isNotEmpty()
            || (($this->forceUseCriteria || !$this->disableCriteria) && $this->persistentCriteria->isNotEmpty());
    }

    /**
     * Get the query the next criterion applies to.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function criteriaQuery(): Builder
    {
        return $this->model instanceof Builder ? $this->model : $this->getModel()->newQuery();
    }

    /**
     * Drop anything that is not a criterion from the given array.
     *
     * @param  array<int, mixed>  $criteria
     * @return array<int, TCriterion>
     */
    private function sanitizeCriteria(array $criteria): array
    {
        return array_filter($criteria, fn (mixed $criterion): bool => $criterion instanceof CriteriaInterface);
    }

    /**
     * Reset the per-query criteria state to what a completed applyCriteria()
     * leaves behind.
     *
     * @return void
     */
    private function resetCriteriaState(): void
    {
        $this->resetTransientCriteria();
        $this->resetCollectedCapabilities();

        $this->skipCriteria     = false;
        $this->forceUseCriteria = false;
    }

    /**
     * Clear the transient criteria.
     *
     * @return static
     */
    private function resetTransientCriteria(): static
    {
        $this->transientCriteria = collect();

        return $this;
    }

    /**
     * Clear the persistent criteria.
     *
     * @return static
     */
    private function resetPersistentCriteria(): static
    {
        $this->persistentCriteria = collect();

        return $this;
    }

    /**
     * Reset all collected capability declarations.
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
     * Collect supplementary capability declarations from a criterion.
     *
     * @param  \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>  $criterion
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

        if (!$criterion instanceof ContributesMetadata) {
            return;
        }

        $this->collectedMetadata = array_merge($this->collectedMetadata, $criterion->metadata());
    }
}
