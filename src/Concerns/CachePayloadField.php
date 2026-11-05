<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

/**
 * Reads the typed fields of a stored payload node, rejecting a node whose field
 * is missing or of another type.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class CachePayloadField
{
    /**
     * Check that a value outside an array node is a scalar or null.
     *
     * @param  mixed  $value
     * @return scalar|null
     *
     * @throws \UnexpectedValueException
     */
    public static function scalar(mixed $value): bool|float|int|string|null
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \UnexpectedValueException('A stored value is neither a scalar nor an array');
        }

        return $value;
    }

    /**
     * Get a class name a node holds, checking it is the given type.
     *
     * @template TClass of object
     *
     * @param  array<array-key, mixed>  $node
     * @param  class-string<TClass>  $type
     * @return class-string<TClass>
     *
     * @throws \UnexpectedValueException
     */
    public static function classOf(array $node, string $type): string
    {
        $class = self::field($node, 'class');

        if (!is_string($class) || !is_a($class, $type, true)) {
            throw new \UnexpectedValueException('A stored class is missing or of the wrong type');
        }

        return $class;
    }

    /**
     * Get the raw attributes a node holds.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $key
     * @return array<string, scalar|null>
     *
     * @throws \UnexpectedValueException
     */
    public static function attributes(array $node, string $key): array
    {
        $attributes = self::list($node, $key);

        foreach ($attributes as $name => $value) {
            if (!is_string($name) || ($value !== null && !is_scalar($value))) {
                throw new \UnexpectedValueException('A stored attribute is not a named scalar');
            }
        }

        /** @var array<string, scalar|null> */
        return $attributes;
    }

    /**
     * Get the strings a node holds under the given key.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $key
     * @return array<array-key, string>
     *
     * @throws \UnexpectedValueException
     */
    public static function strings(array $node, string $key): array
    {
        $values = self::list($node, $key);

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException('A stored list holds a value other than a string');
            }
        }

        /** @var array<array-key, string> */
        return $values;
    }

    /**
     * Get the array a node holds under the given key.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $key
     * @return array<array-key, mixed>
     *
     * @throws \UnexpectedValueException
     */
    public static function list(array $node, string $key): array
    {
        $value = self::field($node, $key);

        if (!is_array($value)) {
            throw new \UnexpectedValueException('A stored value is not an array');
        }

        return $value;
    }

    /**
     * Get the string a node holds under the given key.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $key
     * @return string
     *
     * @throws \UnexpectedValueException
     */
    public static function string(array $node, string $key): string
    {
        $value = self::field($node, $key);

        if (!is_string($value)) {
            throw new \UnexpectedValueException('A stored value is not a string');
        }

        return $value;
    }

    /**
     * Get the string or null a node holds under the given key.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $key
     * @return string|null
     *
     * @throws \UnexpectedValueException
     */
    public static function nullableString(array $node, string $key): ?string
    {
        $value = self::field($node, $key);

        return $value === null ? null : self::string($node, $key);
    }

    /**
     * Get the boolean a node holds under the given key.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $key
     * @return bool
     *
     * @throws \UnexpectedValueException
     */
    public static function boolean(array $node, string $key): bool // phpcs:ignore SineMacula.NamingConventions.BooleanMethodName.NotPredicate
    {
        $value = self::field($node, $key);

        if (!is_bool($value)) {
            throw new \UnexpectedValueException('A stored value is not a boolean');
        }

        return $value;
    }

    /**
     * Get the integer a node holds under the given key.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $key
     * @return int
     *
     * @throws \UnexpectedValueException
     */
    public static function integer(array $node, string $key): int
    {
        $value = self::field($node, $key);

        if (!is_int($value)) {
            throw new \UnexpectedValueException('A stored value is not an integer');
        }

        return $value;
    }

    /**
     * Get the value a node holds under the given key, which must be present.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $key
     * @return mixed
     *
     * @throws \UnexpectedValueException
     */
    public static function field(array $node, string $key): mixed
    {
        if (!array_key_exists($key, $node)) {
            throw new \UnexpectedValueException('A stored value is missing a field');
        }

        return $node[$key];
    }
}
