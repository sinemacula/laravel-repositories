<?php

declare(strict_types = 1);

namespace Tests\Support\Closures;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Fixture producing an eager-load constraint closure bound to an instance, so
 * tests can prove that two closures from the same definition site but differing
 * bound-instance state never share a fingerprint.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class BoundConstraint
{
    /**
     * Create a new bound constraint instance.
     *
     * @param  string  $name
     * @return void
     */
    public function __construct(

        /** The name matched by the bound constraint. */
        private string $name,
    ) {}

    /**
     * Produce the bound constraint closure.
     *
     * @return \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function make(): \Closure
    {
        return fn (Builder $query): Builder => $query->where('name', $this->name);
    }
}
