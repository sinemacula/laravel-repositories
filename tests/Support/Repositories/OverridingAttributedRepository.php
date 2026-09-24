<?php

declare(strict_types = 1);

namespace Tests\Support\Repositories;

use Tests\Support\Models\TagAlias;

/**
 * Fixture repository overriding the model an ancestor's attribute declares.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class OverridingAttributedRepository extends AttributedBaseRepository
{
    /**
     * Return the model class.
     *
     * @return class-string<\Tests\Support\Models\TagAlias>
     */
    #[\Override]
    public function model(): string
    {
        return TagAlias::class;
    }
}
