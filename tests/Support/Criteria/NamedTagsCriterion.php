<?php

declare(strict_types = 1);

namespace Tests\Support\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * Fixture criterion filtering tags by name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\Tag>
 *
 * @internal
 */
final class NamedTagsCriterion implements CriteriaInterface
{
    /**
     * Constructor.
     *
     * @param  string  $name
     */
    public function __construct(

        /** The required tag name */
        private readonly string $name,
    ) {}

    /**
     * Apply the name condition.
     *
     * The repository guarantees Builder input; the assertion narrows the
     * type for static analysis only.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Tests\Support\Models\Tag  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[\Override]
    public function apply(Builder|Model $model): Builder
    {
        assert($model instanceof Builder);

        return $model->where('name', $this->name);
    }
}
