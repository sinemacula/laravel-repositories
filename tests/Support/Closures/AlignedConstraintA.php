<?php

declare(strict_types = 1);

namespace Tests\Support\Closures;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Fixture producing an eager-load constraint closure whose definition line is
 * aligned with AlignedConstraintB, so tests can prove that closures differing
 * only by file never share a fingerprint.
 *
 * The closure below MUST stay on the same line number as its counterpart in
 * AlignedConstraintB.php.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class AlignedConstraintA
{
    /**
     * Produce the aligned constraint closure.
     *
     * @return \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public static function make(): \Closure
    {
        return static fn (Builder $query): Builder => $query;
    }
}
