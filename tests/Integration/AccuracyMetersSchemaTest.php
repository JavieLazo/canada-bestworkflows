<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * On the production engine, a punch's GPS accuracy is one nullable
 * DECIMAL(12,2) column added by a single retry-safe ALTER: re-running the
 * migration on a migrated schema sends no DDL, a run killed before or after
 * its one ALTER resumes to the same column, a full rollback removes it (a
 * second rollback finds nothing to drop) and a re-run restores it — and the
 * punches themselves survive every step, only their accuracies are lost to a
 * rollback.
 *
 * The migration appends the column without `->after()`; its docblock says
 * that keeps the ADD instant on MariaDB 11.4, and this suite checks the
 * statement it sends is accepted with ALGORITHM=INSTANT. sqlite rebuilds a
 * table for a column drop, so only this suite sees the real ALTERs.
 * DatabaseMigrations rolls the whole schema back after each test, so EVERY
 * TEST ENDS MIGRATED.
 */

/** The migration — an anonymous class, so untyped here. */
function accschemaMigration(): mixed
{
    return require base_path('database/migrations/2026_09_25_0003_add_accuracy_meters_to_attendance_logs_table.php');
}

/**
 * The column as information_schema describes it, or null when absent.
 *
 * @return array{type: string, nullable: string, default: mixed}|null
 */
function accschemaColumn(): ?array
{
    $row = DB::selectOne(
        'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['attendance_logs', 'accuracy_meters'],
    );
    // The default is checked for presence, not isset(): MySQL reports it as a real null.
    if (! is_object($row) || ! isset($row->type, $row->nullable) || ! property_exists($row, 'dflt')) {
        return null;
    }

    return ['type' => strtolower((string) $row->type), 'nullable' => (string) $row->nullable, 'default' => $row->dflt];
}

/** The column is there, a plain nullable DECIMAL(12,2) with no default of its own. */
function accschemaAssertApplied(): void
{
    $column = accschemaColumn();
    expect($column)->not->toBeNull();
    assert($column !== null);
    expect($column['type'])->toBe('decimal(12,2)')
        ->and($column['nullable'])->toBe('YES');
    // MariaDB reports a nullable column's implicit default as the string
    // 'NULL' (MySQL as a real null): either way, no default value.
    expect($column['default'] === null || $column['default'] === 'NULL')->toBeTrue();
    expect(Schema::hasColumn('attendance_logs', 'accuracy_meters'))->toBeTrue();
}

function accschemaAssertAbsent(): void
{
    expect(accschemaColumn())->toBeNull();
    expect(Schema::hasColumn('attendance_logs', 'accuracy_meters'))->toBeFalse();
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function accschemaDdlDuring(callable $callback): array
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

function accschemaEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'ACC-'.Str::upper(Str::random(5)),
        'first_name' => 'Accuracy',
        'last_name' => 'Schema '.Str::random(4),
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/**
 * A punch straight into the table, so no model default hides what the
 * column holds. A null `$accuracy` leaves the column out of the INSERT
 * altogether, which also works while the column does not exist.
 */
function accschemaPunch(EmployeeProfile $employee, ?float $accuracy): string
{
    $id = (string) Str::ulid();
    $row = [
        'id' => $id,
        'employee_id' => (string) $employee->getKey(),
        'site_id' => null,
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'distance_meters' => null,
        'is_geofenced' => 1,
        'is_anomaly' => 0,
        'anomaly_note' => null,
        'logged_at' => '2026-09-25 01:00:00.000',
    ];
    if ($accuracy !== null) {
        $row['accuracy_meters'] = $accuracy;
    }
    DB::table('attendance_logs')->insert($row);

    return $id;
}

it('applies the accuracy migration twice on top of a migrated schema without sending any DDL', function () {
    expect(in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true))->toBeTrue();
    // migrate:fresh has already run it through the migrator.
    accschemaAssertApplied();
    $migration = accschemaMigration();

    expect(accschemaDdlDuring(static function () use ($migration): void {
        $migration->up();
        $migration->up();
    }))->toBe([]);

    accschemaAssertApplied();
});

