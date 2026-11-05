<?php

declare(strict_types = 1);

namespace Tests\Support\Collections;

use Illuminate\Database\Eloquent\Collection;

/**
 * Fixture collection the author model builds its results into.
 *
 * @extends \Illuminate\Database\Eloquent\Collection<int, \Tests\Support\Models\Author>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class AuthorCollection extends Collection {}
