<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

/**
 * Rebuilds a repository read result from the arrays and scalars the cache
 * stores.
 *
 * The reverse of the encoder: models are rebuilt without firing any model
 * event, with their raw attributes, relations and state restored, and every
 * reference resolves to the instance it names. A payload that does not read
 * back whole, such as an entry an earlier version stored as objects, is
 * rejected rather than read in part.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class CachePayloadDecoder
{
    /** @var array<int, \Illuminate\Database\Eloquent\Model> The models rebuilt so far, keyed by payload id. */
    private array $models = [];

    /**
     * Decode a stored payload.
     *
     * Returns the miss marker for a payload recording that a read found
     * nothing, and null for a payload that cannot be read back.
     *
     * @param  mixed  $payload
     * @return mixed
     */
    public static function decode(mixed $payload): mixed
    {
        if (!is_array($payload) || ($payload['format'] ?? null) !== CachePayloadEncoder::FORMAT) {
            return null;
        }

        try {
            return ($payload['miss'] ?? null) === true ? new CacheMiss : (new self)->node(self::field($payload, 'value'));
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Decode a single value.
     *
     * @param  mixed  $node
     * @return mixed
     *
     * @throws \UnexpectedValueException
     */
    private function node(mixed $node): mixed
    {
        if (!is_array($node)) {
            return self::scalar($node);
        }

        return match ($node['type'] ?? null) {
            'array'      => $this->items($node),
            'collection' => $this->collection($node),
            'model'      => $this->model($node),
            'ref'        => $this->reference($node),
            'enum'       => self::enum($node),
            'date'       => self::date($node),
            default      => throw new \UnexpectedValueException('A stored value has no known type'),
        };
    }

    /**
     * Check that a value outside an array node is a scalar or null.
     *
     * @param  mixed  $value
     * @return scalar|null
     *
     * @throws \UnexpectedValueException
     */
    private static function scalar(mixed $value): bool|float|int|string|null
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \UnexpectedValueException('A stored value is neither a scalar nor an array');
        }

        return $value;
    }

    /**
     * Decode every value a node lists, keeping its keys.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $key
     * @return array<array-key, mixed>
     *
     * @throws \UnexpectedValueException
     */
    private function items(array $node, string $key = 'items'): array
    {
        $items = [];

        foreach (self::list($node, $key) as $index => $value) {
            $items[$index] = $this->node($value);
        }

        return $items;
    }

    /**
     * Rebuild a collection.
     *
     * @param  array<array-key, mixed>  $node
     * @return \Illuminate\Support\Collection<array-key, mixed>
     *
     * @throws \UnexpectedValueException
     */
    private function collection(array $node): Collection
    {
        $class      = self::classOf($node, Collection::class);
        $collection = new $class($this->items($node));

        // A collection the framework builds for models turns on relationship
        // autoloading when it is enabled, so a rebuilt one must too.
        if ($collection instanceof EloquentCollection && Model::isAutomaticallyEagerLoadingRelationships()) {
            $collection->withRelationshipAutoloading();
        }

        return $collection;
    }

    /**
     * Rebuild a model.
     *
     * The model is recorded under its id before its relations are rebuilt, so a
     * relation that refers back to it resolves to this instance.
     *
     * @param  array<array-key, mixed>  $node
     * @return \Illuminate\Database\Eloquent\Model
     *
     * @throws \UnexpectedValueException
     */
    private function model(array $node): Model
    {
        $class = self::classOf($node, Model::class);
        $id    = self::field($node, 'id');

        if (!is_int($id) || isset($this->models[$id])) {
            throw new \UnexpectedValueException('A stored model has no unique id');
        }

        $model = $this->models[$id] = new $class;

        $model->exists = self::boolean($node, 'exists');
        $model->setConnection(self::nullableString($node, 'connection'));

        $attributes = self::attributes($node, 'attributes');
        $original   = self::field($node, 'original') === null ? $attributes : self::attributes($node, 'original');

        $model->setRawAttributes($original, true);
        $model->setRawAttributes($attributes);

        self::restoreState($model, self::list($node, 'state'));

        $model->setRelations($this->items($node, 'relations'));

        if (array_key_exists('pivot', $node) || $model instanceof Pivot) {
            $this->restorePivot($model, self::list($node, 'pivot'));
        }

        return $model;
    }

    /**
     * Resolve a reference to a model rebuilt earlier.
     *
     * @param  array<array-key, mixed>  $node
     * @return \Illuminate\Database\Eloquent\Model
     *
     * @throws \UnexpectedValueException
     */
    private function reference(array $node): Model
    {
        $id = self::field($node, 'id');

        if (!is_int($id) || !isset($this->models[$id])) {
            throw new \UnexpectedValueException('A stored reference names no model');
        }

        return $this->models[$id];
    }

    /**
     * Restore the relationship a pivot belongs to.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $pivot
     * @param  array<array-key, mixed>  $node
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private function restorePivot(Model $pivot, array $node): void
    {
        $parent  = $this->node(self::field($node, 'parent'));
        $related = $this->node(self::field($node, 'related'));

        if (!$pivot instanceof Pivot || !$parent instanceof Model || !($related === null || $related instanceof Model)) {
            throw new \UnexpectedValueException('A stored pivot does not belong to a relationship');
        }

        $pivot->pivotParent = $parent;
        $pivot->setPivotKeys(self::nullableString($node, 'foreignKey'), self::nullableString($node, 'relatedKey'));
        $pivot->setRelatedModel($related);

        $morphType = self::nullableString($node, 'morphType');

        if (!$pivot instanceof MorphPivot || $morphType === null) {
            return;
        }

        $pivot->setMorphType($morphType);
        $pivot->setMorphClass(self::nullableString($node, 'morphClass'));
    }

    /**
     * Restore the model state that differs from a fresh instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<array-key, mixed>  $state
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private static function restoreState(Model $model, array $state): void
    {
        foreach (array_keys($state) as $key) {
            match ($key) {
                'table'               => $model->setTable(self::string($state, $key)),
                'hidden'              => $model->setHidden(self::strings($state, $key)),
                'visible'             => $model->setVisible(self::strings($state, $key)),
                'appends'             => $model->setAppends(self::strings($state, $key)),
                'casts'               => $model->mergeCasts(self::strings($state, $key)),
                'timestamps'          => $model->timestamps          = self::boolean($state, $key),
                'preventsLazyLoading' => $model->preventsLazyLoading = self::boolean($state, $key),
                default               => throw new \UnexpectedValueException('A stored model has unknown state'),
            };
        }
    }

    /**
     * Rebuild an enum case.
     *
     * @param  array<array-key, mixed>  $node
     * @return \UnitEnum
     *
     * @throws \UnexpectedValueException
     */
    private static function enum(array $node): \UnitEnum
    {
        $class = self::classOf($node, \UnitEnum::class);
        $case  = $class . '::' . self::string($node, 'case');

        $value = defined($case) ? constant($case) : null;

        if (!$value instanceof \UnitEnum) {
            throw new \UnexpectedValueException('A stored enum case no longer exists');
        }

        return $value;
    }

    /**
     * Rebuild a date.
     *
     * @param  array<array-key, mixed>  $node
     * @return \DateTimeInterface
     *
     * @throws \UnexpectedValueException
     */
    private static function date(array $node): \DateTimeInterface
    {
        $class = self::field($node, 'class');

        if (!is_string($class) || !(is_a($class, \DateTime::class, true) || is_a($class, \DateTimeImmutable::class, true))) {
            throw new \UnexpectedValueException('A stored date has no date class');
        }

        try {
            return new $class(self::string($node, 'time'), new \DateTimeZone(self::string($node, 'timezone')));
        } catch (\Exception $exception) {
            throw new \UnexpectedValueException('A stored date cannot be read', previous: $exception);
        }
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
    private static function classOf(array $node, string $type): string
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
    private static function attributes(array $node, string $key): array
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
    private static function strings(array $node, string $key): array
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
    private static function list(array $node, string $key): array
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
    private static function string(array $node, string $key): string
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
    private static function nullableString(array $node, string $key): ?string
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
    private static function boolean(array $node, string $key): bool // phpcs:ignore SineMacula.NamingConventions.BooleanMethodName.NotPredicate
    {
        $value = self::field($node, $key);

        if (!is_bool($value)) {
            throw new \UnexpectedValueException('A stored value is not a boolean');
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
    private static function field(array $node, string $key): mixed
    {
        if (!array_key_exists($key, $node)) {
            throw new \UnexpectedValueException('A stored value is missing a field');
        }

        return $node[$key];
    }
}
