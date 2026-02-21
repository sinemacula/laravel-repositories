<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Repository;
use Tests\Support\Models\TestUser;

/**
 * Repository test double that relies on base Repository::boot().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
class PlainTestUserRepository extends Repository
{
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
}
