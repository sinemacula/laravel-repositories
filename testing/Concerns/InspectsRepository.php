<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Testing\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Trait providing state observation and mutation helpers for repository test
 * doubles.
 *
 * Use this trait on your test repository subclass instead of writing custom
 * public wrapper methods for protected properties. See docs/testing.md for
 * usage patterns.
 *
 * Every observer reads the handle it is called on, and composition returns a
 * copy, so inspect a composition on the copy the composing call returned:
 * $repository->addScope($scope)->scopesCount(), never a discarded addScope()
 * followed by $repository->scopesCount().
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
    // -------------------------------------------------------------------------
    // State observers
    // -------------------------------------------------------------------------

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
     * Scopes registered with pushScope() are not counted: they are
     * configuration for the life of the instance rather than composition of the
     * next query.
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
     * Returns true when $model holds a resolved Model instance, which indicates
     * that makeModel() and boot() both executed. A copy returned by a composing
     * call starts with no model and reports false until it builds its own
     * query, so assert this on the instance under test rather than on a copy.
     *
     * @return bool
     */
    public function isBooted(): bool
    {
        return $this->model !== null;
    }

    // -------------------------------------------------------------------------
    // State mutators (for edge-case testing)
    // -------------------------------------------------------------------------

    /**
     * Force the internal model reference for edge-case coverage.
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

    // -------------------------------------------------------------------------
    // Protected method wrappers
    // -------------------------------------------------------------------------

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
     */
    public function invokeResetAndReturn(mixed $result): mixed
    {
        return $this->resetAndReturn($result);
    }
}
