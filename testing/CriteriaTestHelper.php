<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Testing;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * Applies criteria to query builders on an in-memory SQLite connection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CriteriaTestHelper
{
    // phpcs:disable Squiz.Commenting.VariableComment -- @managed-static documents intentional shared state
    /** @var \Illuminate\Database\Capsule\Manager|null Shared in-memory SQLite connection. @managed-static */
    private static ?Capsule $capsule = null;
    // phpcs:enable Squiz.Commenting.VariableComment

    /**
     * Apply a criterion to a fresh query builder for the given table.
     *
     * @param  \SineMacula\Repositories\Contracts\CriteriaInterface<\Illuminate\Database\Eloquent\Model>  $criterion
     * @param  string  $table
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function apply(CriteriaInterface $criterion, string $table = 'test'): Builder
    {
        self::boot();

        $model   = self::model($table);
        $builder = $model->newQuery();
        $result  = $criterion->apply($builder);

        assert($result instanceof Builder);

        return $result;
    }

    /**
     * Create a fresh query builder for the given table without applying any
     * criterion.
     *
     * @param  string  $table
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function builder(string $table = 'test'): Builder
    {
        self::boot();

        return self::model($table)->newQuery();
    }

    /**
     * Initialize the in-memory SQLite connection if not already active.
     *
     * @return void
     */
    private static function boot(): void
    {
        if (self::$capsule !== null) {
            return;
        }

        self::$capsule = new Capsule;
        self::$capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        self::$capsule->bootEloquent();
    }

    /**
     * Create an anonymous model instance bound to the given table.
     *
     * @param  string  $table
     * @return \Illuminate\Database\Eloquent\Model
     */
    private static function model(string $table): Model
    {
        $model = new class extends Model {
            /** @var array<string> */
            protected $guarded = [];
        };

        $model->setTable($table);

        return $model;
    }
}
