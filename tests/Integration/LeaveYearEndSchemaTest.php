<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The leave type year-end column on MariaDB (2026_11_02_0017): CARRY or
 * LAPSE and nothing else, the migration runs twice and rolls back; the
 * conversion preset seed (2026_11_02_0018) writes one draft while the
 * table is empty and runs twice.
 */
function lyeMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0017_add_year_end_to_leave_types_table.php');
}

function lyePresetMigration(): mixed
{
    return require base_path('database/migrations/2026_11_02_0018_seed_csc_conversion_table_preset.php');
}

it('pins the year-end values, runs twice and rolls back', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    $migration = lyeMigration();
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('leave_types', 'year_end'))->toBeTrue();
    $stamp = now('UTC')->format('Y-m-d H:i:s.v');
    DB::table('leave_types')->insert(['id' => (string) Str::ulid(), 'code' => 'LYE-1', 'name' => 'Year end one', 'year_end' => 'LAPSE', 'created_at' => $stamp, 'updated_at' => null]);
    expect(fn () => DB::table('leave_types')->insert(['id' => (string) Str::ulid(), 'code' => 'LYE-2', 'name' => 'Year end two', 'year_end' => 'FORFEIT', 'created_at' => $stamp, 'updated_at' => null]))->toThrow(QueryException::class);
    $migration->down();
    expect(Schema::hasColumn('leave_types', 'year_end'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('leave_types', 'year_end'))->toBeTrue();
});

it('seeds the conversion preset once while the table is empty', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    DB::table('leave_conversion_tables')->delete();
    $migration = lyePresetMigration();
    $migration->up();
    $migration->up();
    expect(DB::table('leave_conversion_tables')->count())->toBe(1)
        ->and(DB::table('leave_conversion_tables')->value('status'))->toBe('DRAFT');
    $migration->down();
    expect(DB::table('leave_conversion_tables')->count())->toBe(0);
});
