<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Repository;

/**
 * Fixture repository that declares no model at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Illuminate\Database\Eloquent\Model>
 *
 * @internal
 */
final class UndeclaredModelRepository extends Repository {}
