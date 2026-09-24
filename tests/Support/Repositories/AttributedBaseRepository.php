<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Attributes\Model;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;

/**
 * Fixture base repository declaring the model a family of subclasses shares.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Illuminate\Database\Eloquent\Model>
 *
 * @internal
 */
#[Model(Tag::class)]
abstract class AttributedBaseRepository extends Repository {}
