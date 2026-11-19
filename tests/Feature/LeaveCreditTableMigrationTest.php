<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The leave-credits table migration (2026_09_26_0003 leave_credits) rolls
 * back and re-applies cleanly, and survives a repeated up() or down() — the
 * cPanel cron re-running a half-applied deploy. The table comes back with its
 * seven columns, its ULID primary key, the `lcr_employee_year_type_unq`
 * unique and the employee foreign key. ROLLBACK LOSES every entered credit,
 * while the employees themselves survive.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up()
 * before anything else, then deletes the rows seeded here by key. On
 * MySQL/MariaDB DDL commits implicitly, so a down() ends RefreshDatabase's
 * transaction, which can then roll back neither the schema nor those rows
 * (the RosterLocationsMigrationTest pattern). Under phpunit.mariadb.xml the
 * rollback test is skipped for that reason — later migrations widened the
 * table, and the create alone cannot bring that back — and only the
 * "runs no DDL" test runs.
 *
 * `leave_credits` has no child tables, so dropping it takes nothing with it.
 * The two CHECKs are MySQL/MariaDB only and belong to
 * tests/Integration/LeaveCreditTableSchemaTest.
 */

/** The table migration — an anonymous class, so untyped here. */
function lctmigMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0003_create_leave_credits_table.php');
}

/** An employee seeded straight through the model. */
function lctmigEmployee(string $suffix): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LCM-'.$suffix.'-'.random_int(10000, 99999),
        'first_name' => 'Credit',
        'last_name' => 'Migration '.$suffix,
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/** One credit row through the model. */
function lctmigRow(EmployeeProfile $employee, int $year, string $requestType, float $credits): LeaveCredit
{
    $row = new LeaveCredit;
    $row->forceFill([
        'employee_id' => (string) $employee->getKey(),
        'year' => $year,
        'request_type' => $requestType,
        'credits' => $credits,
    ])->save();

    return $row;
}

/**
 * Removes the seeded rows by key where DDL has already committed them.
 * Children first: credit rows, then employees.
 *
 * @param  list<LeaveCredit|EmployeeProfile>  $rows
 */
