<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

/**
 * Converts a repository read result into the arrays and scalars the cache
 * stores, so an entry reads back under a store that unserializes no class.
 *
 * A model keeps its class, connection, raw attributes, eager-loaded relations
 * and any instance state, such as visibility, appends, casts or its table, that
 * differs from a fresh instance; a pivot also keeps its parent, related model
 * and keys. A model reached twice, as a chaperoned relation reaches its parent,
 * is written once and referenced after, so the rebuilt graph shares the same
 * instances. Collections keep their class and keys, enums their case and dates
 * their instant and timezone. A result holding any other object, or a
 * collection whose class cannot be built without arguments, cannot be stored.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class CachePayloadEncoder
{
    /** @var int The payload format version, so a reader can tell an entry from another format. */
    public const int FORMAT = 1;

    /** @var int The deepest a value may nest, so a reader can refuse a payload that recurses without end. */
    public const int MAX_DEPTH = 64;

    /** @var int How deep the value being encoded currently nests. */
    private int $depth = 0;

    /** @var array<class-string<\Illuminate\Support\Collection<array-key, mixed>>, bool> Whether a reader can rebuild each collection class seen so far. */
    private array $buildable = [];

    /** @var array<int, int> The payload id of every model written so far, keyed by object id. */
    private array $ids = [];

    /** @var array<class-string<\Illuminate\Database\Eloquent\Model>, \Illuminate\Database\Eloquent\Model> A fresh instance of each model class, to compare state against. */
    private array $prototypes = [];

    /**
     * Encode a read result for the cache.
     *
     * Returns null when the result holds a value that cannot be stored as
     * arrays and scalars.
     *
     * @param  mixed  $value
     * @return array{format: int, value: mixed}|null
     */
    public static function encode(mixed $value): ?array
    {
        try {
            return ['format' => self::FORMAT, 'value' => (new self)->node($value)];
        } catch (\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * Get the payload recording that a read found nothing.
     *
     * @return array{format: int, miss: true}
     */
    public static function miss(): array
    {
        return ['format' => self::FORMAT, 'miss' => true];
    }

    /**
     * Encode a single value.
     *
     * @param  mixed  $value
     * @return mixed
     *
     * @throws \UnexpectedValueException
     */
    private function node(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($this->depth >= self::MAX_DEPTH) {
            throw new \UnexpectedValueException('The value nests too deeply to store');
        }

        $this->depth++;

        $node = match (true) {
            is_array($value)                     => ['type' => 'array', 'items' => $this->items($value)],
            $value instanceof Model              => $this->model($value),
            $value instanceof Collection         => $this->collection($value),
            $value instanceof \UnitEnum          => ['type' => 'enum', 'class' => $value::class, 'case' => $value->name],
            $value instanceof \DateTimeInterface => $this->date($value),
            default                              => throw new \UnexpectedValueException('The value cannot be stored without its class'),
        };

        $this->depth--;

        return $node;
    }

    /**
     * Encode a date by its instant and timezone, so a wall time that occurs
     * twice in the timezone still reads back as the same instant.
     *
     * @param  \DateTimeInterface  $date
     * @return array<string, string>
     */
    private function date(\DateTimeInterface $date): array
    {
        return [
            'type'     => 'date',
            'class'    => $date::class,
            'time'     => $date->format('U u'),
            'timezone' => $date->getTimezone()->getName(),
        ];
    }

    /**
     * Encode a collection by its class and items.
     *
     * A collection class a reader cannot build without arguments is refused,
     * since an entry naming it would be rejected on every read, then recomputed
     * and written again.
     *
     * @param  \Illuminate\Support\Collection<array-key, mixed>  $collection
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException
     */
    private function collection(Collection $collection): array
    {
        $class = $collection::class;

        if (!($this->buildable[$class] ??= CachePayloadDecoder::isInstantiable($class))) {
            throw new \UnexpectedValueException('The collection cannot be rebuilt without arguments');
        }

        return ['type' => 'collection', 'class' => $class, 'items' => $this->items($collection->all())];
    }

    /**
     * Encode every value of an array, keeping its keys.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     *
     * @throws \UnexpectedValueException
     */
    private function items(array $values): array
    {
        return array_map($this->node(...), $values);
    }

    /**
     * Encode a model, or a reference to it when it was already written.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException
     */
    private function model(Model $model): array
    {
        $object = spl_object_id($model);

        if (isset($this->ids[$object])) {
            return ['type' => 'ref', 'id' => $this->ids[$object]];
        }

        $this->ids[$object] = count($this->ids);

        $attributes = $model->getAttributes();
        $original   = $model->getRawOriginal();

        $node = [
            'type'       => 'model',
            'id'         => $this->ids[$object],
            'class'      => $model::class,
            'connection' => $model->getConnectionName(),
            'exists'     => $model->exists,
            'attributes' => $this->attributes($attributes),
            'original'   => $original === $attributes ? null : $this->attributes($original),
            'state'      => $this->state($model),
            'relations'  => $this->items($model->getRelations()),
        ];

        if ($model instanceof Pivot) {
            $node['pivot'] = $this->pivot($model);
        }

        return $node;
    }

    /**
     * Check that a model's raw attributes hold only scalars.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, scalar|null>
     *
     * @throws \UnexpectedValueException
     */
    private function attributes(array $attributes): array
    {
        foreach ($attributes as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new \UnexpectedValueException('A raw attribute holds a value other than a scalar');
            }
        }

        /** @var array<string, scalar|null> */
        return $attributes;
    }

    /**
     * Get the model state that differs from a fresh instance of its class.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, mixed>
     */
    private function state(Model $model): array
    {
        $prototype = $this->prototypes[$model::class] ??= new ($model::class);

        // The date format falls back to the connection's, so the fresh instance
        // must share the model's connection to compare equal.
        $prototype->setConnection($model->getConnectionName());

        $fresh = self::stateOf($prototype);
        $state = array_filter(self::stateOf($model), static fn (mixed $value, string $key): bool => $value !== $fresh[$key], ARRAY_FILTER_USE_BOTH);
        $casts = array_diff_assoc($model->getCasts(), $prototype->getCasts());

        return $casts === [] ? $state : $state + ['casts' => $casts];
    }

    /**
     * Get the instance state a model can carry beyond its raw attributes and
     * relations.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, mixed>
     */
    private static function stateOf(Model $model): array
    {
        return [
            'table'               => $model->getTable(),
            'hidden'              => $model->getHidden(),
            'visible'             => $model->getVisible(),
            'appends'             => $model->getAppends(),
            'fillable'            => $model->getFillable(),
            'guarded'             => $model->getGuarded(),
            'dateFormat'          => $model->getDateFormat(),
            'keyName'             => $model->getKeyName(),
            'keyType'             => $model->getKeyType(),
            'incrementing'        => $model->getIncrementing(),
            'perPage'             => $model->getPerPage(),
            'touches'             => $model->getTouchedRelations(),
            'timestamps'          => $model->timestamps,
            'preventsLazyLoading' => $model->preventsLazyLoading,
        ];
    }

    /**
     * Encode the relationship a pivot belongs to.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Pivot  $pivot
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException
     */
    private function pivot(Pivot $pivot): array
    {
        $morphType = $pivot instanceof MorphPivot ? $pivot->getMorphType() : null;

        return [
            'parent'     => $this->node($pivot->pivotParent),
            'related'    => $this->node($pivot->pivotRelated),
            'foreignKey' => $pivot->getForeignKey(),
            'relatedKey' => $pivot->getRelatedKey(),
            'morphType'  => $morphType,
            'morphClass' => $morphType === null ? null : ($pivot->getAttributes()[$morphType] ?? null),
        ];
    }
}
