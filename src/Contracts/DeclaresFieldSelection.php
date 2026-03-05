<?php

namespace SineMacula\Repositories\Contracts;

/**
 * Supplementary capability contract for criteria that declare field selection.
 *
 * Criteria implementing this contract can declare which fields should be
 * selected when the query executes. The repository collects these declarations
 * at criteria application time, making them available to the code that composes
 * the final query.
 *
 * This is an opt-in contract: criteria that only implement CriteriaInterface
 * are unaffected.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DeclaresFieldSelection
{
    /**
     * Return the fields to select.
     *
     * @return array<int, string>
     */
    public function fields(): array;
}
