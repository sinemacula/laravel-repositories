<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Shared scope-state lifecycle for repositories, covering both scope lifetimes.
 *
 * Scopes come in two kinds, and the difference is how long they live:
 * - Registered scopes (pushScope()) are configuration. They apply to every
 *   query for the life of the instance and are never consumed.
 * - Composing scopes (addScope()) compose the next query only. The next query
 *   built from the repository applies them and then discards them.
 *
 * Registered scopes are applied first, so a composing scope can still override
 * an ordering or a limit a registered one established.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait ManagesScopes
{
    /** @var array<int, \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void> Managed via addScope()/resetScopes(). Composes the next query only. */
    protected array $scopes = [];

    /** @var array<int, \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void> Managed via pushScope(). Applied to every query for the life of the instance. */
    protected array $persistentScopes = [];

    /**
     * Add a scope composing the next query.
     *
     * The scope is consumed by the next query built from this repository. Use
     * pushScope() for a scope that must apply to every query.
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
     * Reset the scopes composing the next query.
     *
     * Scopes registered with pushScope() are configuration rather than
     * composition, so they are deliberately left in place.
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
     * Register a scope that applies to every query for the life of the
     * instance.
     *
     * This is the scope counterpart of pushCriteria(), and the registrar that
     * boot() needs: a scope added with addScope() during boot() composes only
     * the first query the instance builds, because the first query consumes it.
     * A scope registered here is never consumed, so it survives every query and
     * every resetScopes().
     *
     * @api-stable
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void  $scope
     * @return static
     */
    protected function pushScope(\Closure $scope): static
    {
        $this->persistentScopes[] = $scope;

        return $this;
    }

    /**
     * Apply all accumulated scopes to the model.
     *
     * Called after prepareQueryBuilder() has normalized $model to a Builder.
     * Registered scopes run before the ones composing this query, so a
     * per-query scope can override an ordering or limit a registered one set.
     *
     * @return static
     *
     * @internal use addScope()/pushScope()/resetScopes() for scope management
     */
    protected function applyScopes(): static
    {
        if ($this->model instanceof Builder) {

            $builder = $this->model;

            foreach (array_merge($this->persistentScopes, $this->scopes) as $scope) {
                $scope($builder);
            }
        }

        return $this;
    }
}
