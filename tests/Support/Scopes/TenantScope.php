<?php

declare(strict_types = 1);

namespace Tests\Support\Scopes;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fixture global scope restricting a model to the tenant bound in the container
 * when the query runs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \Illuminate\Database\Eloquent\Scope<\Illuminate\Database\Eloquent\Model>
 *
 * @internal
 */
final class TenantScope implements Scope
{
    /** @var string The container binding holding the current tenant. */
    public const string BINDING = 'tests.tenant';

    /**
     * Apply the scope to the given builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    #[\Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('tenant'), Container::getInstance()->make(self::BINDING));
    }
}
