<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Contracts;

/**
 * Lets a criterion declare fields to select, which the repository collects when
 * it applies.
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
