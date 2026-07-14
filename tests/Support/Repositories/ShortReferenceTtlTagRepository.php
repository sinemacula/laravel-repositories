<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;

/**
 * Fixture cacheable tag repository operating in whole-table reference mode
 * with a short reference-mode TTL.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Tag>
 *
 * @internal
 */
final class ShortReferenceTtlTagRepository extends Repository
{
    use Cacheable;

    /** @var bool Whether the repository operates in whole-table reference mode. */
    protected bool $cacheReferenceTable = true;

    /** @var int Reference-mode cache duration in seconds. */
    protected int $cacheReferenceTtl = 5;

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
