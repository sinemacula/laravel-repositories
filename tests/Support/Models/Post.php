<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\Support\Relations\RankedHasMany;
use Tests\Support\Scopes\TenantScope;

/**
 * Fixture post model that soft deletes and is scoped to the current tenant.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[ScopedBy(TenantScope::class)]
final class Post extends Model
{
    use SoftDeletes;

    /** @var bool Indicates whether the model tracks timestamps */
    public $timestamps = false;

    /** @var string|null The backing table for posts */
    protected $table = 'posts';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /**
     * Get the comments on the post.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Support\Models\Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get the book the post reviews.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Support\Models\Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Get the record the post is about.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the comments on the post through a relation class of the
     * application's own.
     *
     * @return \Tests\Support\Relations\RankedHasMany
     *
     * @phpstan-ignore sineMaculaLaravel.modelBehaviour
     */
    public function rankedComments(): RankedHasMany
    {
        $comment = new Comment;

        return new RankedHasMany($comment->newQuery(), $this, $comment->qualifyColumn('post_id'), 'id');
    }
}
