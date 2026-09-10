<?php

declare(strict_types = 1);

namespace Tests\Support\Exceptions;

/**
 * Raised by a fixture criterion or scope that fails mid-composition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class CompositionFailure extends \RuntimeException {}
