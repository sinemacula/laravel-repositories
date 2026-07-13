<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Derives a stable, collision-resistant fingerprint for a prepared query
 * builder and the read verb applied to it, so that distinct reads map to
 * distinct cache keys.
 *
 * The fingerprint combines the connection name, the database name, the
 * compiled SQL, the normalised bindings, the read verb, its arguments, and the
 * registered eager loads. Folding the verb and its arguments in is essential:
 * read verbs such as find(), value(), pluck(), and a column-projected get()
 * apply their constraints at execution time - after the base builder is
 * compiled - so two reads sharing one base builder (e.g. find(1) and find(2))
 * would otherwise collide on a single cache key. Eager loads are likewise
 * invisible to the compiled base SQL, so with('posts')->get() would collide
 * with a plain get(). Eager-load constraint closures are fingerprinted by
 * their definition site and captured variables, so two constraints on the same
 * relation never collide.
 *
 * Values that have no stable representation (e.g. closures passed as verb
 * arguments) cannot be fingerprinted; fingerprinting throws so the caller can
 * fall back to an uncached read rather than risk serving another query's
 * result.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryFingerprint
{
    /**
     * Build a fingerprint for the given prepared query builder and read verb.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return string
     *
     * @throws \Exception
     */
    public static function for(Builder $query, string $method = '', array $arguments = []): string
    {
        $base       = $query->getQuery();
        $connection = $base->getConnection();

        $components = [
            $connection instanceof Connection ? $connection->getName() : '',
            $connection->getDatabaseName(),
            $base->toSql(),
            self::normalise($base->getBindings()),
            $method,
            self::normalise($arguments),
            self::normaliseEagerLoads($query),
        ];

        return hash('xxh128', implode('|', $components));
    }

    /**
     * Normalise a value list into a stable string representation, falling back
     * to serialisation when the values are not JSON-encodable.
     *
     * @param  array<int|string, mixed>  $values
     * @return string
     *
     * @throws \Exception
     */
    private static function normalise(array $values): string
    {
        $normalised = array_map(fn (mixed $value): mixed => self::normaliseValue($value), $values);

        $encoded = json_encode($normalised);

        return $encoded === false ? serialize($normalised) : $encoded;
    }

    /**
     * Normalise the registered eager loads into a stable, order-independent
     * string representation that includes each constraint's identity.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @return string
     *
     * @throws \Exception
     */
    private static function normaliseEagerLoads(Builder $query): string
    {
        $relations = $query instanceof EloquentBuilder ? $query->getEagerLoads() : [];

        ksort($relations);

        $components = [];

        foreach ($relations as $name => $constraint) {
            $components[$name] = self::fingerprintClosure($constraint);
        }

        return serialize($components);
    }

    /**
     * Normalise a single value into a comparable scalar form.
     *
     * Objects without a dedicated normalisation are serialized so that two
     * distinct instances (e.g. raw query expressions) never collapse to the
     * same representation; serialization throws for closures, which have no
     * stable representation at all.
     *
     * @param  mixed  $value
     * @return mixed
     *
     * @throws \Exception
     */
    private static function normaliseValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_object($value)) {
            return serialize($value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $entry): mixed => self::normaliseValue($entry), $value);
        }

        return $value;
    }

    /**
     * Fingerprint an eager-load constraint closure by its definition site and
     * captured variables.
     *
     * Unconstrained eager loads compile to an empty closure defined inside the
     * framework, so they share one stable identity; user-supplied constraints
     * are identified by where they are defined and what they capture, keeping
     * the fingerprint stable across requests on the same codebase.
     *
     * @param  \Closure  $constraint
     *
     * @phpstan-param \Closure(mixed...): mixed $constraint
     *
     * @return string
     *
     * @throws \Exception
     */
    private static function fingerprintClosure(\Closure $constraint): string
    {
        $reflection = new \ReflectionFunction($constraint);

        return serialize([
            $reflection->getFileName(),
            $reflection->getStartLine(),
            array_map(fn (mixed $value): mixed => self::normaliseCapture($value), $reflection->getStaticVariables()),
        ]);
    }

    /**
     * Normalise a value captured by an eager-load constraint closure.
     *
     * The framework combines constraints by wrapping them in a closure that
     * captures the underlying closures, so captured closures are expected here
     * and are fingerprinted recursively by their own definition site rather
     * than rejected.
     *
     * @param  mixed  $value
     * @return mixed
     *
     * @throws \Exception
     */
    private static function normaliseCapture(mixed $value): mixed
    {
        if ($value instanceof \Closure) {
            return self::fingerprintClosure($value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $entry): mixed => self::normaliseCapture($entry), $value);
        }

        return self::normaliseValue($value);
    }
}
