<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Contracts;

/**
 * Lets a criterion declare relationships to eager load, which the repository
 * collects when it applies.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface DeclaresEagerLoading
{
    /**
     * Return the relationships to eager load, each with an optional constraint.
     *
     * @return array<string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null>
     */
    public function eagerLoads(): array;
}
