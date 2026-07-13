<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;

/**
 * Fixture cacheable tag repository that overrides every cache tuning property,
 * so the property-over-config precedence can be asserted.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Tag>
 *
 * @internal
 */
final class TunedCacheableTagRepository extends Repository
{
    use Cacheable;

    /** @var int Per-query cache duration in seconds. */
    protected int $cacheTtl = 120;

    /** @var int Reference-mode cache duration in seconds. */
    protected int $cacheReferenceTtl = 240;

    /** @var int Negative-lookup cache duration in seconds. */
    protected int $cacheNegativeTtl = 30;

    /** @var int The maximum cacheable row count. */
    protected int $cacheMaxRows = 50;

    /** @var int The maximum cacheable serialized byte size. */
    protected int $cacheMaxBytes = 2048;

    /** @var bool Whether the non-taggable key registry is enabled. */
    protected bool $cacheRegistryEnabled = false;

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
