<?php

declare(strict_types = 1);

namespace Tests\Support\Concerns;

/**
 * Provides reflection helpers for accessing non-public class members in tests.
 *
 * @SuppressWarnings("php:S3011")
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait InteractsWithNonPublicMembers
{
    /**
     * Invoke a non-public method on the given object.
     *
     * @param  object  $object
     * @param  string  $method
     * @param  mixed  ...$args
     * @return mixed
     */
    protected function invokeMethod(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);

        return $reflection->invoke($object, ...$args);
    }

    /**
     * Get a non-public property value from the given object.
     *
     * @param  object  $object
     * @param  string  $property
     * @return mixed
     */
    protected function getProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }

    /**
     * Set a non-public property value on the given object.
     *
     * @param  object  $object
     * @param  string  $property
     * @param  mixed  $value
     * @return void
     */
    protected function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);

        $reflection->setValue($object, $value);
    }
}
