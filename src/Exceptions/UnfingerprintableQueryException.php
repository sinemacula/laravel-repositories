<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Exceptions;

/**
 * Thrown when a prepared query has no stable representation and cannot be
 * fingerprinted for caching.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UnfingerprintableQueryException extends RepositoryException {}
