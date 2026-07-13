<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;

/**
 * Fixture cacheable tag repository backed by the non-taggable file store with
 * the version-bump registry disabled.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Tag>
 *
 * @internal
 */
final class RegistryDisabledFileStoreTagRepository extends Repository
{
    use Cacheable;

    /** @var string|null Laravel cache store name. */
    protected ?string $cacheStoreName = 'file';

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
