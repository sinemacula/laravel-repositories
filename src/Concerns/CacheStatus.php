<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The state of a repository cache, as its stored metadata records it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CacheStatus
{
    /**
     * Create a new cache status instance.
     *
     * @param  bool  $populated
     * @param  int|null  $age
     * @param  \Carbon\CarbonInterface|null  $lastInvalidatedAt
     * @return void
     */
    public function __construct(

        /** Whether the cache currently holds data. */
        private bool $populated,

        /** The age of the cache entry in seconds, or null if unpopulated. */
        private ?int $age,

        /** The timestamp of the last cache invalidation, if any. */
        private ?CarbonInterface $lastInvalidatedAt,
    ) {}

    /**
     * Build a cache status from the stored metadata and population flag.
     *
     * @param  array{populated_at?: int, invalidated_at?: int}|null  $meta
     * @param  bool  $populated
     * @return self
     */
    public static function fromMeta(?array $meta, bool $populated): self
    {
        $age = ($populated && isset($meta['populated_at']))
            ? (int) now()->timestamp - $meta['populated_at']
            : null;

        $lastInvalidatedAt = isset($meta['invalidated_at'])
            ? CarbonImmutable::createFromTimestamp($meta['invalidated_at'])
            : null;

        return new self($populated, $age, $lastInvalidatedAt);
    }

    /**
     * Determine whether the cache currently holds data.
     *
     * A flush outside the repository can empty the cache without this knowing.
     *
     * @return bool
     */
    public function isPopulated(): bool
    {
        return $this->populated;
    }

    /**
     * Get the age of the cache in seconds.
     *
     * @return int|null
     */
    public function getAge(): ?int
    {
        return $this->age;
    }

    /**
     * Get the timestamp of the last cache invalidation.
     *
     * @return \Carbon\CarbonInterface|null
     */
    public function getLastInvalidatedAt(): ?CarbonInterface
    {
        return $this->lastInvalidatedAt;
    }
}
