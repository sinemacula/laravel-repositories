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

    /** @var array<string, bool> Whether each class named so far can be built without arguments. */
    private array $instantiable = [];

    /** @var array<string, \Closure(\Illuminate\Database\Eloquent\Model, array<array-key, mixed>, string): mixed>|null The state setters, built on first use. */
    private ?array $restorers = null;

    /** @var int How deep the node being decoded currently nests. */
    private int $depth = 0;

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
            return ($payload['miss'] ?? null) === true ? new CacheMiss : (new self)->node(CachePayloadField::field($payload, 'value'));
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
            return CachePayloadField::scalar($node);
        }

        $this->descend();

        $value = match ($node['type'] ?? null) {
            'array'      => $this->items($node),
            'collection' => $this->collection($node),
            'model'      => $this->model($node),
            'ref'        => $this->reference($node),
            'enum'       => self::enum($node),
            'date'       => self::date($node),
            default      => throw new \UnexpectedValueException('A stored value has no known type'),
        };

        $this->depth--;

        return $value;
    }

    /**
     * Enter a node one level deeper, refusing to go deeper than any payload is
     * written.
     *
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    private function descend(): void
    {
        if ($this->depth >= CachePayloadEncoder::MAX_DEPTH) {
            throw new \UnexpectedValueException('A stored value nests deeper than any payload is written');
        }

        $this->depth++;
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

        foreach (CachePayloadField::list($node, $key) as $index => $value) {
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
        $class      = $this->instantiableClassOf($node, Collection::class);
        $collection = new $class($this->items($node));

        // A collection the framework builds for models turns on relationship
        // autoloading when it is enabled, so a rebuilt one must too. Releases
        // before the setting existed never autoload.
        if ($collection instanceof EloquentCollection && self::autoloadsRelationships()) {
            $collection->withRelationshipAutoloading();
        }

        return $collection;
    }

    /**
     * Determine whether the application autoloads the relationships of the
     * models a collection holds.
     *
     * @return bool
     */
    private static function autoloadsRelationships(): bool
    {
        // @phpstan-ignore function.alreadyNarrowedType
        return method_exists(Model::class, 'isAutomaticallyEagerLoadingRelationships') && Model::isAutomaticallyEagerLoadingRelationships();
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
        $class = $this->instantiableClassOf($node, Model::class);
        $id    = CachePayloadField::field($node, 'id');

        if (!is_int($id) || isset($this->models[$id])) {
            throw new \UnexpectedValueException('A stored model has no unique id');
        }

        $model = $this->models[$id] = new $class;

        $model->exists = CachePayloadField::boolean($node, 'exists');
        $model->setConnection(CachePayloadField::nullableString($node, 'connection'));

        $attributes = CachePayloadField::attributes($node, 'attributes');
        $original   = CachePayloadField::field($node, 'original') === null ? $attributes : CachePayloadField::attributes($node, 'original');

        $model->setRawAttributes($original, true);
        $model->setRawAttributes($attributes);

        $this->restoreState($model, CachePayloadField::list($node, 'state'));

        $model->setRelations($this->items($node, 'relations'));

        if (array_key_exists('pivot', $node) || $model instanceof Pivot) {
            $this->restorePivot($model, CachePayloadField::list($node, 'pivot'));
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
        $id = CachePayloadField::field($node, 'id');

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
        $parent  = $this->node(CachePayloadField::field($node, 'parent'));
        $related = $this->node(CachePayloadField::field($node, 'related'));

        if (!$pivot instanceof Pivot || !$parent instanceof Model || !($related === null || $related instanceof Model)) {
            throw new \UnexpectedValueException('A stored pivot does not belong to a relationship');
        }

        $pivot->pivotParent = $parent;
        $pivot->setPivotKeys(CachePayloadField::nullableString($node, 'foreignKey'), CachePayloadField::nullableString($node, 'relatedKey'));
        $pivot->setRelatedModel($related);

        $morphType = CachePayloadField::nullableString($node, 'morphType');

        if (!$pivot instanceof MorphPivot || $morphType === null) {
            return;
        }

        $pivot->setMorphType($morphType);
        $pivot->setMorphClass(CachePayloadField::nullableString($node, 'morphClass'));
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
    private function restoreState(Model $model, array $state): void
    {
        $this->restorers ??= self::restorers();

        foreach (array_keys($state) as $key) {
            $restore = $this->restorers[$key] ?? throw new \UnexpectedValueException('A stored model has unknown state');

            $restore($model, $state, (string) $key);
        }
    }

    /**
     * Get the setter for each piece of state a model can carry, each checking
     * the stored value has the type the setter takes.
     *
     * @return array<string, \Closure(\Illuminate\Database\Eloquent\Model, array<array-key, mixed>, string): mixed>
     */
    private static function restorers(): array
    {
        return [
            'table'               => static fn (Model $model, array $state, string $key): mixed => $model->setTable(CachePayloadField::string($state, $key)),
            'hidden'              => static fn (Model $model, array $state, string $key): mixed => $model->setHidden(CachePayloadField::strings($state, $key)),
            'visible'             => static fn (Model $model, array $state, string $key): mixed => $model->setVisible(CachePayloadField::strings($state, $key)),
            'appends'             => static fn (Model $model, array $state, string $key): mixed => $model->setAppends(CachePayloadField::strings($state, $key)),
            'fillable'            => static fn (Model $model, array $state, string $key): mixed => $model->fillable(CachePayloadField::strings($state, $key)),
            'guarded'             => static fn (Model $model, array $state, string $key): mixed => $model->guard(CachePayloadField::strings($state, $key)),
            'casts'               => static fn (Model $model, array $state, string $key): mixed => $model->mergeCasts(CachePayloadField::strings($state, $key)),
            'dateFormat'          => static fn (Model $model, array $state, string $key): mixed => $model->setDateFormat(CachePayloadField::string($state, $key)),
            'keyName'             => static fn (Model $model, array $state, string $key): mixed => $model->setKeyName(CachePayloadField::string($state, $key)),
            'keyType'             => static fn (Model $model, array $state, string $key): mixed => $model->setKeyType(CachePayloadField::string($state, $key)),
            'incrementing'        => static fn (Model $model, array $state, string $key): mixed => $model->setIncrementing(CachePayloadField::boolean($state, $key)),
            'perPage'             => static fn (Model $model, array $state, string $key): mixed => $model->setPerPage(CachePayloadField::integer($state, $key)),
            'touches'             => static fn (Model $model, array $state, string $key): mixed => $model->setTouchedRelations(CachePayloadField::strings($state, $key)),
            'timestamps'          => static fn (Model $model, array $state, string $key): mixed => $model->timestamps = CachePayloadField::boolean($state, $key),
            'preventsLazyLoading' => static fn (Model $model, array $state, string $key): mixed => $model->preventsLazyLoading = CachePayloadField::boolean($state, $key),
        ];
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
        $class = CachePayloadField::classOf($node, \UnitEnum::class);
        $case  = $class . '::' . CachePayloadField::string($node, 'case');

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
        $class = CachePayloadField::field($node, 'class');

        if (!is_string($class) || !(is_a($class, \DateTime::class, true) || is_a($class, \DateTimeImmutable::class, true))) {
            throw new \UnexpectedValueException('A stored date has no date class');
        }

        try {
            $date = $class::createFromFormat('U u', CachePayloadField::string($node, 'time'));
            $zone = new \DateTimeZone(CachePayloadField::string($node, 'timezone'));
        } catch (\Exception $exception) {
            throw new \UnexpectedValueException('A stored date cannot be read', previous: $exception);
        }

        if (!$date instanceof \DateTimeInterface) {
            throw new \UnexpectedValueException('A stored date cannot be read');
        }

        return $date->setTimezone($zone);
    }

    /**
     * Get a class name a node holds, checking it is the given type and can be
     * built without arguments.
     *
     * @template TClass of object
     *
     * @param  array<array-key, mixed>  $node
     * @param  class-string<TClass>  $type
     * @return class-string<TClass>
     *
     * @throws \UnexpectedValueException
     */
    private function instantiableClassOf(array $node, string $type): string
    {
        $class = CachePayloadField::classOf($node, $type);

        if (!($this->instantiable[$class] ??= self::isInstantiable($class))) {
            throw new \UnexpectedValueException('A stored class cannot be built without arguments');
        }

        return $class;
    }

    /**
     * Determine whether a class can be built without arguments.
     *
     * @param  class-string  $class
     * @return bool
     */
    private static function isInstantiable(string $class): bool
    {
        $reflection = new \ReflectionClass($class);

        return $reflection->isInstantiable() && (int) $reflection->getConstructor()?->getNumberOfRequiredParameters() === 0;
    }
}
