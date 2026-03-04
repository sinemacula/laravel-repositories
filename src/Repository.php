<?php

namespace SineMacula\Repositories;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Override;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use SineMacula\Repositories\Contracts\RepositoryCriteriaInterface;
use SineMacula\Repositories\Contracts\RepositoryInterface;
use SineMacula\Repositories\Exceptions\RepositoryException;

/**
 * Core Eloquent repository abstraction that coordinates model resolution, query
 * composition, and repository state lifecycle.
 *
 * This class resolves the target model from Laravel's container, applies
 * persistent/transient criteria and scopes to query builders, and forwards
 * model-style calls while resetting transient state between operations.
 *
 * ## Lifecycle Phases
 *
 * The repository operates in two distinct phases:
 *
 * **At rest** — The default state after construction and after each query
 * completes. The $model property holds a resolved Model instance. Public
 * methods such as getModel() observe this phase.
 *
 * **Query composition** — Active while a query is being built. The $model
 * property holds a Builder instance. All criteria and scopes receive a
 * Builder during this phase; no defensive Model-to-Builder conversion is
 * needed. This phase ends when query() or __call() completes and resets
 * the repository back to rest.
 *
 * Transition points:
 * - At rest → Query composition: prepareQueryBuilder()
 *   normalizes Model to Builder
 * - Query composition → At rest: query() or __call() resets via resetModel()
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @formatter:off
 *
 * @implements \SineMacula\Repositories\Contracts\RepositoryInterface<TModel>
 * @implements \SineMacula\Repositories\Contracts\RepositoryCriteriaInterface<TModel>
 *
 * @formatter:on
 *
 * @mixin \Illuminate\Contracts\Database\Eloquent\Builder
 */
abstract class Repository implements RepositoryCriteriaInterface, RepositoryInterface
{
    /** @use \SineMacula\Repositories\Concerns\ManagesCriteria<TModel> */
    use ManagesCriteria;

    /** @var \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|null The resolved model or active query builder. */
    protected Builder|Model|null $model = null;

    /** @var \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>> Managed via pushCriteria()/removeCriteria()/getCriteria()/resetCriteria(). */
    protected Collection $persistentCriteria;

    /** @var \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>> Managed via withCriteria(). Cleared after each query. */
    protected Collection $transientCriteria;

    /** @var bool Managed via enableCriteria()/disableCriteria(). */
    protected bool $disableCriteria = false;

    /** @var bool Managed via skipCriteria(). Resets after each query. */
    protected bool $skipCriteria = false;

    /** @var bool Managed via useCriteria(). Resets after each query. */
    protected bool $forceUseCriteria = false;

    /** @var array<int, \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void> Managed via addScope()/resetScopes(). */
    protected array $scopes = [];

    /** @var array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null> Eager-loading declarations from applied criteria. */
    protected array $collectedEagerLoads = [];

    /** @var array<int, string> Field selection declarations from applied criteria. */
    protected array $collectedFields = [];

    /** @var array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null> Relationship count declarations from applied criteria. */
    protected array $collectedCounts = [];

    /** @var array<string, mixed> Metadata from applied criteria. */
    protected array $collectedMetadata = [];

    /**
     * Resolve the target model and initialize criteria and scope
     * state.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function __construct(

        /** @api-stable Resolves models and criteria from the container. */
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
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
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
     * @formatter:off
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException|\SineMacula\Repositories\Exceptions\RepositoryException
     *
     * @formatter:on
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
     * Alias for query().
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[\Override]
    public function newQuery(): Builder
    {
        return $this->query();
    }

    /**
     * Create a new query with active repository criteria and scopes applied.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
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
     * Reset the model instance.
     *
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
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
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
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
     * @formatter:off
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void  $scope
     *
     * @formatter:on
     *
     * @return static
     */
    #[\Override]
    public function addScope(\Closure $scope): static
    {
        $this->scopes[] = $scope;

        return $this;
    }

    /**
     * Get the eager-loading declarations collected from the most recent
     * criteria application.
     *
     * @formatter:off
     *
     * @return array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     *
     * @formatter:on
     */
    public function getCollectedEagerLoads(): array
    {
        return $this->collectedEagerLoads;
    }

    /**
     * Get the field selection declarations collected from the most recent
     * criteria application.
     *
     * @return array<int, string>
     */
    public function getCollectedFields(): array
    {
        return $this->collectedFields;
    }

    /**
     * Get the relationship count declarations collected from the most recent
     * criteria application.
     *
     * @formatter:off
     *
     * @return array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     *
     * @formatter:on
     */
    public function getCollectedCounts(): array
    {
        return $this->collectedCounts;
    }

    /**
     * Get the metadata collected from the most recent criteria application.
     *
     * @return array<string, mixed>
     */
    public function getCollectedMetadata(): array
    {
        return $this->collectedMetadata;
    }

    /**
     * Boot the repository instance.
     *
     * Override this method to perform subclass initialization
     * such as registering persistent criteria, adding scopes,
     * or configuring subclass-specific state.
     *
     * When this method is called, the following state is guaranteed:
     * - $app holds the Application instance
     * - $persistentCriteria and $transientCriteria are empty Collections
     * - All criteria flags are at their defaults
     *   (disabled=false, skip=false, force=false)
     * - $scopes is an empty array
     * - $model holds a resolved Model instance
     *
     * It is safe to call pushCriteria(), addScope(), getModel(), and any public
     * method during boot().
     *
     * @api-stable
     *
     * @return void
     */
    protected function boot(): void {}

    /**
     * Prepare a query builder with criteria and scopes applied.
     *
     * Normalizes the model to a Builder before applying criteria and scopes,
     * guaranteeing that all criteria receive a Builder input
     * (never a raw Model).
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @internal Orchestration method. Use query() to obtain a prepared builder.
     */
    protected function prepareQueryBuilder(): Builder
    {
        if (!$this->model instanceof Builder) {
            $this->model = $this->makeModel()->newQuery();
        }

        $this->applyCriteria();
        $this->applyScopes();

        return $this->model;
    }

    /**
     * Apply all accumulated scopes to the model.
     *
     * Called after prepareQueryBuilder() has normalized $model to a Builder.
     *
     * @return static
     *
     * @internal use addScope()/resetScopes() for scope management
     */
    protected function applyScopes(): static
    {
        if ($this->model instanceof Builder) {

            $builder = $this->model;

            foreach ($this->scopes as $scope) {
                $scope($builder);
            }
        }

        return $this;
    }

    /**
     * Reset the various transient values and return the result.
     *
     * @param  mixed  $result
     * @return mixed
     *
     * @internal cleanup step in the magic method forwarding pipeline
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
