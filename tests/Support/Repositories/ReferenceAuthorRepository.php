<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Author;

/**
 * Fixture author repository in whole-table reference mode on the configured
 * cache store.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Author>
 *
 * @internal
 */
final class ReferenceAuthorRepository extends Repository
{
    use Cacheable;

    /** @var bool Whether the repository operates in whole-table reference mode. */
    protected bool $cacheReferenceTable = true;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Support\Models\Author>
     */
    #[\Override]
    public function model(): string
    {
        return Author::class;
    }
}
