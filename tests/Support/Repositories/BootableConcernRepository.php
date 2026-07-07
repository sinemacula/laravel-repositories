<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use SineMacula\Repositories\Repository;
use Tests\Support\Concerns\TracksBooting;
use Tests\Support\Models\TestUser;

/**
 * Repository test double using a concern with a dedicated boot hook.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
final class BootableConcernRepository extends Repository
{
    use TracksBooting;

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
