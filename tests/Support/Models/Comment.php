<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\Support\Scopes\TenantScope;

/**
 * Fixture comment model scoped to the current tenant, with replies.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[ScopedBy(TenantScope::class)]
final class Comment extends Model
{
    /** @var bool Indicates whether the model tracks timestamps */
    public $timestamps = false;

    /** @var string|null The backing table for comments */
    protected $table = 'comments';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /**
     * Get the replies to the comment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Support\Models\Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
