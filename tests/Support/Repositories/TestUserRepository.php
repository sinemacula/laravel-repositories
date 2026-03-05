<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use Override;
use SineMacula\Repositories\Repository;
use SineMacula\Repositories\Testing\InspectsRepository;
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
class TestUserRepository extends Repository
{
    /** @use \SineMacula\Repositories\Testing\InspectsRepository<\Tests\Support\Models\TestUser> */
    use InspectsRepository;

    /** @var bool Indicates whether boot() was executed */
    public bool $booted = false;

    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Support\Models\TestUser>
     */
    #[Override]
    public function model(): string
    {
        return TestUser::class;
    }

    /**
     * Boot the repository.
     *
     * @return void
     */
    #[Override]
    protected function boot(): void
    {
        $this->booted = true;
    }
}
