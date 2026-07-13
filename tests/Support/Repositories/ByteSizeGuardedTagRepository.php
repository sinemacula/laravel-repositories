<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;

/**
 * Fixture cacheable tag repository with a byte-size guard ceiling too small
 * for any real result to pass.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Tag>
 *
 * @internal
 */
final class ByteSizeGuardedTagRepository extends Repository
{
    use Cacheable;

    /** @var int|null Size guard byte ceiling. */
    protected ?int $cacheMaxBytes = 8;

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
