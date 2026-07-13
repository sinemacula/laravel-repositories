<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;

/**
 * Fixture cacheable tag repository with a one-row size guard ceiling.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Tag>
 *
 * @internal
 */
final class SizeGuardedTagRepository extends Repository
{
    use Cacheable;

    /** @var int|null Size guard row ceiling. */
    protected ?int $cacheMaxRows = 1;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Support\Models\Tag>
     */
    #[\Override]
    public function model(): string
    {
        return Tag::class;
    }
}
