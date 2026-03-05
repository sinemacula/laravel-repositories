<?php

namespace SineMacula\Repositories\Contracts;

/**
 * Supplementary capability contract for criteria that declare eager-loading
 * relationships.
 *
 * Criteria implementing this contract can declare which relationships should be
 * eager-loaded when the query executes. The repository collects these
 * declarations at criteria application time, making them available to the code
 * that composes the final query.
 *
 * This is an opt-in contract: criteria that only implement CriteriaInterface
 * are unaffected.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DeclaresEagerLoading
{
    /**
     * Return the relationships to eager-load.
     *
     * Keys are relationship names. Values are optional constraint closures
     * (null for unconstrained eager-loading).
     *
     * @return array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     */
    public function eagerLoads(): array;
}
