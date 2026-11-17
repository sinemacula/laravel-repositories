<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Manages the scopes a repository applies: registered scopes apply to every
 * query, composing scopes to the next query only.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait ManagesScopes
{
    /** @var array<int, \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void> The scopes composing the next query only. */
    protected array $scopes = [];

    /** @var array<int, \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void> The scopes applied to every query. */
    protected array $persistentScopes = [];

    /**
     * Return a copy carrying a scope that composes the next query.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void  $scope
     * @return static
     *
     * @phpstan-pure
     */
    #[\Override]
    public function addScope(\Closure $scope): static
    {
        $composed = clone $this;

        // @phpstan-ignore impure.propertyAssign
        $composed->scopes[] = $scope;

        return $composed;
    }

    /**
     * Return a copy without the scopes composing the next query.
     *
     * Scopes registered with pushScope() are kept.
     *
     * @return static
     *
     * @phpstan-pure
     */
    #[\Override]
    public function resetScopes(): static
    {
        $composed = clone $this;

        // @phpstan-ignore impure.methodCall
        $composed->clearComposingScopes();

        return $composed;
    }

    /**
     * Register a scope that applies to every query, such as one added in
     * boot().
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
     * Apply the registered scopes, then the composing ones, to the model.
     *
     * @return static
     *
     * @internal
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

    /**
     * Clear the scopes composing the next query in place.
     *
     * @return void
     */
    private function clearComposingScopes(): void
    {
        $this->scopes = [];
    }
}
