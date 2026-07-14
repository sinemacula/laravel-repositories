<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model mapped to the same table as Tag, proving that two models
 * sharing a table are never fingerprinted as the same query.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class TagAlias extends Model
{
    /** @var string|null The backing table for tags */
    protected $table = 'tags';

    /** @var array<int, string> The fillable attributes */
    protected $fillable = ['name'];
}
