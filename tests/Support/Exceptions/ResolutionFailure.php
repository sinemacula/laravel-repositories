<?php

declare(strict_types = 1);

namespace Tests\Support\Exceptions;

/**
 * Raised by a fixture container binding that cannot resolve the model.
 *
 * Deliberately outside the resolution exceptions the package declares, so a
 * test can prove failure cleanup copes with an unexpected throwable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ResolutionFailure extends \RuntimeException {}
