<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture tag model for repository cache behavior checks.
 *
 * @method static static create(array<string, string> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class Tag extends Model
{
    /** @var string|null The backing table for tags */
    protected $table = 'tags';

    /** @var array<int, string> The fillable attributes */
    protected $fillable = ['name'];
}