it('adds the column as DECIMAL(12,2) NULL in one ALTER with no AFTER clause, which MariaDB runs instantly', function () {
    $migration = accschemaMigration();
    $migration->down();
    accschemaAssertAbsent();

    $ddl = accschemaDdlDuring(static fn () => $migration->up());

    // The one statement a run sends: appended, never placed with AFTER.
    expect($ddl)->toBe(['alter table `attendance_logs` add `accuracy_meters` decimal(12, 2) null']);
    accschemaAssertApplied();

    // The same statement with ALGORITHM=INSTANT is refused outright where the
    // engine would have to copy the table, so this proves the ADD is instant.
    $migration->down();
    DB::statement($ddl[0].', ALGORITHM=INSTANT');
    accschemaAssertApplied();
});

it('stores a two-decimal accuracy and leaves every punch without one null', function () {
    $employee = accschemaEmployee();
    $blank = accschemaPunch($employee, null);
    $measured = accschemaPunch($employee, 8.46);

    expect(DB::table('attendance_logs')->where('id', $blank)->value('accuracy_meters'))->toBeNull();
    expect((string) DB::table('attendance_logs')->where('id', $measured)->value('accuracy_meters'))->toBe('8.46');
    // The model's float cast reads the same number back.
    expect(AttendanceLog::query()->findOrFail($measured)->accuracy_meters)->toBe(8.46);
    expect(AttendanceLog::query()->findOrFail($blank)->accuracy_meters)->toBeNull();
});

it('removes the accuracy column on a full rollback, tolerates a second one, and restores it with every punch intact', function () {
    $employee = accschemaEmployee();
    $measured = accschemaPunch($employee, 8.46);
    $blank = accschemaPunch($employee, null);
    $migration = accschemaMigration();

    $migration->down();
    accschemaAssertAbsent();

    // A rollback killed after its DROP and re-run finds nothing left to drop.
    expect(accschemaDdlDuring(static fn () => $migration->down()))->toBe([]);
    accschemaAssertAbsent();

    // The punches, their types, coordinates and instants are untouched.
    expect(DB::table('attendance_logs')->whereIn('id', [$measured, $blank])->count())->toBe(2);
    expect(DB::table('attendance_logs')->where('id', $measured)->value('log_type'))->toBe('TIME_IN');
    expect((string) DB::table('attendance_logs')->where('id', $measured)->value('logged_at'))->toBe('2026-09-25 01:00:00.000');

    $migration->up();
    accschemaAssertApplied();

    // ROLLBACK LOSES every recorded accuracy: the restored column is blank,
    // for the punch that was measured as much as for the one that was not.
    expect(DB::table('attendance_logs')->whereIn('id', [$measured, $blank])->pluck('accuracy_meters')->all())->toBe([null, null]);
    expect(DB::table('attendance_logs')->whereIn('id', [$measured, $blank])->count())->toBe(2);
});

it('resumes an accuracy migration run killed on either side of its one ALTER', function () {
    $employee = accschemaEmployee();
    $punch = accschemaPunch($employee, null);
    $migration = accschemaMigration();

    // Killed BEFORE the ALTER: the column is not there, and the re-run adds it.
    $migration->down();
    accschemaAssertAbsent();
    expect(accschemaDdlDuring(static fn () => $migration->up()))->toHaveCount(1);
    accschemaAssertApplied();

    // Killed AFTER the ALTER, before the migrator recorded it: the column is
    // there, a reading may already have landed, and the re-run sends no DDL
    // and leaves the value alone.
    DB::table('attendance_logs')->where('id', $punch)->update(['accuracy_meters' => 5.5]);
    expect(accschemaDdlDuring(static fn () => $migration->up()))->toBe([]);
    accschemaAssertApplied();
    expect((string) DB::table('attendance_logs')->where('id', $punch)->value('accuracy_meters'))->toBe('5.50');
});
