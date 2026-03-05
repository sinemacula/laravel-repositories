<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Testing;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * Lightweight criteria testing utility.
 *
 * Allows testing criterion query-building behavior without a database
 * connection, service container, or integration test framework. Uses an
 * in-memory SQLite connection via Laravel's Capsule Manager.
 *
 * See TESTING.md for usage patterns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class CriteriaTestHelper
{
    /** @var \Illuminate\Database\Capsule\Manager|null Shared capsule instance */
    private static ?Capsule $capsule = null;

    /**
     * Apply a criterion to a fresh query builder for the given table.
     *
     * Returns the resulting Builder so you can inspect the generated SQL,
     * bindings, and query structure.
     *
     * Example:
     *
     *     $builder = CriteriaTestHelper::apply(new ActiveUsersCriterion, 'users');
     *     $this->assertStringContainsString('active', $builder->toSql());
     *     $this->assertContains(true, $builder->getBindings());
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
     * Useful as a control comparison in tests.
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
            /** @var bool */
            public $timestamps = false;
        };

        $model->setTable($table);

        return $model;
    }
}
