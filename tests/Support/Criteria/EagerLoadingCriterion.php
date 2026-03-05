<?php

declare(strict_types = 1);

namespace Tests\Support\Criteria;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use SineMacula\Repositories\Contracts\ContributesMetadata;
use SineMacula\Repositories\Contracts\CriteriaInterface;
use SineMacula\Repositories\Contracts\DeclaresEagerLoading;
use SineMacula\Repositories\Contracts\DeclaresFieldSelection;
use SineMacula\Repositories\Contracts\DeclaresRelationshipCounts;

/**
 * Test criterion implementing all supplementary capability contracts.
 *
 * Demonstrates that a criterion can declare eager-loading, field selection,
 * relationship counts, and metadata alongside query modification.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \SineMacula\Repositories\Contracts\CriteriaInterface<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
class EagerLoadingCriterion implements ContributesMetadata, CriteriaInterface, DeclaresEagerLoading, DeclaresFieldSelection, DeclaresRelationshipCounts
{
    /**
     * Apply the criterion (no-op for this test criterion).
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[Override]
    public function apply(Builder|Model $model): Builder
    {
        assert($model instanceof Builder);

        return $model;
    }

    /**
     * Declare eager-loading relationships.
     *
     * @return array<string, (Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     */
    #[Override]
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
    #[Override]
    public function fields(): array
    {
        return ['id', 'name', 'active'];
    }

    /**
     * Declare relationship counts.
     *
     * @return array<string, (Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     */
    #[Override]
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
    #[Override]
    public function metadata(): array
    {
        return [
            'source'  => 'api',
            'version' => 2,
        ];
    }
}
