<?php

declare(strict_types = 1);

namespace Tests\Support\Concerns;

/**
 * Fixture concern exposing a dedicated boot hook, so the repository concern
 * boot chain can be asserted.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait TracksBooting
{
    /** @var bool Indicates whether the concern boot hook was executed */
    private bool $concernBooted = false;

    /**
     * Determine whether the concern boot hook was executed.
     *
     * @return bool
     */
    public function hasBootedConcern(): bool
    {
        return $this->concernBooted;
    }

    /**
     * Boot the concern.
     *
     * @return void
     */
    protected function bootTracksBooting(): void
    {
        $this->concernBooted = true;
    }
}
