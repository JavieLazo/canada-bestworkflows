<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\OvertimeApproval;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The overtime-decision table (2026_09_26_0006 overtime_approvals) rolls back
 * and re-applies cleanly, and survives a repeated up() or down() — the cPanel
 * cron re-running a half-applied deploy. The table comes back with its unique
 * (employee, day) index and its cascading employee foreign key. ROLLBACK LOSES
 * every overtime decision; the employee is untouched.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up()
 * before anything else, then deletes the rows seeded here by key. On
 * MySQL/MariaDB DDL commits implicitly, so a down() ends RefreshDatabase's
 * transaction (the RosterLocationsMigrationTest pattern). The CHECKs are
 * MySQL/MariaDB only and belong to tests/Integration/OvertimeApprovalsSchemaTest.
 */

/** The migration — an anonymous class, so untyped here. */
function otamigMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0006_create_overtime_approvals_table.php');
}

function otamigEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'OTM-'.random_int(10000, 99999), 'first_name' => 'Overtime', 'last_name' => 'Migration',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    return $employee;
}

function otamigRow(EmployeeProfile $employee, string $date): OvertimeApproval
{
    $row = new OvertimeApproval;
    $row->forceFill(['employee_id' => (string) $employee->getKey(), 'date' => $date, 'decision' => 'APPROVED',
        'computed_minutes' => 90, 'approved_minutes' => 60])->save();

    return $row;
}

/** @param list<OvertimeApproval|EmployeeProfile> $rows */
function otamigCleanup(array $rows): void
{
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        return;
    }
    foreach ($rows as $row) {
        if ($row instanceof OvertimeApproval && Schema::hasTable('overtime_approvals')) {
            OvertimeApproval::query()->whereKey($row->getKey())->delete();
        }
    }
    foreach ($rows as $row) {
        if ($row instanceof EmployeeProfile) {
            EmployeeProfile::query()->whereKey($row->getKey())->delete();
        }
    }
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function otamigDdlDuring(callable $callback): array
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

function otamigAssertTable(bool $applied): void
{
    expect(Schema::hasTable('overtime_approvals'))->toBe($applied);
    if (! $applied) {
        return;
    }
    expect(Schema::hasIndex('overtime_approvals', 'ota_employee_date_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasForeignKey('overtime_approvals', ['employee_id']))->toBeTrue()
        ->and(Schema::hasColumns('overtime_approvals', [
            'id', 'employee_id', 'date', 'decision', 'computed_minutes', 'approved_minutes',
            'note', 'reviewed_by', 'created_at', 'updated_at',
        ]))->toBeTrue();
}

it('rolls the overtime_approvals table back and re-applies it twice, losing every decision', function () {
    $migration = otamigMigration();
    $seeded = [];

    try {
        $employee = otamigEmployee();
        $seeded[] = $employee;
        $before = otamigRow($employee, '2026-09-08');
        otamigAssertTable(true);

        $migration->down();
        otamigAssertTable(false);

        // A repeated rollback finds nothing to drop and does nothing.
        $migration->down();
        otamigAssertTable(false);

        $migration->up();
        // The retry: every piece already exists, so every step is skipped.
        $migration->up();
        otamigAssertTable(true);

        // ROLLBACK LOSES the decision; the employee is untouched.
        expect(OvertimeApproval::query()->whereKey($before->getKey())->exists())->toBeFalse()
            ->and(EmployeeProfile::query()->whereKey($employee->getKey())->exists())->toBeTrue();

        // The table takes rows again, one per employee and day.
        $after = otamigRow($employee, '2026-09-08');
        $seeded[] = $after;
        expect(fn () => otamigRow($employee, '2026-09-08'))->toThrow(QueryException::class);
        expect(OvertimeApproval::query()->where('employee_id', (string) $employee->getKey())->count())->toBe(1);
    } finally {
        $migration->up();
        otamigCleanup($seeded);
    }
});

it('runs no DDL on an up() that finds the overtime_approvals table complete', function () {
    $migration = otamigMigration();

    try {
        otamigAssertTable(true);

        $ddl = otamigDdlDuring(static fn () => $migration->up());

        // sqlite: nothing at all. MySQL/MariaDB: only the unguarded,
        // idempotent ROW_FORMAT restatement.
        $allowed = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? ['ALTER TABLE `overtime_approvals` ROW_FORMAT=DYNAMIC']
            : [];
        expect($ddl)->toBe($allowed);
        otamigAssertTable(true);
    } finally {
        $migration->up();
    }
});

it('keeps the deciding account out of mass assignment', function () {
    $row = new OvertimeApproval;
    $row->fill(['reviewed_by' => 7, 'decision' => 'APPROVED']);

    expect($row->reviewed_by)->toBeNull()
        ->and($row->decision)->toBe('APPROVED');
});
