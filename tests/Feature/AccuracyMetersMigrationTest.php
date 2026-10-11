<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\WorkSite;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The accuracy migration (2026_09_25_0003) rolls back and re-applies
 * cleanly, and survives a repeated up() or down() — the cPanel cron
 * re-running a half-applied deploy. The column comes back nullable with no
 * default, every punch that existed before it reads null (there is no
 * backfill: "not measured"), and ROLLBACK LOSES an accuracy that was recorded
 * while the rest of the punch survives.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up()
 * before anything else, then deletes the rows seeded here by key. On
 * MySQL/MariaDB DDL commits implicitly, so the down() ends RefreshDatabase's
 * transaction, which can then roll back neither the schema nor those rows
 * (the same pattern as TaskStartDateMigrationTest).
 *
 * `attendance_logs` has no child tables, so the sqlite table rebuild behind
 * a column drop cannot cascade into anything (see ProjectTaskAssigneesTest).
 */

/** The migration under test — an anonymous class, so untyped here. */
function accmigMigration(): mixed
{
    return require base_path('database/migrations/2026_09_25_0003_add_accuracy_meters_to_attendance_logs_table.php');
}

/**
 * A site, an employee and one punch seeded straight through the models,
 * with an optional accuracy on the punch.
 *
 * @return array{site: string, employee: string, punch: string}
 */
function accmigSeed(string $suffix, ?float $accuracy): array
{
    $site = WorkSite::query()->create([
        'name' => 'Accuracy site '.$suffix.' '.Str::random(4),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 150,
        'geofence_exempt' => true,
    ]);

    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'ACC-'.$suffix.'-'.random_int(10000, 99999),
        'first_name' => 'Accuracy',
        'last_name' => 'Punch '.$suffix,
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
        'work_site_id' => $site->getKey(),
    ])->save();

    $punch = new AttendanceLog;
    $punch->forceFill(array_merge([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => (string) $site->getKey(),
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'distance_meters' => null,
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant('2026-09-02T08:00:31')->utc(),
    ], $accuracy === null ? [] : ['accuracy_meters' => $accuracy]))->save();

    return ['site' => (string) $site->getKey(), 'employee' => (string) $employee->getKey(), 'punch' => (string) $punch->getKey()];
}

/**
 * Removes the seeded rows by key where DDL has already committed them.
 *
 * @param  array<string, array{site: string, employee: string, punch: string}>  $seeded
 */
function accmigCleanup(array $seeded): void
{
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        return;
    }
    foreach ($seeded as $rows) {
        AttendanceLog::query()->whereKey($rows['punch'])->delete();
        EmployeeProfile::query()->whereKey($rows['employee'])->delete();
        WorkSite::query()->whereKey($rows['site'])->delete();
    }
}

/**
 * The accuracy column as the schema reports it, or null when it is absent.
 *
 * @return array<string, mixed>|null
 */
function accmigColumn(): ?array
{
    foreach (Schema::getColumns('attendance_logs') as $column) {
        if ($column['name'] === 'accuracy_meters') {
            return $column;
        }
    }

    return null;
}

it('rolls the accuracy column back and re-applies it twice, leaving an existing punch unmeasured', function () {
    $migration = accmigMigration();
    $seeded = [];

    try {
        // Two punches that predate the rollback: one never measured, one with
        // a recorded accuracy — which the rollback throws away.
        $seeded['blank'] = accmigSeed('A', null);
        $seeded['measured'] = accmigSeed('B', 12.34);
        expect((float) DB::table('attendance_logs')->where('id', $seeded['measured']['punch'])->value('accuracy_meters'))->toBe(12.34);

        $migration->down();
        expect(Schema::hasColumn('attendance_logs', 'accuracy_meters'))->toBeFalse();

        // A repeated rollback finds nothing to drop and does nothing.
        $migration->down();
        expect(Schema::hasColumn('attendance_logs', 'accuracy_meters'))->toBeFalse();

        $migration->up();
        // The retry: the column already exists, so the step is skipped.
        $migration->up();

        expect(Schema::hasColumn('attendance_logs', 'accuracy_meters'))->toBeTrue();
        $column = accmigColumn();
        expect($column)->not->toBeNull()
            // sqlite declares the column NUMERIC; MySQL/MariaDB report DECIMAL.
            ->and(in_array(strtolower((string) $column['type_name']), ['numeric', 'decimal'], true))->toBeTrue()
            ->and($column['nullable'])->toBeTrue()
            // No default: sqlite reports null, MariaDB's information_schema
            // reports the literal string 'NULL' for a nullable column without
            // one (COLUMN_DEFAULT, since 10.2.7) — both mean "none".
            ->and(in_array($column['default'], [null, 'NULL'], true))->toBeTrue();

        // No backfill: both punches read "not measured", the one that was
        // measured included — ROLLBACK LOSES every recorded accuracy.
        foreach ($seeded as $rows) {
            expect(DB::table('attendance_logs')->where('id', $rows['punch'])->value('accuracy_meters'))->toBeNull();
            expect(AttendanceLog::query()->findOrFail($rows['punch'])->accuracy_meters)->toBeNull();
        }

        // The rest of the punch is untouched by the drop and the re-add.
        $measured = AttendanceLog::query()->findOrFail($seeded['measured']['punch']);
        expect($measured->log_type)->toBe('TIME_IN')
            ->and($measured->source)->toBe('WEB_GPS')
            ->and((float) $measured->latitude)->toBe(14.5995)
            ->and($measured->logged_at->format('Y-m-d H:i:s'))->toBe('2026-09-02 00:00:31');

        // And the column takes a reading again once it is back — a
        // two-decimal value, so sqlite and MariaDB read the same number.
        $measured->forceFill(['accuracy_meters' => 8.45])->save();
        expect((float) $measured->fresh()?->accuracy_meters)->toBe(8.45);
    } finally {
        // Re-migrate FIRST, even when an expectation failed between the
        // down() and up() calls above. The step is guarded, so on a passing
        // run this is a no-op.
        $migration->up();
        accmigCleanup($seeded);
    }
});

it('runs no DDL on an up() that finds the accuracy column already there', function () {
    $migration = accmigMigration();

    try {
        expect(Schema::hasColumn('attendance_logs', 'accuracy_meters'))->toBeTrue();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $migration->up();
        DB::disableQueryLog();

        $ddl = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1,
        ));
        expect($ddl)->toBe([]);
        expect(Schema::hasColumn('attendance_logs', 'accuracy_meters'))->toBeTrue();
    } finally {
        $migration->up();
    }
});
