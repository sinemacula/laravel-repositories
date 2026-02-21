<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Integration test model for repository behavior checks.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class TestUser extends Model
{
    /** @var bool Indicates whether the model tracks timestamps */
    public $timestamps = false;

    /** @var string|null The backing table for test users */
    protected $table = 'test_users';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];
}
