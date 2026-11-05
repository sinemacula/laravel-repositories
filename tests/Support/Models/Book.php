<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fixture book model written by an author.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class Book extends Model
{
    /** @var bool Indicates whether the model tracks timestamps */
    public $timestamps = false;

    /** @var string|null The backing table for books */
    protected $table = 'books';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /**
     * The author who wrote the book.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Support\Models\Author, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
