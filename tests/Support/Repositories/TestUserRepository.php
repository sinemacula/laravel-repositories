<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\Repositories\Repository;
use SineMacula\Repositories\Testing\Concerns\InspectsRepository;
use Tests\Support\Exceptions\CompositionFailure;
use Tests\Support\Models\TestUser;

/**
 * Repository test double for exercising repository internals.
 *
 * Uses the exported InspectsRepository trait for state observation and
 * mutation, eliminating the need for custom shadow API methods.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
final class TestUserRepository extends Repository
{
    /** @use \SineMacula\Repositories\Testing\Concerns\InspectsRepository<\Tests\Support\Models\TestUser> */
    use InspectsRepository;

    /** @var string The message carried by the aborted scope method. */
    public const string ABORT_MESSAGE = 'Scope composition aborted.';

    /** @var bool Indicates whether boot() was executed */
    public bool $booted = false;

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
     * Register a name scope and then abort.
     *
     * Mimics a repository method that fails after it has already composed part
     * of the next query. The composed copy is abandoned, so nothing reaches the
     * instance the caller holds.
     *
     * @param  string  $name
     * @return self
     *
     * @throws \Tests\Support\Exceptions\CompositionFailure
     */
    public function scopeByNameThenAbort(string $name): self
    {
        $composed = $this->addScope(static function (Builder $query) use ($name): void {
            $query->where('name', $name);
        });

        // The copy really did compose; the exception carries how many scopes it
        // holds so a test can prove the abandoned work existed and still never
        // reached the instance the caller holds.
        throw new CompositionFailure(self::ABORT_MESSAGE, $composed->scopesCount());
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
