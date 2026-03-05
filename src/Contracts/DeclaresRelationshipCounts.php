<?php

namespace SineMacula\Repositories\Contracts;

/**
 * Supplementary capability contract for criteria that declare relationship
 * count aggregations.
 *
 * Criteria implementing this contract can declare which relationship counts
 * should be included when the query executes. The repository collects these
 * declarations at criteria application time, making them available to the code
 * that composes the final query.
 *
 * This is an opt-in contract: criteria that only implement CriteriaInterface
 * are unaffected.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DeclaresRelationshipCounts
{
    /**
     * Return the relationship counts to include.
     *
     * Keys are relationship names. Values are optional constraint closures
     * (null for unconstrained counts).
     *
     * @return array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     */
    public function withCounts(): array;
}
