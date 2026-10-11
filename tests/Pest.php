<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->afterEach(function (): void {
        // The evidence migration refuses destructive rollback. Only guarded synthetic
        // integration fixtures are removed before DatabaseMigrations tears them down.
        if (app()->environment('testing') && DB::connection()->getDatabaseName() === 'ems_testing'
            && Schema::hasTable('payroll_finalization_snapshots')) {
            DB::table('payroll_finalization_snapshots')->delete();
        }
    })
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/** @param class-string<Model> $model */
function seededId(string $model, string $key): string
{
    $table = (new $model)->getTable();
    $id = DB::table('seed_identities')->where('table_name', $table)->where('seed_key', $key)->value('row_id');
    if (! is_string($id) || ! $model::query()->whereKey($id)->exists()) {
        throw new RuntimeException("Missing seeded identity {$table}:{$key}.");
    }

    return $id;
}
