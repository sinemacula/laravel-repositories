<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

/**
 * Invokes the boot{Concern} hook of every concern a repository uses, so a
 * concern needs no boot() override of its own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait BootsConcerns
{
    /**
     * Boot each used concern that exposes a boot{Concern} hook.
     *
     * @return void
     */
    private function bootConcerns(): void
    {
        foreach (class_uses_recursive(static::class) as $concern) {

            $method = 'boot' . class_basename($concern);

            if (!method_exists($this, $method)) {
                continue;
            }

            $this->{$method}(); // @phpstan-ignore method.dynamicName
        }
    }
}
