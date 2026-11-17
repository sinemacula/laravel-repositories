<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Contracts;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The model and query contract a repository exposes.
 *
 * addScope() and resetScopes() return a copy and leave the instance untouched,
 * so chain the query off the returned value.
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
     * Return a copy carrying a scope that composes the next query.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void  $scope
     * @return static
     *
     * @phpstan-pure
     */
    public function addScope(\Closure $scope): static;

    /**
     * Return a copy without the scopes composing the next query.
     *
     * Registered scopes are kept.
     *
     * @return static
     *
     * @phpstan-pure
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
