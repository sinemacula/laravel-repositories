<?php

namespace SineMacula\Repositories\Contracts;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Repository interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
interface RepositoryInterface
{
    /**
     * Return the model class.
     *
     * @return class-string<TModel>
     */
    public function model(): string;

    /**
     * Create a new model instance.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function makeModel(): Model;

    /**
     * Reset the model instance.
     *
     * @return void
     */
    public function resetModel(): void;

    /**
     * Get the model instance.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getModel(): Model;

    /**
     * Add a new scope.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void  $scope
     * @return static
     */
    public function addScope(\Closure $scope): static;

    /**
     * Reset the scopes.
     *
     * @return static
     */
    public function resetScopes(): static;

    /**
     * Create a new query with active repository criteria and scopes applied.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function query(): Builder;

    /**
     * Alias for query().
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function newQuery(): Builder;
}
