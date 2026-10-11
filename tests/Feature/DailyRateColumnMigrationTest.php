<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Rate Per Day column (2026_09_30_0002) survives a repeated up() or
 * down() — the cPanel cron re-running a half-applied deploy — and a rollback
 * drops it and a re-run brings it back as NULL: ROLLBACK LOSES every Rate Per
 * Day entered (the migration's docblock says so; this pins it).
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up().
 * The seeded employee has no child rows, because on sqlite a column drop may
 * rebuild the table and, inside RefreshDatabase's transaction, that DROP
 * TABLE cascades into children.
 */

/** The migration under test — an anonymous class, so untyped here. */
function drmigMigration(): mixed
{
    return require base_path('database/migrations/2026_09_30_0002_add_daily_rate_to_employee_profiles_table.php');
}

function drmigEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'DRM-1',
        'first_name' => 'Rosa',
        'last_name' => 'Villanueva',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'monthly_rate' => 30000,
        'daily_rate' => 1250.5,
    ])->save();

    return $employee;
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function drmigDdlDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
    ));
}

it('adds the column once, NULL for an existing employee, and a repeated run sends no DDL', function () {
    $migration = drmigMigration();

    try {
        // migrate:fresh already ran it through the migrator.
        expect(Schema::hasColumn('employee_profiles', 'daily_rate'))->toBeTrue()
            ->and(drmigDdlDuring(static fn () => $migration->up()))->toBe([]);

        // No default and no backfill: an employee saved without one reads NULL.
        $employee = new EmployeeProfile;
        $employee->forceFill(['employee_code' => 'DRM-2', 'first_name' => 'Ana', 'last_name' => 'Reyes',
            'employment_status' => 'Regular', 'date_hired' => '2024-01-15', 'monthly_rate' => 20000])->save();
        expect(DB::table('employee_profiles')->where('id', $employee->id)->value('daily_rate'))->toBeNull()
            ->and($employee->fresh()?->daily_rate)->toBeNull();
    } finally {
        $migration->up();
    }
});

it('drops the column on rollback, tolerates a second one, and loses what was entered', function () {
    $migration = drmigMigration();
    $employee = drmigEmployee();

    try {
        expect($employee->fresh()?->daily_rate)->toBe(1250.5);

        $migration->down();
        expect(Schema::hasColumn('employee_profiles', 'daily_rate'))->toBeFalse();

        // A rollback killed after its drop and re-run finds nothing to drop.
        expect(drmigDdlDuring(static fn () => $migration->down()))->toBe([]);

        $migration->up();

        // Back as NULL: ROLLBACK LOSES the entered rate, and the rest of the
        // employee is untouched.
        $row = DB::table('employee_profiles')->where('id', $employee->id)->first();
        expect($row?->daily_rate)->toBeNull()
            ->and($row?->last_name)->toBe('Villanueva')
            ->and((float) $row?->monthly_rate)->toBe(30000.0);
    } finally {
        $migration->up();
    }
});
