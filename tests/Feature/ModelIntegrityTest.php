<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

/**
 * Every model must be constructible, and its Eloquent configuration readable.
 *
 * This exists because of a defect that reached production: the IsReferenceList
 * trait declared `protected $fillable`, colliding with the `$fillable` that
 * Eloquent's own GuardsAttributes trait already gives every Model. Two traits
 * declaring the same property with different initial values is a
 * property-composition conflict.
 *
 * PHP 8.4 rejects it with a FATAL at class-composition time; PHP 8.5 accepts
 * it. Development ran 8.5 and production runs 8.4, so the whole suite was green
 * locally while the seeder died on the host with:
 *
 *   Model and IsReferenceList define the same property ($fillable) in the
 *   composition of Position. However, the definition differs and is considered
 *   incompatible.
 *
 * A fatal at composition time cannot be caught by a test that never constructs
 * the class — hence this test constructs ALL of them. It only fails on a PHP
 * version that enforces the rule, which is why the suite must also be run
 * against the deployment target: see ./test-on-target-php.sh
 */
it('constructs every Eloquent model and reads its configuration', function () {
    $dir = __DIR__.'/../../app/Models';
    $failures = [];
    $checked = 0;

    foreach (scandir($dir) as $file) {
        if (! str_ends_with($file, '.php')) {
            continue;
        }

        $class = 'App\\Models\\'.substr($file, 0, -4);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        try {
            /** @var Model $model */
            $model = new $class;

            // Touch the configuration a trait could corrupt.
            $model->getFillable();
            $model->getCasts();
            $model->getTable();
            $model->getKeyName();

            $checked++;
        } catch (Throwable $e) {
            $failures[] = $class.': '.$e->getMessage();
        }
    }

    expect($failures)->toBe([]);
    expect($checked)->toBeGreaterThan(20, 'Model discovery found suspiciously few models.');
});

it('keeps actor columns out of every mass-assignable list', function () {
    // Actor columns come from the session, never a request body. A trait or a
    // careless edit reintroducing one into $fillable is a privilege bug.
    $actorColumns = [
        'actor_id', 'actor_name', 'uploaded_by', 'reviewed_by',
        'assigned_by', 'finalized_by', 'author_id', 'author_name',
    ];

    $dir = __DIR__.'/../../app/Models';
    $offenders = [];

    foreach (scandir($dir) as $file) {
        if (! str_ends_with($file, '.php')) {
            continue;
        }

        $class = 'App\\Models\\'.substr($file, 0, -4);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $fillable = (new $class)->getFillable();

        foreach ($actorColumns as $column) {
            if (in_array($column, $fillable, true)) {
                $offenders[] = $class.' → '.$column;
            }
        }
    }

    expect($offenders)->toBe([]);
});
