<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Contracts;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Declares the model and query contract a repository must expose: resolve the
 * target model, compose a query from the registered criteria and scopes, and
 * hand back a builder.
 *
 * Composing a query never mutates the repository. addScope() and resetScopes()
 * return a copy and leave the instance they were called on untouched, so the
 * composition lives on the returned value alone and discarding it discards the
 * change. Chain the query off the returned value, or keep the copy.
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
     * Add a scope composing the next query.
     *
     * Returns a copy carrying the scope; the instance it was called on is left
     * untouched, so discarding the return value discards the scope. The query
     * built from the copy applies the scope and then consumes it. A scope that
     * must apply to every query is configuration rather than composition, and
     * is registered instead of composed.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void  $scope
     * @return static
     *
     * @phpstan-pure
     */
    public function addScope(\Closure $scope): static;

    /**
     * Drop the scopes composing the next query.
     *
     * Returns a copy with no composing scopes; the instance it was called on is
     * left untouched. Scopes registered for the life of the instance are
     * configuration rather than composition, so they are deliberately kept.
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
