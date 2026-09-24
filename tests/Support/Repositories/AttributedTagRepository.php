<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Attributes\Model;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;

/**
 * Fixture repository that declares its model with the attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Tag>
 *
 * @internal
 */
#[Model(Tag::class)]
final class AttributedTagRepository extends Repository {}
