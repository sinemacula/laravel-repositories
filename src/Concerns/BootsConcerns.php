<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

/**
 * Invokes the dedicated boot hook of every bootable concern used by a
 * repository.
 *
 * Concerns such as Cacheable need boot-time setup but cannot override boot()
 * without colliding with a subclass boot() override, so the repository invokes
 * their dedicated boot hooks after boot() has run instead. This lets a single
 * repository safely use more than one bootable concern.
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
