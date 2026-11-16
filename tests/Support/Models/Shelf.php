<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Config;

/**
 * Fixture shelf model whose relations read a key column from configuration when
 * they are built, so a relation's key changes without changing the SQL of its
 * unconstrained query.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class Shelf extends Model
{
    /** @var string The configuration key naming the column each relation keys on. */
    public const string KEY = 'tests.shelf_key';

    /** @var bool Indicates whether the model tracks timestamps */
    public $timestamps = false;

    /** @var string|null The backing table for shelves */
    protected $table = 'shelves';

    /**
     * Get the author who owns the shelf.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Support\Models\Author, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Author::class, Config::string(self::KEY));
    }

    /**
     * Get the books on the shelf.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Support\Models\Book, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class, Config::string(self::KEY));
    }

    /**
     * Get the comments on the posts about the shelf.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough<\Tests\Support\Models\Comment, \Tests\Support\Models\Post, $this>
     */
    public function comments(): HasManyThrough
    {
        return $this->hasManyThrough(Comment::class, Post::class, Config::string(self::KEY));
    }

    /**
     * Get the labels attached to the shelf.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\Tests\Support\Models\Label, $this>
     */
    public function labels(): MorphMany
    {
        return $this->morphMany(Label::class, 'labelable', Config::string(self::KEY), 'labelable_id');
    }

    /**
     * Get the genres the shelf holds.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Tests\Support\Models\Genre, $this>
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'genre_shelf', Config::string(self::KEY));
    }

    /**
     * Get the tags on the shelf.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany<\Tests\Support\Models\Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, Config::string(self::KEY), 'taggables', 'taggable_id');
    }
}
