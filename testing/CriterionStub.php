<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Testing;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * Configurable criterion stub for downstream tests.
 *
 * Provides a simple, predictable criterion that can be used in tests to verify
 * repository behavior such as criteria ordering, application counts, and flag
 * interactions without writing a custom criterion class.
 *
 * See TESTING.md for usage patterns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \SineMacula\Repositories\Contracts\CriteriaInterface<\Illuminate\Database\Eloquent\Model>
 */
class CriterionStub implements CriteriaInterface
{
    /** @var int The number of times apply() has been called */
    public int $applyCount = 0;

    /**
     * Create a new criterion stub.
     *
     * // @formatter:off
     *
     * @param  (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): \Illuminate\Contracts\Database\Eloquent\Builder)|null  $callback
     *                                                                                                                                       // @formatter:on
     */
    public function __construct(

        /** The optional callback to apply to the builder. */
        private readonly ?\Closure $callback = null,

    ) {}

    /**
     * Apply the criterion.
     *
     * If a callback was provided, it is invoked with the builder and its return
     * value is used. Otherwise the builder is returned unmodified.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[\Override]
    public function apply(Builder|Model $model): Builder
    {
        $this->applyCount++;

        assert($model instanceof Builder);

        if ($this->callback !== null) {
            return ($this->callback)($model);
        }

        return $model;
    }

    /**
     * Determine whether the criterion was applied.
     *
     * @return bool
     */
    public function wasApplied(): bool
    {
        return $this->applyCount > 0;
    }
}
