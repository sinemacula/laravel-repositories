<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Testing\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * State observers and mutators for repository test doubles.
 *
 * Composition returns a copy, so inspect the copy it returned:
 * $repository->addScope($scope)->scopesCount().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-require-extends \SineMacula\Repositories\Repository<TModel>
 */
trait InspectsRepository
{
    /**
     * Get the number of persistent criteria registered.
     *
     * @return int
     */
    public function persistentCriteriaCount(): int
    {
        return $this->persistentCriteria->count();
    }

    /**
     * Get the number of transient criteria registered.
     *
     * @return int
     */
    public function transientCriteriaCount(): int
    {
        return $this->transientCriteria->count();
    }

    /**
     * Get the number of scopes composing the next query.
     *
     * Excludes scopes registered with pushScope().
     *
     * @return int
     */
    public function scopesCount(): int
    {
        return count($this->scopes);
    }

    /**
     * Get the number of scopes registered for the life of the instance.
     *
     * @return int
     */
    public function persistentScopesCount(): int
    {
        return count($this->persistentScopes);
    }

    /**
     * Determine whether persistent criteria are disabled.
     *
     * @return bool
     */
    public function isCriteriaDisabled(): bool
    {
        return $this->disableCriteria;
    }

    /**
     * Determine whether the next query will skip all criteria.
     *
     * @return bool
     */
    public function isCriteriaSkipped(): bool
    {
        return $this->skipCriteria;
    }

    /**
     * Determine whether criteria are force-enabled for the next query.
     *
     * @return bool
     */
    public function isForceUsingCriteria(): bool
    {
        return $this->forceUseCriteria;
    }

    /**
     * Get the current internal model/builder reference.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|null
     */
    public function currentModel(): Builder|Model|null
    {
        return $this->model;
    }

    /**
     * Determine whether this handle holds a resolved model.
     *
     * A copy returned by a composing call reports false until it builds its own
     * query.
     *
     * @return bool
     */
    public function isBooted(): bool
    {
        return $this->model !== null;
    }

    /**
     * Force the internal model reference.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|null  $model
     * @return void
     */
    public function forceModel(Builder|Model|null $model): void
    {
        $this->model = $model;
    }

    /**
     * Force the internal persistent criteria collection.
     *
     * @param  \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>>  $criteria
     * @return void
     */
    public function forcePersistentCriteria(Collection $criteria): void
    {
        $this->persistentCriteria = $criteria;
    }

    /**
     * Force the internal transient criteria collection.
     *
     * @param  \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>>  $criteria
     * @return void
     */
    public function forceTransientCriteria(Collection $criteria): void
    {
        $this->transientCriteria = $criteria;
    }

    /**
     * Invoke the protected applyScopes() method.
     *
     * @return static
     */
    public function invokeApplyScopes(): static
    {
        return $this->applyScopes();
    }

    /**
     * Invoke the protected resetAndReturn() method.
     *
     * @param  mixed  $result
     * @return mixed
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function invokeResetAndReturn(mixed $result): mixed
    {
        return $this->resetAndReturn($result);
    }
}
