<?php

declare(strict_types = 1);

namespace SineMacula\Repositories;

use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SineMacula\Repositories\Attributes\Model as ModelAttribute;
use SineMacula\Repositories\Concerns\BootsConcerns;
use SineMacula\Repositories\Concerns\ManagesCriteria;
use SineMacula\Repositories\Concerns\ManagesScopes;
use SineMacula\Repositories\Concerns\ResetsTransientState;
use SineMacula\Repositories\Contracts\RepositoryCriteriaInterface;
use SineMacula\Repositories\Contracts\RepositoryInterface;
use SineMacula\Repositories\Exceptions\RepositoryException;

/**
 * Base Eloquent repository: resolves the model and composes queries from
 * criteria and scopes.
 *
 * Composition (addScope(), resetScopes(), withCriteria(), useCriteria(),
 * skipCriteria()) returns a copy and leaves this instance untouched, while
 * configuration (pushScope() and the other criteria methods) mutates it.
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
    use BootsConcerns, ManagesCriteria, ManagesScopes, ResetsTransientState;

    /** @var \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|null The resolved model or active query builder. */
    protected Builder|Model|null $model = null;

    /** @var \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>> The criteria applied to every query. */
    protected Collection $persistentCriteria;

    /** @var \Illuminate\Support\Collection<int, \SineMacula\Repositories\Contracts\CriteriaInterface<TModel>> The criteria applied to the next query only. */
    protected Collection $transientCriteria;

    /** @var bool Whether persistent criteria are disabled. */
    protected bool $disableCriteria = false;

    /** @var bool Whether the next query skips every criterion. */
    protected bool $skipCriteria = false;

    /** @var bool Whether the next query applies persistent criteria even when disabled. */
    protected bool $forceUseCriteria = false;

    /** @var array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null> Eager-loading declarations from applied criteria. */
    protected array $collectedEagerLoads = [];

    /** @var array<int, string> Field selection declarations from applied criteria. */
    protected array $collectedFields = [];

    /** @var array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null> Relationship count declarations from applied criteria. */
    protected array $collectedCounts = [];

    /** @var array<string, mixed> Metadata from applied criteria. */
    protected array $collectedMetadata = [];

    /** @var class-string<TModel>|null The model class read from the attribute. */
    private ?string $declaredModel = null;

    /**
     * Resolve the target model and initialize criteria state.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
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
        $this->makeModel();
        $this->boot();
        $this->bootConcerns();
    }

    /**
     * Isolate a composed copy from the instance it was composed from.
     *
     * The cache collaborators stay shared, so a flush through either handle
     * reaches both.
     *
     * @return void
     */
    public function __clone(): void
    {
        $this->model              = null;
        $this->persistentCriteria = clone $this->persistentCriteria;
        $this->transientCriteria  = clone $this->transientCriteria;
    }

    /**
     * Trigger a static method call on the repository.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \BadMethodCallException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $container = Container::getInstance();

        if ($container instanceof Application) {

            $instance = $container->make(static::class);
            $callable = [$instance, $method];

            if (!is_callable($callable)) {
                throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', get_debug_type($instance), $method));
            }

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
     * @throws \BadMethodCallException
     * @throws \Throwable
     */
    public function __call(string $method, array $arguments): mixed
    {
        try {

            $query    = $this->prepareQueryBuilder();
            $callable = [$query, $method];

            if (!is_callable($callable)) {
                throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', $query::class, $method));
            }

            return $this->resetAndReturn($callable(...$arguments));
        } catch (\Throwable $exception) {

            $this->resetAfterFailure();

            throw $exception;
        }
    }

    /**
     * Retrieve all models for the repository through the get() pipeline.
     *
     * Neither Eloquent builder has an all() method to forward to.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function all(mixed ...$arguments): mixed
    {
        return $this->__call('get', array_values($arguments));
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

        // @phpstan-ignore instanceof.alwaysTrue
        if (!$model instanceof Model) {
            throw new RepositoryException("Class {$this->model()} must be an instance of Illuminate\\Database\\Eloquent\\Model");
        }

        return $this->model = $model;
    }

    /**
     * Return the model class, by default the one the Model attribute names.
     *
     * @return class-string<TModel>
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[\Override]
    public function model(): string
    {
        return $this->declaredModel ??= $this->resolveDeclaredModel();
    }

    /**
     * Alias for query().
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @throws \Throwable
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
     * @throws \Throwable
     */
    #[\Override]
    public function query(): Builder
    {
        try {

            $query = $this->prepareQueryBuilder();

            $this->resetTransientCriteria();
            $this->clearComposingScopes();
            $this->resetModel();

            return $query;
        } catch (\Throwable $exception) {

            $this->resetAfterFailure();

            throw $exception;
        }
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
     * Get the eager loads collected from the most recent criteria application.
     *
     * @return array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     */
    public function getCollectedEagerLoads(): array
    {
        return $this->collectedEagerLoads;
    }

    /**
     * Get the fields collected from the most recent criteria application.
     *
     * @return array<int, string>
     */
    public function getCollectedFields(): array
    {
        return $this->collectedFields;
    }

    /**
     * Get the relationship counts collected from the most recent criteria
     * application.
     *
     * @return array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
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
     * Boot the repository instance, after the model resolves and before any
     * concern boots.
     *
     * Register scopes here with pushScope(): addScope() returns a copy, so a
     * scope added with it is discarded. Concern methods such as the cache
     * operations are not yet available.
     *
     * @api-stable
     *
     * @return void
     */
    protected function boot(): void {}

    /**
     * Prepare a query builder with criteria and scopes applied.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     *
     * @internal
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
     * Reset the various transient values and return the result.
     *
     * @param  mixed  $queryResult
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     *
     * @internal
     */
    protected function resetAndReturn(mixed $queryResult): mixed
    {
        $this->resetTransientCriteria();
        $this->clearComposingScopes();
        $this->resetModel();

        return $queryResult;
    }

    /**
     * Resolve the model class named by the nearest Model attribute in the class
     * hierarchy, since attributes are not inherited.
     *
     * A declaration without arguments is skipped: a generated test double
     * copies the attribute by name alone.
     *
     * @return class-string<TModel>
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function resolveDeclaredModel(): string
    {
        for ($class = static::class; $class !== false; $class = get_parent_class($class)) {

            foreach ((new \ReflectionClass($class))->getAttributes(ModelAttribute::class) as $attribute) {

                if ($attribute->getArguments() !== []) {
                    // @phpstan-ignore return.type
                    return $attribute->newInstance()->model;
                }
            }
        }

        throw new RepositoryException(sprintf('Repository `%s` must declare its model with the `%s` attribute or by overriding model().', static::class, ModelAttribute::class));
    }
}