function lctmigCleanup(array $rows): void
{
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        return;
    }
    foreach ($rows as $row) {
        if ($row instanceof LeaveCredit && Schema::hasTable('leave_credits')) {
            LeaveCredit::query()->whereKey($row->getKey())->delete();
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
function lctmigDdlDuring(callable $callback): array
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

/**
 * The table's columns as the schema reports them, in table order.
 *
 * @return array<string, array{type: string, type_name: string, nullable: bool}>
 */
function lctmigColumns(): array
{
    $columns = [];
    foreach (Schema::getColumns('leave_credits') as $column) {
        $columns[$column['name']] = [
            'type' => strtolower((string) $column['type']),
            'type_name' => strtolower((string) $column['type_name']),
            'nullable' => (bool) $column['nullable'],
        ];
    }

    return $columns;
}

/** Every piece of the leave_credits table, present or absent together. */
function lctmigAssertTable(bool $applied): void
{
    expect(Schema::hasTable('leave_credits'))->toBe($applied);
    if (! $applied) {
        return;
    }
    expect(Schema::hasIndex('leave_credits', 'lcr_employee_year_type_unq'))->toBeTrue()
        ->and(Schema::hasIndex('leave_credits', 'lcr_employee_year_type_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('leave_credits', ['id'], 'primary'))->toBeTrue()
        ->and(Schema::hasForeignKey('leave_credits', ['employee_id']))->toBeTrue();

    // The seven columns in creation order; only updated_at takes NULL.
    $columns = lctmigColumns();
    expect(array_keys($columns))->toBe(['id', 'employee_id', 'year', 'request_type', 'credits', 'created_at', 'updated_at']);
    foreach ($columns as $name => $column) {
        expect($column['nullable'])->toBe($name === 'updated_at', "{$name} nullability");
    }

    // sqlite spells char as varchar, unsigned smallint as integer and decimal
    // as numeric; MySQL/MariaDB report the declared types (MariaDB with its
    // display width on the smallint, hence type_name rather than type there).
    $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $expected = $mysql
        ? ['id' => 'char', 'employee_id' => 'char', 'year' => 'smallint', 'request_type' => 'varchar', 'credits' => 'decimal', 'created_at' => 'datetime', 'updated_at' => 'datetime']
        : ['id' => 'varchar', 'employee_id' => 'varchar', 'year' => 'integer', 'request_type' => 'varchar', 'credits' => 'numeric', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    foreach ($expected as $name => $typeName) {
        expect($columns[$name]['type_name'])->toBe($typeName, "{$name} type");
    }
    if ($mysql) {
        expect($columns['id']['type'])->toBe('char(26)')
            ->and($columns['employee_id']['type'])->toBe('char(26)')
            ->and($columns['request_type']['type'])->toBe('varchar(60)')
            // The finished schema's width: 2026_10_31_0003 widened the create's decimal(6,2).
            ->and($columns['credits']['type'])->toBe('decimal(10,4)')
            ->and($columns['year']['type'])->toContain('unsigned')
            ->and($columns['created_at']['type'])->toBe('datetime(3)')
            ->and($columns['updated_at']['type'])->toBe('datetime(3)');
    }
}

it('rolls the leave_credits table back and re-applies it twice, losing every credit', function () {
    // The create alone rebuilds the table in its first shape, narrower than
    // the finished schema, and on MySQL/MariaDB that DDL commits: every
    // later test would run on the narrow table. There the rollback is
    // tests/Integration/LeaveCreditTableSchemaTest's, on a schema of its own.
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('On MySQL/MariaDB the rollback is covered by LeaveCreditTableSchemaTest.');
    }
    $migration = lctmigMigration();
    $seeded = [];

    try {
        $employee = lctmigEmployee('A');
        $seeded[] = $employee;
        $before = lctmigRow($employee, 2026, 'VACATION_LEAVE', 15.0);
        lctmigAssertTable(true);
        expect(LeaveCredit::query()->findOrFail((string) $before->getKey())->credits)->toBe(15.0);

        $migration->down();
        lctmigAssertTable(false);

        // A repeated rollback finds nothing to drop and does nothing.
        $migration->down();
        lctmigAssertTable(false);

        $migration->up();
        // The retry: every piece already exists, so every step is skipped.
        $migration->up();
        lctmigAssertTable(true);

        // ROLLBACK LOSES the credit: the row is gone, the employee is untouched.
        expect(LeaveCredit::query()->whereKey($before->getKey())->exists())->toBeFalse()
            ->and(LeaveCredit::query()->where('employee_id', (string) $employee->getKey())->count())->toBe(0)
            ->and(EmployeeProfile::query()->whereKey($employee->getKey())->exists())->toBeTrue();

        // And the table takes rows again once it is back — a half day, an
        // explicit zero — with the unique index holding one row per
        // (employee, year, type) and the ULID minted by BaseModel.
        $after = lctmigRow($employee, 2026, 'SICK_LEAVE', 7.5);
        $zero = lctmigRow($employee, 2026, 'VACATION_LEAVE', 0.0);
        $seeded[] = $after;
        $seeded[] = $zero;
        expect(strlen((string) $after->getKey()))->toBe(26)
            ->and(LeaveCredit::query()->findOrFail((string) $after->getKey())->credits)->toBe(7.5)
            ->and(LeaveCredit::query()->findOrFail((string) $zero->getKey())->credits)->toBe(0.0)
            ->and(Str::isUlid((string) $after->getKey()))->toBeTrue();
        expect(fn () => lctmigRow($employee, 2026, 'SICK_LEAVE', 10.0))->toThrow(QueryException::class);
        // The same type in another year is a different row.
        $nextYear = lctmigRow($employee, 2027, 'SICK_LEAVE', 10.0);
        $seeded[] = $nextYear;
        expect(LeaveCredit::query()->where('employee_id', (string) $employee->getKey())->count())->toBe(3);
    } finally {
        // Re-migrate FIRST, even when an expectation failed between the
        // down() and up() calls above. Every step is guarded, so on a
        // passing run this is a no-op.
        $migration->up();
        lctmigCleanup($seeded);
    }
});

it('runs no DDL on an up() that finds the leave_credits table complete', function () {
    $migration = lctmigMigration();

    try {
        lctmigAssertTable(true);

        $ddl = lctmigDdlDuring(static fn () => $migration->up());

        // sqlite: nothing at all. MySQL/MariaDB: only the unguarded,
        // idempotent ROW_FORMAT restatement.
        $allowed = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? ['ALTER TABLE `leave_credits` ROW_FORMAT=DYNAMIC']
            : [];
        expect($ddl)->toBe($allowed);
        lctmigAssertTable(true);
    } finally {
        $migration->up();
    }
});
