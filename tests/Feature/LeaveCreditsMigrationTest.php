<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leave-credit drop (2026_09_25_0002) rolls back and re-applies cleanly
 * and survives a repeated up() or down() — the cPanel cron re-running a
 * half-applied deploy. A rollback brings both columns back as NOT NULL
 * DEFAULT 0, every employee reads 0 in them, and a re-run drops them again
 * with the employee row otherwise untouched: ROLLBACK LOSES the balances (the
 * migration's docblock says so; this pins it).
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up()
 * before anything else. The seeded employee has no child rows, because on
 * sqlite a column drop may rebuild the table and, inside RefreshDatabase's
 * transaction, that DROP TABLE cascades into children.
 */

/** The migration under test — an anonymous class, so untyped here. */
function lcmigMigration(): mixed
{
    return require base_path('database/migrations/2026_09_25_0002_drop_leave_credits_from_employee_profiles_table.php');
}

function lcmigEmployee(string $code): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => 'Credit',
        'last_name' => 'Holder '.$code,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-15',
        'monthly_rate' => 30000,
    ])->save();

    return $employee;
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function lcmigDdlDuring(callable $callback): array
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

function lcmigCredit(string $employeeId, string $column): float
{
    return (float) DB::table('employee_profiles')->where('id', $employeeId)->value($column);
}

it('drops both columns, tolerates a repeated run and restores them as zero on rollback', function () {
    $migration = lcmigMigration();

    try {
        // migrate:fresh already ran it: a migrated schema has neither column.
        expect(Schema::hasColumn('employee_profiles', 'vacation_credits'))->toBeFalse()
            ->and(Schema::hasColumn('employee_profiles', 'sick_credits'))->toBeFalse();

        $employee = lcmigEmployee('LCMIG-1');

        $migration->down();
        expect(Schema::hasColumn('employee_profiles', 'vacation_credits'))->toBeTrue()
            ->and(Schema::hasColumn('employee_profiles', 'sick_credits'))->toBeTrue();

        // A repeated rollback finds both columns and sends no DDL.
        expect(lcmigDdlDuring(static fn () => $migration->down()))->toBe([]);

        // Back with DEFAULT 0 for the row that predates the rollback, and
        // still writable as the old fractional day counts.
        expect(lcmigCredit($employee->id, 'vacation_credits'))->toBe(0.0);
        DB::table('employee_profiles')->where('id', $employee->id)->update(['vacation_credits' => 15, 'sick_credits' => 7.5]);
        expect(lcmigCredit($employee->id, 'sick_credits'))->toBe(7.5);

        $migration->up();
        // The retry: nothing left to drop, no DDL.
        expect(lcmigDdlDuring(static fn () => $migration->up()))->toBe([]);

        expect(Schema::hasColumn('employee_profiles', 'vacation_credits'))->toBeFalse()
            ->and(Schema::hasColumn('employee_profiles', 'sick_credits'))->toBeFalse();

        // The employee is untouched by the drop and the re-add.
        $fresh = EmployeeProfile::query()->findOrFail($employee->id);
        expect($fresh->last_name)->toBe('Holder LCMIG-1')
            ->and($fresh->monthly_rate)->toBe(30000.0)
            ->and($fresh->date_hired->format('Y-m-d'))->toBe('2024-01-15');

        // ROLLBACK LOSES the balances: once dropped, a second rollback brings
        // the columns back at 0, not at 15 and 7.5.
        $migration->down();
        expect(lcmigCredit($employee->id, 'vacation_credits'))->toBe(0.0)
            ->and(lcmigCredit($employee->id, 'sick_credits'))->toBe(0.0);
    } finally {
        $migration->up();
    }
});

it('resumes a run killed between its two ALTERs', function () {
    $migration = lcmigMigration();

    try {
        $migration->down();

        // Killed after the first drop: only the second column is left, and
        // the re-run sends DDL for that one alone.
        Schema::table('employee_profiles', function (Blueprint $t): void {
            $t->dropColumn('vacation_credits');
        });
        expect(Schema::hasColumn('employee_profiles', 'vacation_credits'))->toBeFalse()
            ->and(Schema::hasColumn('employee_profiles', 'sick_credits'))->toBeTrue();

        $ddl = lcmigDdlDuring(static fn () => $migration->up());

        expect($ddl)->not->toBe([]);
        foreach ($ddl as $statement) {
            expect($statement)->not->toContain('vacation_credits');
        }
        expect(Schema::hasColumn('employee_profiles', 'vacation_credits'))->toBeFalse()
            ->and(Schema::hasColumn('employee_profiles', 'sick_credits'))->toBeFalse();
    } finally {
        $migration->up();
    }
});
