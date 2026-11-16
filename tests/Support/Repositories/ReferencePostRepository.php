<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Attributes\Model;
use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Post;

/**
 * Fixture reference-mode repository for the tenant-scoped post.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Post>
 *
 * @internal
 */
#[Model(Post::class)]
final class ReferencePostRepository extends Repository
{
    use Cacheable;

    /** @var bool Whether the repository caches its whole table. */
    protected bool $cacheReferenceTable = true;
}
