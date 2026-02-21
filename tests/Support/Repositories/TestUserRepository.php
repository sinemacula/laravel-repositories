<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\TestUser;

/**
 * Repository test double for exercising repository internals.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
class TestUserRepository extends Repository
{
    /** @var bool Indicates whether boot() was executed */
    public bool $booted = false;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Support\Models\TestUser>
     */
    #[\Override]
    public function model(): string
    {
        return TestUser::class;
    }

    /**
     * Expose the persistent criteria count.
     *
     * @return int
     */
    public function persistentCriteriaCount(): int
    {
        return $this->persistentCriteria->count();
    }

    /**
     * Expose the transient criteria count.
     *
     * @return int
     */
    public function transientCriteriaCount(): int
    {
        return $this->transientCriteria->count();
    }

    /**
     * Expose the current scope count.
     *
     * @return int
     */
    public function scopesCount(): int
    {
        return count($this->scopes);
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
     * Determine whether the next query should skip criteria.
     *
     * @return bool
     */
    public function isCriteriaSkipped(): bool
    {
        return $this->skipCriteria;
    }

    /**
     * Determine whether criteria are force-enabled for one query.
     *
     * @return bool
     */
    public function isForceUsingCriteria(): bool
    {
        return $this->forceUseCriteria;
    }

    /**
     * Force the internal model value for edge-case coverage.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|null  $model
     * @return void
     */
    public function forceModel(Builder|Model|null $model): void
    {
        $this->model = $model;
    }

    /**
     * Force the internal persistent criteria for edge-case coverage.
     *
     * @param  \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\TestUser>>  $criteria
     * @return void
     */
    public function forcePersistentCriteria(Collection $criteria): void
    {
        $this->persistentCriteria = $criteria;
    }

    /**
     * Force the internal transient criteria for edge-case coverage.
     *
     * @param  \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\TestUser>>  $criteria
     * @return void
     */
    public function forceTransientCriteria(Collection $criteria): void
    {
        $this->transientCriteria = $criteria;
    }

    /**
     * Expose the current model reference for assertions.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|null
     */
    public function currentModel(): Builder|Model|null
    {
        return $this->model;
    }

    /**
     * Invoke applyScopes() for edge-case coverage.
     *
     * @return static
     */
    public function invokeApplyScopes(): static
    {
        return $this->applyScopes();
    }

    /**
     * Invoke resetAndReturn() for state-reset coverage.
     *
     * @param  mixed  $result
     * @return mixed
     */
    public function invokeResetAndReturn(mixed $result): mixed
    {
        return $this->resetAndReturn($result);
    }

    /**
     * Boot the repository.
     *
     * @return void
     */
    #[\Override]
    protected function boot(): void
    {
        $this->booted = true;
    }
}
