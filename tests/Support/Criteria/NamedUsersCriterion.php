<?php

declare(strict_types = 1);

namespace Tests\Support\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * Limits the query to users with a specific name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
class NamedUsersCriterion implements CriteriaInterface
{
    /**
     * Constructor.
     *
     * @param  string  $name
     */
    public function __construct(

        /** The required user name */
        private readonly string $name,

    ) {}

    /**
     * Apply the name condition.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[\Override]
    public function apply(Builder|Model $model): Builder
    {
        $query = $model instanceof Model ? $model->newQuery() : $model;

        return $query->where('name', $this->name);
    }
}
