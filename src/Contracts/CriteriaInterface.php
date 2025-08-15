<?php

namespace SineMacula\Repositories\Contracts;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Criteria interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
interface CriteriaInterface
{
    /**
     * Apply the criteria to the given model.
     *
     * @param \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(Builder|Model $model): Builder;
}
