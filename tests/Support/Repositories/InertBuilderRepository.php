<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\Repositories\Repository;
use Tests\Support\Builders\InertBuilder;
use Tests\Support\Models\TestUser;

/**
 * Repository test double whose prepared builder answers no method.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Tests\Support\Models\TestUser>
 *
 * @internal
 */
final class InertBuilderRepository extends Repository
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

    /**
     * Prepare a builder that answers no method.
     *
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[\Override]
    protected function prepareQueryBuilder(): Builder
    {
        return new InertBuilder;
    }
}
