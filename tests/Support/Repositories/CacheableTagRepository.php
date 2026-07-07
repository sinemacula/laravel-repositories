<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;

/**
 * Fixture cacheable tag repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Tag>
 *
 * @internal
 */
final class CacheableTagRepository extends Repository
{
    use Cacheable;

    /** @var bool Indicates whether boot() was executed */
    public bool $booted = false;

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
     * Scope the next query to the given primary key.
     *
     * @param  int|string|null  $id
     * @return static
     */
    public function scopeById(int|string|null $id): static
    {
        return $this->addScope(static function (Builder $query) use ($id): void {
            $query->where('id', $id);
        });
    }

    /**
     * Boot the repository.
     *
     * @return void
     */
    #[\Override]
    protected function boot(): void
    {
        $this->booted = true;
    }
}
