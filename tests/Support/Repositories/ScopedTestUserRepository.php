<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\Repositories\Repository;
use SineMacula\Repositories\Testing\Concerns\InspectsRepository;
use Tests\Support\Exceptions\CompositionFailure;
use Tests\Support\Models\TestUser;

/**
 * Fixture repository registering a durable scope during boot and exposing the
 * composition shapes that can abandon per-query state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
final class ScopedTestUserRepository extends Repository
{
    /** @use \SineMacula\Repositories\Testing\Concerns\InspectsRepository<\Tests\Support\Models\TestUser> */
    use InspectsRepository;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Support\Models\TestUser>
     */
    #[\Override]
    public function model(): string
    {
        return TestUser::class;
    }

    /**
     * Compose the next query down to the active users.
     *
     * @return self
     */
    public function scopeActive(): self
    {
        return $this->addScope(static function (Builder $query): void {
            $query->where('active', true);
        });
    }

    /**
     * Compose the next query down to a name, then abort.
     *
     * Mirrors a consumer scope method that derives something after registering
     * its scope and fails doing so.
     *
     * @param  string  $name
     * @return self
     *
     * @throws \Tests\Support\Exceptions\CompositionFailure
     */
    public function scopeByNameThenAbort(string $name): self
    {
        $this->addScope(static function (Builder $query) use ($name): void {
            $query->where('name', $name);
        });

        throw new CompositionFailure('Scope composition aborted.');
    }

    /**
     * Apply the composed scopes to the current builder from inside the
     * subclass.
     *
     * Mirrors a repository that drives composition itself rather than going
     * through query(), which is why applyScopes() is protected rather than
     * private.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function composeInPlace(): Builder
    {
        $this->applyScopes();

        $builder = $this->currentModel();

        assert($builder instanceof Builder);

        return $builder;
    }

    /**
     * Register the durable ordering every query for this repository carries.
     *
     * @return void
     */
    #[\Override]
    protected function boot(): void
    {
        $this->pushScope(static function (Builder $query): void {
            $query->orderBy('name');
        });
    }
}
