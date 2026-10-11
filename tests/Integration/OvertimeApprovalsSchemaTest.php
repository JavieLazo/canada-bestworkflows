<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, the overtime-decision table (2026_09_26_0006
 * overtime_approvals) is retry-safe step by step: its three CHECK constraints
 * exist exactly once and refuse a decision outside APPROVED/REJECTED, a
 * computed figure outside 1..1440 and approved minutes that do not fit the
 * decision; the unique (employee, day) index holds; the employee foreign key
 * cascades; and a run killed between any two statements resumes with only
 * the missing piece.
 *
 * sqlite never creates a CHECK, so only this suite sees them. DatabaseMigrations
 * rolls the whole schema back after each test, so EVERY TEST ENDS MIGRATED.
 */

/** The migration — an anonymous class, so untyped here. */
function otaschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0006_create_overtime_approvals_table.php');
}

/** How many CHECK constraints of this name the table carries. */
function otaschemaChecks(string $name): int
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'overtime_approvals' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
        [$name],
    );

    return is_object($row) && isset($row->n) ? (int) $row->n : 0;
}

/** @return list<string> */
function otaschemaIndexNames(): array
{
    $names = array_map(static fn (array $index): string => $index['name'], Schema::getIndexes('overtime_approvals'));
    sort($names);

    return $names;
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function otaschemaDdlDuring(callable $callback): array
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

function otaschemaAssertApplied(): void
{
    expect(Schema::hasTable('overtime_approvals'))->toBeTrue();
    foreach (['ota_decision_chk', 'ota_computed_chk', 'ota_minutes_chk'] as $check) {
        expect(otaschemaChecks($check))->toBe(1);
    }
    expect(otaschemaIndexNames())->toBe(['ota_employee_date_unq', 'primary'])
        ->and(Schema::hasForeignKey('overtime_approvals', ['employee_id']))->toBeTrue();
}

function otaschemaEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'OTS-'.Str::upper(Str::random(5)), 'first_name' => 'Overtime', 'last_name' => 'Schema',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    return $employee;
}

/** A decision straight into the table, so no model or command hides a bad value. */
function otaschemaRow(EmployeeProfile $employee, string $date, string $decision, int $computed, int $approved): string
{
    $id = (string) Str::ulid();
    DB::table('overtime_approvals')->insert([
        'id' => $id, 'employee_id' => (string) $employee->getKey(), 'date' => $date, 'decision' => $decision,
        'computed_minutes' => $computed, 'approved_minutes' => $approved, 'note' => null, 'reviewed_by' => 1,
        'created_at' => '2026-09-26 01:00:00.000', 'updated_at' => null,
    ]);

    return $id;
}

it('applies the migration twice on top of a migrated schema, with each CHECK exactly once', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    otaschemaAssertApplied();
    $migration = otaschemaMigration();

    $restated = 'ALTER TABLE `overtime_approvals` ROW_FORMAT=DYNAMIC';
    expect(otaschemaDdlDuring(static function () use ($migration): void {
        $migration->up();
        $migration->up();
    }))->toBe([$restated, $restated]);

    otaschemaAssertApplied();
});

it('refuses a decision, a computed figure or approved minutes outside the constraints', function () {
    $employee = otaschemaEmployee();

    expect(fn () => otaschemaRow($employee, '2026-09-01', 'PENDING', 60, 0))->toThrow(QueryException::class, 'ota_decision_chk');
    expect(fn () => otaschemaRow($employee, '2026-09-01', 'APPROVED', 0, 0))->toThrow(QueryException::class, 'ota_computed_chk');
    expect(fn () => otaschemaRow($employee, '2026-09-01', 'APPROVED', 1441, 60))->toThrow(QueryException::class, 'ota_computed_chk');
    // An approval of nothing, of more than computed, and a rejection that pays.
    expect(fn () => otaschemaRow($employee, '2026-09-01', 'APPROVED', 60, 0))->toThrow(QueryException::class, 'ota_minutes_chk');
    expect(fn () => otaschemaRow($employee, '2026-09-01', 'APPROVED', 60, 61))->toThrow(QueryException::class, 'ota_minutes_chk');
    expect(fn () => otaschemaRow($employee, '2026-09-01', 'REJECTED', 60, 30))->toThrow(QueryException::class, 'ota_minutes_chk');
    expect(DB::table('overtime_approvals')->count())->toBe(0);

    // The legal shapes land, one per employee and day.
    otaschemaRow($employee, '2026-09-01', 'APPROVED', 60, 60);
    otaschemaRow($employee, '2026-09-02', 'APPROVED', 1440, 1);
    otaschemaRow($employee, '2026-09-03', 'REJECTED', 60, 0);
    expect(fn () => otaschemaRow($employee, '2026-09-01', 'REJECTED', 60, 0))->toThrow(QueryException::class, 'ota_employee_date_unq');
    expect(DB::table('overtime_approvals')->count())->toBe(3);
});

