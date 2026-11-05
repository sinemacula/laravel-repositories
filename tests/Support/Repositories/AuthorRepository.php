<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Author;

/**
 * Fixture cacheable author repository on the configured cache store.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\Author>
 *
 * @internal
 */
final class AuthorRepository extends Repository
{
    use Cacheable;

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

    /**
     * Eager load every relation of the authors the next read returns.
     *
     * @return self
     */
    public function scopeWithGraph(): self
    {
        return $this->addScope(static function (Builder $query): void {
            $query->with(['books', 'genres', 'labels']);
        });
    }
}
