<?php

namespace SineMacula\Repositories\Contracts;

/**
 * Criteria interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface PresentableInterface
{
    /**
     * Return the presenter class.
     *
     * @return class-string
     */
    public function presenter(): string;
}
