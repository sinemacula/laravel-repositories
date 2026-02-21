<?php

namespace SineMacula\Repositories;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use SineMacula\Repositories\Contracts\RepositoryCriteriaInterface;
use SineMacula\Repositories\Contracts\RepositoryInterface;
use SineMacula\Repositories\Exceptions\RepositoryException;

/**
 * Core Eloquent repository abstraction that coordinates model resolution,
 * query composition, and repository state lifecycle.
 *
 * This class resolves the target model from Laravel's container, applies
 * persistent/transient criteria and scopes to query builders, and forwards
 * model-style calls while resetting transient state between operations.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @implements \SineMacula\Repositories\Contracts\RepositoryInterface<TModel>
 * @implements \SineMacula\Repositories\Contracts\RepositoryCriteriaInterface<TModel>
 *
 * @mixin \Illuminate\Contracts\Database\Eloquent\Builder
 */
abstract class Repository implements RepositoryCriteriaInterface, RepositoryInterface
{
    /** @use \SineMacula\Repositories\Concerns\ManagesCriteria<TModel> */
    use ManagesCriteria;

    /** @var \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|null The model instance */
    protected Builder|Model|null $model = null;

    /** @var \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>> The persistent criteria */
    protected Collection $persistentCriteria;

    /** @var \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>> The transient criteria */
    protected Collection $transientCriteria;

    /** @var bool Indicate whether criteria are enabled/disabled */
    protected bool $disableCriteria = false;

    /** @var bool Indicate whether criteria should be skipped */
    protected bool $skipCriteria = false;

    /** @var bool Indicate whether criteria should be force enabled for the next query */
    protected bool $forceUseCriteria = false;

    /** @var array<int, \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void> The scopes to be applied to the current query */
    protected array $scopes = [];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     */
    public function __construct(

        /** The Laravel application instance */
        protected readonly Application $app,

    ) {
        $this->persistentCriteria = new Collection;
        $this->transientCriteria  = new Collection;
        $this->resetCriteria();
        $this->resetScopes();
        $this->makeModel();
        $this->boot();
    }

    /**
     * Trigger a static method call on the repository.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $container = Container::getInstance();

        if ($container instanceof Application) {

            $instance = $container->make(static::class);

            $callable = \Closure::fromCallable([$instance, $method]);

            return $callable(...$arguments);
        }

        throw new RepositoryException(sprintf('Static repository calls require an initialized Laravel container for `%s`.', static::class));
    }

    /**
     * Forward method calls to the model.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        $query    = $this->prepareQueryBuilder();
        $callable = \Closure::fromCallable([$query, $method]);
        $result   = $callable(...$arguments);

        return $this->resetAndReturn($result);
    }

    /**
     * Reset the scopes.
     *
     * @return static
     */
    #[\Override]
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
     * @throws \Illuminate\Contracts\Container\BindingResolutionException|\SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[\Override]
    public function makeModel(): Model
    {
        $model = $this->app->make($this->model());

        if (!$this->isModelInstance($model)) {
            throw new RepositoryException("Class {$this->model()} must be an instance of Illuminate\\Database\\Eloquent\\Model");
        }

        return $this->model = $model;
    }

    /**
     * Return the model class.
     *
     * @return class-string<TModel>
     */
    abstract public function model(): string;

    /**
     * Reset the model instance.
     *
     * @return void
     */
    #[\Override]
    public function resetModel(): void
    {
        $this->makeModel();
    }

    /**
     * Get the model instance.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    #[\Override]
    public function getModel(): Model
    {
        if (!$this->model instanceof Model) {
            return $this->makeModel();
        }

        return $this->model;
    }

    /**
     * Add a new scope.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void  $scope
     * @return static
     */
    #[\Override]
    public function addScope(\Closure $scope): static
    {
        $this->scopes[] = $scope;

        return $this;
    }

    /**
     * Create a new query with active repository criteria and scopes applied.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[\Override]
    public function query(): Builder
    {
        $query = $this->prepareQueryBuilder();

        $this->resetTransientCriteria();
        $this->resetScopes();
        $this->resetModel();

        return $query;
    }

    /**
     * Alias for query().
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[\Override]
    public function newQuery(): Builder
    {
        return $this->query();
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
     * Apply all accumulated scopes to the model.
     *
     * @return static
     */
    protected function applyScopes(): static
    {
        if (!$this->model instanceof Builder && !$this->model instanceof Model) {
            $this->model = $this->makeModel();
        }

        foreach ($this->scopes as $scope) {

            if (!$this->model instanceof Builder) {
                $this->model = $this->makeModel()->newQuery();
            }

            $scope($this->model);
        }

        return $this;
    }

    /**
     * Prepare a query builder with criteria and scopes applied.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    protected function prepareQueryBuilder(): Builder
    {
        $this->applyCriteria();
        $this->applyScopes();

        if (!$this->model instanceof Builder) {
            $this->model = $this->makeModel()->newQuery();
        }

        return $this->model;
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
     * Determine whether the resolved value is a model instance.
     *
     * @param  mixed  $model
     * @return bool
     */
    private function isModelInstance(mixed $model): bool
    {
        return $model instanceof Model;
    }
}
