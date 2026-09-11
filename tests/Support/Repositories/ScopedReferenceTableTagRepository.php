<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;

/**
 * Fixture reference-mode tag repository that registers a scope during boot.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Tag>
 *
 * @internal
 */
final class ScopedReferenceTableTagRepository extends Repository
{
    use Cacheable;

    /** @var bool Whether the repository operates in whole-table reference mode. */
    protected bool $cacheReferenceTable = true;

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

    /**
     * Register the constraint every query for this repository must carry.
     *
     * @return void
     */
    #[\Override]
    protected function boot(): void
    {
        $this->pushScope(static function (Builder $query): void {
            $query->where('name', 'php');
        });
    }
}
