<?php

namespace SineMacula\Repositories\Contracts;

use Illuminate\Contracts\Database\Query\Builder;
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
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Query\Builder
     */
    public function apply(Model $model): Builder;
}
