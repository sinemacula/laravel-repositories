<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;

/**
 * Derives a stable fingerprint for a prepared query builder and the read verb
 * applied to it, so that distinct reads map to distinct cache keys.
 *
 * The fingerprint folds in the connection identity, the model class, the
 * compiled SQL, the normalised bindings, the read verb and its arguments, and
 * the eager loads - including each constraint closure's definition site,
 * captures, and bound instance state - since all of these are invisible to
 * the compiled base SQL yet still distinguish one read from another. Values
 * with no stable representation (e.g. closures passed as verb arguments)
 * cannot be fingerprinted and cause fingerprinting to fail.
 *
 * The digest is produced with xxh128: a fast, non-cryptographic 128-bit hash.
 * Accidental collisions are negligible at this width, but it offers no
 * guarantee against a deliberate second-preimage.
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
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    public static function for(Builder $query, string $method = '', array $arguments = []): string
    {
        try {

            $base       = $query->getQuery();
            $connection = $base->getConnection();

            $components = [
                $connection instanceof Connection ? $connection->getName() : '',
                $connection->getDatabaseName(),
                $query->getModel()::class,
                $base->toSql(),
                self::normalise($base->getBindings()),
                $method,
                self::normalise($arguments),
                self::normaliseEagerLoads($query),
            ];

            return hash('xxh128', implode('|', $components));

        } catch (\Exception $exception) {
            throw new UnfingerprintableQueryException('Unable to fingerprint the given query.', previous: $exception);
        }
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
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \BackedEnum        => $value->value,
            is_object($value)                    => serialize($value),
            is_array($value)                     => array_map(fn (mixed $entry): mixed => self::normaliseValue($entry), $value),
            default                              => $value,
        };
    }

    /**
     * Fingerprint an eager-load constraint closure by its definition site,
     * captured variables, and bound instance state.
     *
     * Unconstrained eager loads compile to an empty closure defined inside the
     * framework, so they share one stable identity; user-supplied constraints
     * are identified by where they are defined, what they capture, and the
     * state of a bound $this, keeping the fingerprint stable across requests
     * on the same codebase while still distinguishing constraints that differ
     * only by that instance state.
     *
     * The result is memoised per closure instance for the life of the
     * request; the fingerprint reflects capture state at first evaluation of
     * that closure instance.
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
        /** @var \WeakMap<\Closure, string> $fingerprints */
        static $fingerprints = new \WeakMap;

        if (isset($fingerprints[$constraint])) {
            return $fingerprints[$constraint];
        }

        $reflection = new \ReflectionFunction($constraint);
        $boundThis  = $reflection->getClosureThis();

        // The framework combines eager-load constraints into a closure bound
        // to the query builder itself (not application state); excluded here
        // both because it carries no identity of its own and because the
        // builder holds a live, unserializable database connection. A closure
        // bound to itself would otherwise also recurse forever below.
        $identity = $boundThis === null || $boundThis === $constraint || $boundThis instanceof Builder
            ? null
            : self::normaliseCapture($boundThis);

        $fingerprint = serialize([
            self::normaliseDefinitionPath($reflection->getFileName()),
            $reflection->getStartLine(),
            array_map(fn (mixed $value): mixed => self::normaliseCapture($value), $reflection->getStaticVariables()),
            $identity,
        ]);

        $fingerprints[$constraint] = $fingerprint;

        return $fingerprint;
    }

    /**
     * Normalise a closure's definition-site file path so that an otherwise
     * unchanged constraint fingerprints identically across releases.
     *
     * Atomic-release deployments unpack each release into a fresh, timestamped
     * directory, so the absolute path to an unchanged file differs between
     * deploys; stripping the application base path keeps the fingerprint
     * stable while still distinguishing files that differ beyond that prefix.
     *
     * @param  false|string  $path
     * @return string
     */
    private static function normaliseDefinitionPath(false|string $path): string
    {
        if ($path === false) {
            return '';
        }

        try {
            $base = rtrim(base_path(), \DIRECTORY_SEPARATOR);
        } catch (\Throwable) {
            // No bound Laravel application (or the helper is unavailable);
            // fingerprint the path unchanged.
            return $path;
        }

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), \DIRECTORY_SEPARATOR) : $path;
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
