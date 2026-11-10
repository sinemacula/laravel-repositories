<?php

declare(strict_types = 1);

namespace Tests\Support\Builders;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Fixture builder that answers no method, so tests can reach the refusal of a
 * call the builder cannot take.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class InertBuilder implements Builder {}
