<?php

namespace SineMacula\Repositories;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SineMacula\Repositories\Contracts\CriteriaInterface;
use SineMacula\Repositories\Contracts\RepositoryCriteriaInterface;
use SineMacula\Repositories\Contracts\RepositoryInterface;
use SineMacula\Repositories\Exceptions\RepositoryException;

/**
 * The base repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
abstract class Repository implements RepositoryInterface, RepositoryCriteriaInterface
{
    /** @var \Illuminate\Database\Eloquent\Model|\Illuminate\Contracts\Database\Eloquent\Builder The model instance */
    protected Model|Builder $model;

    /** @var \Illuminate\Support\Collection The persistent criteria */
    protected Collection $persistentCriteria;

    /** @var \Illuminate\Support\Collection The transient criteria */
    protected Collection $transientCriteria;

    /** @var bool Indicate whether criteria are enabled/disabled */
    protected bool $disableCriteria = false;

    /** @var bool Indicate whether criteria should be skipped */
    protected bool $skipCriteria = false;

    /** @var array The scopes to be applied to the current query */
    protected array $scopes = [];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     */
    public function __construct(

        /** The Laravel application instance */
        protected readonly Application $app

    ) {
        $this->resetCriteria();
        $this->resetScopes();
        $this->makeModel();
        $this->boot();
    }

    /**
     * Trigger a static method call on the repository.
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return call_user_func_array([new static, $method], $arguments);
    }

    /**
     * Forward method calls to the model
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        $this->applyCriteria();
        $this->applyScopes();

        $result = call_user_func_array([$this->model, $method], $arguments);

        return $this->resetAndReturn($result);
    }

    /**
     * Reset the scopes.
     *
     * @return static
     */
    public function resetScopes(): static
    {
        $this->scopes = [];

        return $this;
    }

    /**
     * Create a new model instance.
     *
     * @return \Illuminate\Database\Eloquent\Model
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException|\Illuminate\Contracts\Container\BindingResolutionException
     */
    public function makeModel(): Model
    {
        $model = $this->app->make($this->model());

        if (!$model instanceof Model) {
            throw new RepositoryException("Class {$this->model()} must be an instance of Illuminate\\Database\\Eloquent\\Model");
        }

        return $this->model = $model;
    }

    /**
     * Return the model class
     *
     * @return class-string
     */
    abstract public function model(): string;

    /**
     * Reset the model instance.
     *
     * @return void
     */
    public function resetModel(): void
    {
        $this->makeModel();
    }

    /**
     * Get the model instance.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Temporarily applies specified criteria to the next request only.
     *
     * This method allows you to specify criteria that are applied once to the
     * next operation involving data retrieval or manipulation and then
     * automatically discarded.
     *
     * @param  \SineMacula\Repositories\Contracts\CriteriaInterface|array  $criteria
     * @return static
     */
    public function withCriteria(CriteriaInterface|array $criteria): static
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
    public function useCriteria(): static
    {
        $this->skipCriteria = false;

        return $this;
    }

    /**
     * Persistently applies specified criteria to all requests.
     *
     * Add criteria that will be applied to all future operations until
     * explicitly removed or the repository is reset.
     *
     * @param  \SineMacula\Repositories\Contracts\CriteriaInterface|array  $criteria
     * @return static
     */
    public function pushCriteria(CriteriaInterface|array $criteria): static
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
     * @param  \SineMacula\Repositories\Contracts\CriteriaInterface|array|string  $criteria
     * @return static
     */
    public function removeCriteria(CriteriaInterface|array|string $criteria): static
    {
        $criteria = is_array($criteria) ? $criteria : [$criteria];

        $this->persistentCriteria = $this->persistentCriteria->reject(function ($persisted) use ($criteria) {

            foreach ($criteria as $criterion) {
                if (
                    (is_object($criterion) && $persisted instanceof $criterion)
                    || get_class($persisted) === $criterion
                ) {
                    return true;
                }
            }

            return false;
        });

        return $this;
    }

    /**
     * Retrieves a collection of all active criteria that will be applied in the
     * next query.
     *
     * @return \Illuminate\Support\Collection
     */
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
    public function disableCriteria(): static
    {
        $this->disableCriteria = true;

        return $this;
    }

    /**
     * Temporarily disables the application of criteria in queries.
     *
     * Use this method to temporarily bypass all criteria for the next query,
     * even if `enableCriteria()` has been called. This does not
     * affect the permanent enabled/disabled state.
     *
     * @return static
     */
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
    public function resetCriteria(): static
    {
        $this->resetPersistentCriteria()
            ->resetTransientCriteria();

        return $this;
    }

    /**
     * Add a new scope.
     *
     * @param  \Closure  $scope
     * @return static
     */
    public function addScope(Closure $scope): static
    {
        $this->scopes[] = $scope;

        return $this;
    }

    /**
     * Boot the repository instance.
     *
     * This is a useful method for setting immediate properties when extending
     * the base repository class.
     *
     * @return void
     */
    protected function boot(): void {}

    /**
     * Apply the criteria to the current query.
     *
     * @return static
     */
    protected function applyCriteria(): static
    {
        if ($this->skipCriteria) {

            $this->skipCriteria = false;
            $this->resetTransientCriteria();

            return $this;
        }

        if ($this->transientCriteria->isNotEmpty()) {

            foreach ($this->transientCriteria as $criterion) {
                $this->model = $criterion->apply($this->model);
            }

            $this->resetTransientCriteria();
        }

        if (!$this->disableCriteria && $this->persistentCriteria->isNotEmpty()) {
            foreach ($this->persistentCriteria as $criterion) {
                $this->model = $criterion->apply($this->model);
            }
        }

        return $this;
    }

    /**
     * Apply all accumulated scopes to the model.
     *
     * @return static
     */
    protected function applyScopes(): static
    {
        foreach ($this->scopes as $scope) {
            if (is_callable($scope)) {
                $this->model = $scope($this->model);
            }
        }

        return $this;
    }

    /**
     * Reset the various transient values and return the result.
     *
     * @param  mixed  $result
     * @return mixed
     */
    protected function resetAndReturn(mixed $result): mixed
    {
        $this->resetTransientCriteria();
        $this->resetScopes();
        $this->resetModel();

        return $result;
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
     * @param  array  $criteria
     * @return array
     */
    private function sanitizeCriteria(array $criteria): array
    {
        return array_filter($criteria, fn ($criterion) => $criterion instanceof CriteriaInterface);
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
