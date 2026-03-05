<?php

declare(strict_types = 1);

namespace Tests\Support\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * Limits the query to active users.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
class ActiveUsersCriterion implements CriteriaInterface
{
    /**
     * Apply the active-user condition.
     *
     * The repository guarantees Builder input; the assertion narrows the
     * type for static analysis only.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[Override]
    public function apply(Builder|Model $model): Builder
    {
        assert($model instanceof Builder);

        return $model->where('active', true);
    }
}
