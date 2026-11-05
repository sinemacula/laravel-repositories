<?php

declare(strict_types = 1);

namespace Tests\Support\Collections;

use Illuminate\Support\Collection;

/**
 * Fixture collection that cannot be built without a key, so a payload naming it
 * cannot be read back.
 *
 * @extends \Illuminate\Support\Collection<array-key, string>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class KeyedCollection extends Collection
{
    /**
     * Create a new keyed collection.
     *
     * @param  string  $key
     * @param  array<array-key, string>  $items
     * @return void
     */
    public function __construct(

        /** The key the collection is grouped under. */
        public readonly string $key,

        // The items the collection holds.
        array $items = [],
    ) {
        parent::__construct($items);
    }
}
