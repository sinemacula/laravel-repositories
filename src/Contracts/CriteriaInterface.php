<?php

namespace SineMacula\Repositories\Contracts;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Criteria interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
interface CriteriaInterface
{
    /**
     * Apply the criteria to the given model.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|TModel  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(Builder|Model $model): Builder;
}
