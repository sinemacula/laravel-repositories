<?php

declare(strict_types = 1);

namespace Tests\Support\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SineMacula\Repositories\Contracts\CriteriaInterface;
use Tests\Support\Exceptions\CompositionFailure;

/**
 * Constrains the query and then fails, leaving the builder half-applied.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
final class FailingUsersCriterion implements CriteriaInterface
{
    /** @var string The message carried by the raised failure. */
    public const string MESSAGE = 'Criteria composition failed.';

    /**
     * Mutate the builder and then raise a failure.
     *
     * The mutation happens first so the failure leaves a partially constrained
     * builder behind, which is the state the failure path has to discard.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @throws \Tests\Support\Exceptions\CompositionFailure
     */
    #[\Override]
    public function apply(Builder|Model $model): Builder
    {
        assert($model instanceof Builder);

        $model->where('name', 'Bob');

        throw new CompositionFailure(self::MESSAGE);
    }
}
