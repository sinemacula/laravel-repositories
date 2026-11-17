<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Contracts;

/**
 * Lets a criterion contribute key-value metadata the repository collects when
 * it applies.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ContributesMetadata
{
    /**
     * Return the metadata to contribute.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array;
}
