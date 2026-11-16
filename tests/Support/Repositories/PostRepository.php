<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Attributes\Model;
use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Post;

/**
 * Fixture cacheable repository for the tenant-scoped, soft-deleting post.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Post>
 *
 * @internal
 */
#[Model(Post::class)]
final class PostRepository extends Repository
{
    use Cacheable;
}
