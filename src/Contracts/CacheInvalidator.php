<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Contracts;

/**
 * Invalidates a repository table's cache, whichever cache mode is active.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface CacheInvalidator
{
    /**
     * Invalidate every cached entry for the repository table.
     *
     * @return void
     */
    public function flushTable(): void;
}
