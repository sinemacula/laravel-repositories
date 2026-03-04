<?php

namespace SineMacula\Repositories\Contracts;

/**
 * Presentable interface.
 *
 * @formatter:off
 *
 * @deprecated Since v2.0.0, will be removed in v3.0.0. This interface is unused within the
 *             package and has no documented purpose. If you implement this interface, please
 *             open an issue to discuss your use case before the removal version.
 *
 * @formatter:on
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
