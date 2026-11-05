<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;
use Tests\Support\Collections\AuthorCollection;
use Tests\Support\Enums\Status;

/**
 * Fixture author model carrying casts, hidden and appended attributes, a custom
 * collection and every relation shape the repository cache stores.
 *
 * @property string $name
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CollectedBy(AuthorCollection::class)]
final class Author extends Model
{
    /** @var bool Indicates whether the model tracks timestamps */
    public $timestamps = false;

    /** @var string|null The backing table for authors */
    protected $table = 'authors';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /** @var list<string> The attributes hidden from serialization */
    protected $hidden = ['secret'];

    /** @var list<string> The accessors appended to serialization */
    protected $appends = ['display_name'];

    /**
     * The books the author wrote, each pointing back at the author.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Support\Models\Book, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class)->chaperone('author');
    }

    /**
     * The genres the author writes in, ranked on the pivot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Tests\Support\Models\Genre, $this>
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'author_genre')->withPivot('position')->withTimestamps();
    }

    /**
     * The labels attached to the author.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany<\Tests\Support\Models\Label, $this>
     */
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable');
    }

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status'   => Status::class,
            'born_at'  => 'datetime',
            'meta'     => 'array',
            'settings' => AsArrayObject::class,
        ];
    }

    /**
     * Get the name the author is displayed under.
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute<uppercase-string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => Str::upper($this->name));
    }
}
