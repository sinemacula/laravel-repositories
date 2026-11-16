<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use SineMacula\Repositories\Exceptions\UnfingerprintableQueryException;

/**
 * Derives a stable fingerprint for a prepared query builder and the read verb
 * applied to it, so that reads which run different SQL map to different cache
 * keys.
 *
 * The fingerprint is taken from the builder Eloquent actually executes: global
 * scopes are applied, so a removed scope (e.g. withTrashed()) or a scope that
 * reads run-time state changes the key. Each eager load is compiled the way
 * Eloquent loads it - the relation is built, its constraint is run, and the
 * relation query is fingerprinted recursively with the related model's own
 * scopes - so a constraint is keyed by the SQL it produces rather than by its
 * identity. Scopes and constraints therefore run once more per read, and must
 * be deterministic: one that has side effects, or that inspects the parent key
 * constraints Eloquent adds once the parents are loaded, is unsupported.
 *
 * Anything that shapes the result outside SQL cannot be fingerprinted, and
 * fingerprinting fails: a MorphTo eager load, an after-query callback other
 * than the framework's own chaperone, a callable relation default, a relation
 * class other than the framework's own, an unknown relation, a constraint that
 * throws, eager loads nested deeper than the depth limit, and verb arguments
 * with no stable representation such as closures.
 *
 * The digest is produced with xxh128: a fast, non-cryptographic 128-bit hash.
 * Accidental collisions are negligible at this width, but it offers no
 * guarantee against a deliberate second-preimage.
 *
 * @phpstan-type SupportedRelation (
 *     \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model>
 *     | \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model>
 *     | \Illuminate\Database\Eloquent\Relations\HasOneOrMany<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>
 *     | \Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough<
 *         \Illuminate\Database\Eloquent\Model,
 *         \Illuminate\Database\Eloquent\Model,
 *         \Illuminate\Database\Eloquent\Model,
 *         mixed
 *     >
 * )
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class QueryFingerprint
{
    /** @var int The deepest eager-load nesting fingerprinted, which bounds a model that eager loads itself. */
    private const int MAX_EAGER_LOAD_DEPTH = 16;

    /** @var array<int, class-string<\Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, *>>> The relation classes whose eager load the description captures in full. */
    private const array SUPPORTED_RELATIONS = [
        BelongsTo::class, BelongsToMany::class, HasMany::class, HasManyThrough::class, HasOne::class,
        HasOneThrough::class, MorphMany::class, MorphOne::class, MorphToMany::class,
    ];

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
        if (!$query instanceof EloquentBuilder) {
            throw new UnfingerprintableQueryException('Only an Eloquent builder can be fingerprinted.');
        }

        try {
            return self::hash([self::describe($query, 0), $method, self::normalise($arguments)]);
        } catch (UnfingerprintableQueryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new UnfingerprintableQueryException('Unable to fingerprint the given query.', previous: $exception);
        }
    }

    /**
     * Describe the query Eloquent executes for the given builder, including
     * every eager load nested beneath it.
     *
     * The builder is cloned before its scopes apply, since applyScopes()
     * returns its receiver when there are none and compiling the SQL drains the
     * before-query callbacks.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  int  $depth
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, *>|null  $relation
     * @return string
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     * @throws \Throwable
     */
    private static function describe(EloquentBuilder $query, int $depth, ?Relation $relation = null): string
    {
        $scoped     = (clone $query)->applyScopes();
        $base       = $scoped->getQuery();
        $sql        = $base->toSql();
        $connection = $base->getConnection();

        if (self::hasCallbacks($scoped, $relation) || self::hasCallbacks($base, null)) {
            throw new UnfingerprintableQueryException('A query with after-query callbacks cannot be fingerprinted.');
        }

        return self::hash([
            $connection instanceof Connection ? $connection->getName() : '',
            $connection->getDatabaseName(),
            $scoped->getModel()::class,
            self::normalise($scoped->getModel()->getCasts()),
            $sql,
            self::normalise($connection->prepareBindings($base->getBindings())),
            self::describeEagerLoads($scoped, $depth),
        ]);
    }

    /**
     * Describe the top-level eager loads of a scoped builder, in name order.
     *
     * Each relation is built and constrained exactly as Eloquent eager loads
     * it, minus the parent key constraints, whose values come from rows the
     * parent query already keys. Nested loads travel on the relation query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $scoped
     * @param  int  $depth
     * @return string
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     * @throws \Throwable
     */
    private static function describeEagerLoads(EloquentBuilder $scoped, int $depth): string
    {
        $eagerLoads = array_filter($scoped->getEagerLoads(), static fn (int|string $name): bool => !str_contains((string) $name, '.'), ARRAY_FILTER_USE_KEY);

        if ($eagerLoads === []) {
            return '';
        }

        if ($depth >= self::MAX_EAGER_LOAD_DEPTH) {
            throw new UnfingerprintableQueryException('Eager loads nested this deep cannot be fingerprinted.');
        }

        ksort($eagerLoads);

        $components = [];

        foreach ($eagerLoads as $name => $constraint) {

            $relation = $scoped->getRelation((string) $name);

            self::assertSupported($relation);

            $constraint($relation);

            $components[$name] = [self::describeRelation($relation), self::describe($relation->getQuery(), $depth + 1, $relation)];
        }

        return serialize($components);
    }

    /**
     * Assert that a relation is one whose eager load is fully described by its
     * query and configuration.
     *
     * A MorphTo builds one query per parent type only once the parents load,
     * and any other class, a subclass included, may match its results in ways
     * the description cannot see.
     *
     * @param  mixed  $relation
     * @return void
     *
     * @phpstan-assert SupportedRelation $relation
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     */
    private static function assertSupported(mixed $relation): void
    {
        if (!is_object($relation) || !in_array($relation::class, self::SUPPORTED_RELATIONS, true)) {
            throw new UnfingerprintableQueryException('An eager load of this relation type cannot be fingerprinted.');
        }
    }

    /**
     * Describe the configuration of a relation that shapes its eager load but
     * never reaches the relation query's SQL: the default it returns for a
     * missing model, and the keys, morph type and pivot it constrains on and
     * hydrates once the parents load.
     *
     * @param  SupportedRelation  $relation
     * @return string
     *
     * @throws \SineMacula\Repositories\Exceptions\UnfingerprintableQueryException
     * @throws \Throwable
     */
    private static function describeRelation(Relation $relation): string
    {
        $default = self::readProperty($relation, 'withDefault');

        if (is_callable($default)) {
            throw new UnfingerprintableQueryException('A relation with a callable default cannot be fingerprinted.');
        }

        $keys = match (true) {
            $relation instanceof BelongsToMany       => self::describePivot($relation),
            $relation instanceof HasOneOrManyThrough => [
                $relation->getQualifiedFirstKeyName(),
                $relation->getQualifiedForeignKeyName(),
                $relation->getQualifiedLocalKeyName(),
                $relation->getSecondLocalKeyName(),
            ],
            $relation instanceof MorphOneOrMany => [
                $relation->getQualifiedMorphType(),
                $relation->getMorphClass(),
                $relation->getQualifiedForeignKeyName(),
                $relation->getLocalKeyName(),
                $relation->getInverseRelationship(),
            ],
            $relation instanceof HasOneOrMany => [
                $relation->getQualifiedForeignKeyName(),
                $relation->getLocalKeyName(),
                $relation->getInverseRelationship(),
            ],
            default => [$relation->getQualifiedForeignKeyName(), $relation->getQualifiedOwnerKeyName()],
        };

        return self::normalise([$default, $relation::class, $keys]);
    }

    /**
     * Describe the pivot configuration of a many-to-many relation.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model>  $relation
     * @return array<int, mixed>
     *
     * @throws \ReflectionException
     */
    private static function describePivot(BelongsToMany $relation): array
    {
        return [
            $relation->getQualifiedForeignPivotKeyName(),
            $relation->getQualifiedRelatedPivotKeyName(),
            $relation->getParentKeyName(),
            $relation->getRelatedKeyName(),
            $relation->getPivotColumns(),
            $relation->getPivotAccessor(),
            $relation->getPivotClass(),
            self::readProperty($relation, 'declaringInverseRelationship'),
            self::readProperty($relation, 'relatedInverseRelationship'),
            $relation instanceof MorphToMany ? [$relation->getMorphType(), $relation->getMorphClass(), $relation->getInverse()] : null,
        ];
    }

    /**
     * Determine whether a builder holds callbacks that run on its results,
     * other than the chaperone of the relation it queries.
     *
     * @param  object  $builder
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, *>|null  $relation
     * @return bool
     *
     * @throws \ReflectionException
     */
    private static function hasCallbacks(object $builder, ?Relation $relation): bool
    {
        $callbacks = array_filter((array) self::readProperty($builder, 'afterQueryCallbacks'), static fn (mixed $callback): bool => !self::isChaperone($callback, $relation));

        return $callbacks !== [];
    }

    /**
     * Determine whether a callback is the one the framework registers when a
     * relation is chaperoned: a closure bound to that relation and defined
     * where chaperone() is. It links each result back to its parent under the
     * inverse relation name the relation description keys.
     *
     * @param  mixed  $callback
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, *>|null  $relation
     * @return bool
     *
     * @throws \ReflectionException
     */
    private static function isChaperone(mixed $callback, ?Relation $relation): bool
    {
        if (!$callback instanceof \Closure || !$relation instanceof HasOneOrMany) {
            return false;
        }

        $closure   = new \ReflectionFunction($callback);
        $chaperone = new \ReflectionMethod($relation, 'chaperone');

        return $closure->getClosureThis() === $relation && $closure->getFileName() === $chaperone->getFileName();
    }

    /**
     * Read a property the framework keeps protected, or null when the object
     * does not declare it.
     *
     * @SuppressWarnings("php:S3011")
     *
     * @param  object  $object
     * @param  string  $property
     * @return mixed
     *
     * @throws \ReflectionException
     */
    private static function readProperty(object $object, string $property): mixed
    {
        return property_exists($object, $property)
            ? (new \ReflectionProperty($object, $property))->getValue($object)
            : null;
    }

    /**
     * Hash the given components into a fingerprint.
     *
     * @param  array<int, string|null>  $components
     * @return string
     */
    private static function hash(array $components): string
    {
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
            $value instanceof \BackedEnum => $value->value,
            is_object($value)             => serialize($value),
            is_array($value)              => array_map(fn (mixed $entry): mixed => self::normaliseValue($entry), $value),
            default                       => $value,
        };
    }
}
