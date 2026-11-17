<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Attributes;

/**
 * Declares the Eloquent model a repository targets.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Model
{
    /**
     * Capture the declared model class.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return void
     */
    public function __construct(

        /** @var class-string<\Illuminate\Database\Eloquent\Model> The declared model class. */
        public string $model,
    ) {}
}
