<?php

declare(strict_types = 1);

namespace Tests\Support\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SineMacula\Repositories\Contracts\ContributesMetadata;
use SineMacula\Repositories\Contracts\CriteriaInterface;
use SineMacula\Repositories\Contracts\DeclaresEagerLoading;
use SineMacula\Repositories\Contracts\DeclaresFieldSelection;
use SineMacula\Repositories\Contracts\DeclaresRelationshipCounts;

/**
 * Fixture criterion implementing every supplementary capability contract.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
final class EagerLoadingCriterion implements ContributesMetadata, CriteriaInterface, DeclaresEagerLoading, DeclaresFieldSelection, DeclaresRelationshipCounts
{
    /**
     * Return the builder unchanged.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[\Override]
    public function apply(Builder|Model $model): Builder
    {
        assert($model instanceof Builder);

        return $model;
    }

    /**
     * Declare eager-loading relationships.
     *
     * @return array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     */
    #[\Override]
    public function eagerLoads(): array
    {
        return [
            'posts'    => null,
            'comments' => null,
        ];
    }

    /**
     * Declare fields to select.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function fields(): array
    {
        return ['id', 'name', 'active'];
    }

    /**
     * Declare relationship counts.
     *
     * @return array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     */
    #[\Override]
    public function withCounts(): array
    {
        return [
            'posts' => null,
        ];
    }

    /**
     * Contribute metadata.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function metadata(): array
    {
        return [
            'source'  => 'api',
            'version' => 2,
        ];
    }
}
