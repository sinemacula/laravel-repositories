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
 * and any visibility, append, cast or table state that differs from a fresh
 * instance; a pivot also keeps its parent, related model and keys. A model
 * reached twice, as a chaperoned relation reaches its parent, is written once
 * and referenced after, so the rebuilt graph shares the same instances.
 * Collections keep their class and keys, enums their case and dates their time
 * and timezone. A result holding any other object cannot be stored.
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
        return match (true) {
            $value === null || is_scalar($value) => $value,
            is_array($value)                     => ['type' => 'array', 'items' => $this->items($value)],
            $value instanceof Model              => $this->model($value),
            $value instanceof Collection         => ['type' => 'collection', 'class' => $value::class, 'items' => $this->items($value->all())],
            $value instanceof \UnitEnum          => ['type' => 'enum', 'class' => $value::class, 'case' => $value->name],
            $value instanceof \DateTimeInterface => $this->date($value),
            default                              => throw new \UnexpectedValueException('The value cannot be stored without its class'),
        };
    }

    /**
     * Encode a date by its time and timezone.
     *
     * @param  \DateTimeInterface  $date
     * @return array<string, string>
     */
    private function date(\DateTimeInterface $date): array
    {
        return [
            'type'     => 'date',
            'class'    => $date::class,
            'time'     => $date->format('Y-m-d H:i:s.u'),
            'timezone' => $date->getTimezone()->getName(),
        ];
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

        $state = [
            'table'               => $model->getTable(),
            'hidden'              => $model->getHidden(),
            'visible'             => $model->getVisible(),
            'appends'             => $model->getAppends(),
            'timestamps'          => $model->timestamps,
            'preventsLazyLoading' => $model->preventsLazyLoading,
        ];

        $fresh = [
            'table'               => $prototype->getTable(),
            'hidden'              => $prototype->getHidden(),
            'visible'             => $prototype->getVisible(),
            'appends'             => $prototype->getAppends(),
            'timestamps'          => $prototype->timestamps,
            'preventsLazyLoading' => $prototype->preventsLazyLoading,
        ];

        $state = array_filter($state, static fn (mixed $value, string $key): bool => $value !== $fresh[$key], ARRAY_FILTER_USE_BOTH);
        $casts = array_diff_assoc($model->getCasts(), $prototype->getCasts());

        return $casts === [] ? $state : $state + ['casts' => $casts];
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
