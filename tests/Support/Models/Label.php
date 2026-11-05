<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model for a label attached to authors.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class Label extends Model
{
    /** @var bool Indicates whether the model tracks timestamps */
    public $timestamps = false;

    /** @var string|null The backing table for labels */
    protected $table = 'labels';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];
}