it('removes an employee\'s decisions with the employee', function () {
    $employee = otaschemaEmployee();
    $row = otaschemaRow($employee, '2026-09-01', 'APPROVED', 60, 60);

    DB::table('employee_profiles')->where('id', $employee->getKey())->delete();

    expect(DB::table('overtime_approvals')->where('id', $row)->exists())->toBeFalse();
});

it('removes the table on rollback, tolerates a second one, and restores every piece', function () {
    otaschemaRow(otaschemaEmployee(), '2026-09-01', 'APPROVED', 60, 60);
    $migration = otaschemaMigration();

    $migration->down();
    expect(Schema::hasTable('overtime_approvals'))->toBeFalse();
    expect(otaschemaDdlDuring(static fn () => $migration->down()))->toBe([]);

    $migration->up();
    otaschemaAssertApplied();
    // ROLLBACK LOSES every decision.
    expect(DB::table('overtime_approvals')->count())->toBe(0);
});

it('resumes from a run killed between any two of its statements', function () {
    $migration = otaschemaMigration();
    $restated = 'ALTER TABLE `overtime_approvals` ROW_FORMAT=DYNAMIC';

    // Killed before a CHECK: only that CHECK is re-added (plus the restatement).
    foreach (['ota_decision_chk', 'ota_computed_chk', 'ota_minutes_chk'] as $check) {
        DB::statement("ALTER TABLE `overtime_approvals` DROP CONSTRAINT `{$check}`");
        expect(otaschemaChecks($check))->toBe(0);

        $ddl = otaschemaDdlDuring(static fn () => $migration->up());

        expect($ddl)->toHaveCount(2)
            ->and($ddl[0])->toBe($restated)
            ->and($ddl[1])->toStartWith("ALTER TABLE `overtime_approvals` ADD CONSTRAINT `{$check}` CHECK");
        otaschemaAssertApplied();
    }

    // Killed before the unique: the foreign key uses it, so both come back, unique first.
    DB::statement('ALTER TABLE `overtime_approvals` DROP FOREIGN KEY `overtime_approvals_employee_id_foreign`');
    DB::statement('ALTER TABLE `overtime_approvals` DROP INDEX `ota_employee_date_unq`');

    $ddl = otaschemaDdlDuring(static fn () => $migration->up());

    expect($ddl)->toHaveCount(3)
        ->and(strtolower($ddl[0]))->toContain('add unique `ota_employee_date_unq`')
        ->and(strtolower($ddl[1]))->toContain('foreign key (`employee_id`) references `employee_profiles`')
        ->and(strtolower($ddl[1]))->toContain('on delete cascade')
        ->and($ddl[2])->toBe($restated);
    otaschemaAssertApplied();
});

it('resumes from a create killed right after its CREATE TABLE', function () {
    $migration = otaschemaMigration();
    DB::statement('ALTER TABLE `overtime_approvals` DROP FOREIGN KEY `overtime_approvals_employee_id_foreign`');
    DB::statement('ALTER TABLE `overtime_approvals` DROP INDEX `ota_employee_date_unq`');
    foreach (['ota_decision_chk', 'ota_computed_chk', 'ota_minutes_chk'] as $check) {
        DB::statement("ALTER TABLE `overtime_approvals` DROP CONSTRAINT `{$check}`");
    }
    expect(otaschemaIndexNames())->toBe(['primary']);

    $migration->up();

    otaschemaAssertApplied();
});
