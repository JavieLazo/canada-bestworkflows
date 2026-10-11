<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Place of Birth / Citizenship / emergency Address columns
 * (2026_09_30_0001) survive a repeated up() or down() — the cPanel cron
 * re-running a half-applied deploy — and a run killed between its ALTERs
 * resumes with only the columns still missing. A rollback drops all three
 * and a re-run brings them back as NULL: ROLLBACK LOSES what was entered
 * (the migration's docblock says so; this pins it).
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up().
 * The seeded employee has no child rows, because on sqlite a column drop may
 * rebuild the table and, inside RefreshDatabase's transaction, that DROP
 * TABLE cascades into children.
 */

/** @var list<string> */
const EDCMIG_COLUMNS = ['place_of_birth', 'citizenship', 'emergency_address'];

/** The migration under test — an anonymous class, so untyped here. */
function edcmigMigration(): mixed
{
    return require base_path('database/migrations/2026_09_30_0001_add_birth_place_citizenship_and_emergency_address_to_employee_profiles_table.php');
}

function edcmigEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'EDC-1',
        'first_name' => 'Rosa',
        'last_name' => 'Villanueva',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'monthly_rate' => 30000,
        'place_of_birth' => 'Iloilo City',
        'citizenship' => 'Filipino',
        'emergency_address' => '12 Luna St., Iloilo City',
    ])->save();

    return $employee;
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function edcmigDdlDuring(callable $callback): array
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

function edcmigHas(string $column): bool
{
    return Schema::hasColumn('employee_profiles', $column);
}

it('adds the three columns once, and a repeated run sends no DDL', function () {
    $migration = edcmigMigration();

    try {
        // migrate:fresh already ran it through the migrator.
        foreach (EDCMIG_COLUMNS as $column) {
            expect(edcmigHas($column))->toBeTrue();
        }

        expect(edcmigDdlDuring(static fn () => $migration->up()))->toBe([]);
    } finally {
        $migration->up();
    }
});

it('drops all three on rollback, tolerates a second one, and loses what was entered', function () {
    $migration = edcmigMigration();
    $employee = edcmigEmployee();

    try {
        $migration->down();
        foreach (EDCMIG_COLUMNS as $column) {
            expect(edcmigHas($column))->toBeFalse();
        }

        // A rollback killed after its drops and re-run finds nothing to drop.
        expect(edcmigDdlDuring(static fn () => $migration->down()))->toBe([]);

        $migration->up();

        // Back as NULL: ROLLBACK LOSES the entered values, and the rest of
        // the employee is untouched.
        $row = DB::table('employee_profiles')->where('id', $employee->id)->first();
        expect($row?->place_of_birth)->toBeNull()
            ->and($row?->citizenship)->toBeNull()
            ->and($row?->emergency_address)->toBeNull()
            ->and($row?->last_name)->toBe('Villanueva')
            ->and((float) $row?->monthly_rate)->toBe(30000.0);
    } finally {
        $migration->up();
    }
});

it('resumes a run killed between its ALTERs with only the columns still missing', function () {
    $migration = edcmigMigration();

    try {
        $migration->down();

        // Killed after the first ADD: only place_of_birth exists.
        Schema::table('employee_profiles', function (Blueprint $t): void {
            $t->string('place_of_birth', 120)->nullable();
        });

        $ddl = edcmigDdlDuring(static fn () => $migration->up());

        expect($ddl)->not->toBe([]);
        foreach ($ddl as $statement) {
            expect($statement)->not->toContain('place_of_birth');
        }
        foreach (EDCMIG_COLUMNS as $column) {
            expect(edcmigHas($column))->toBeTrue();
        }
    } finally {
        $migration->up();
    }
});
