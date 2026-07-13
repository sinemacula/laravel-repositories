<?php

declare(strict_types = 1);

namespace Tests\Support\Enums;

/**
 * Fixture backed enum for status values.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
enum Status: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
}
