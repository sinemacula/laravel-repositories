<?php

declare(strict_types = 1);

namespace Tests\Support\Relations;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixture relation class of an application's own, extending a framework
 * relation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Support\Models\Comment, \Tests\Support\Models\Post>
 *
 * @internal
 */
final class RankedHasMany extends HasMany {}
