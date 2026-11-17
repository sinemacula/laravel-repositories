<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

/**
 * Marks a cached read that found nothing, as distinct from an absent entry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CacheMiss {}
