<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\RosterLocation;
use App\Models\WorkSite;
use App\Support\LocalClock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The two weekly-location migrations (2026_09_26_0001 roster_locations,
 * 2026_09_26_0002 attendance_logs.location_kind) roll back and re-apply
 * cleanly, and survive a repeated up() or down() — the cPanel cron
 * re-running a half-applied deploy. The table comes back with its unique
 * index, its site index and both foreign keys; the column comes back NOT
 * NULL DEFAULT 'ONSITE'. ROLLBACK LOSES every weekly schedule and which
 * punches were made from home, while the punches themselves survive.
 *
 * THIS FILE ALWAYS ENDS MIGRATED, pass or fail: each `finally` re-runs up()
 * before anything else, then deletes the rows seeded here by key. On
 * MySQL/MariaDB DDL commits implicitly, so a down() ends RefreshDatabase's
 * transaction, which can then roll back neither the schema nor those rows
 * (the AccuracyMetersMigrationTest pattern).
 *
 * `attendance_logs` has no child tables, so the sqlite table rebuild behind
 * a column drop cannot cascade into anything; `roster_locations` has none
 * either, so dropping it takes nothing with it. The CHECKs are MySQL/MariaDB
 * only and belong to tests/Integration/RosterLocationsSchemaTest.
 */

/** The table migration — an anonymous class, so untyped here. */
function rlocmigTableMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0001_create_roster_locations_table.php');
}

/**
 * The migration that later versions this table (2026_09_30_0006): it drops
 * the unique the create adds, so the create's retry checks run with it
 * rolled back first and re-applied after — the order production runs them.
 */
function rlocmigVersioningMigration(): mixed
{
    return require base_path('database/migrations/2026_09_30_0006_add_effective_from_to_roster_locations_table.php');
}

/** The column migration. */
function rlocmigColumnMigration(): mixed
{
    return require base_path('database/migrations/2026_09_26_0002_add_location_kind_to_attendance_logs_table.php');
}

/**
 * A site and an employee assigned to it, seeded straight through the models.
 *
 * @return array{site: WorkSite, employee: EmployeeProfile}
 */
function rlocmigPeople(string $suffix): array
{
    $site = WorkSite::query()->create([
        'name' => 'Roster site '.$suffix.' '.Str::random(4),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 150,
        'geofence_exempt' => false,
    ]);

    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'RLM-'.$suffix.'-'.random_int(10000, 99999),
        'first_name' => 'Roster',
        'last_name' => 'Migration '.$suffix,
        'position' => 'Field Engineer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
        'work_site_id' => $site->getKey(),
    ])->save();

    return ['site' => $site, 'employee' => $employee];
}

/** One weekly-location row through the model. */
function rlocmigRow(EmployeeProfile $employee, int $weekday, ?WorkSite $site): RosterLocation
{
    $row = new RosterLocation;
    $row->forceFill([
        'employee_id' => (string) $employee->getKey(),
        'weekday' => $weekday,
        'kind' => $site === null ? 'WFH' : 'ONSITE',
        'site_id' => $site?->getKey(),
    ])->save();

    return $row;
}

/** One punch through the model, with the kind the test needs on it. */
function rlocmigPunch(EmployeeProfile $employee, ?WorkSite $site, string $kind): AttendanceLog
{
    $punch = new AttendanceLog;
    $punch->forceFill([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => $site?->getKey(),
        'location_kind' => $kind,
        'log_type' => 'TIME_IN',
        'source' => 'WEB_GPS',
        'latitude' => 14.6500,
        'longitude' => 121.0500,
        'distance_meters' => null,
        'accuracy_meters' => 9.0,
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => LocalClock::instant('2026-09-02T08:00:31')->utc(),
    ])->save();

    return $punch;
}

/**
 * Removes the seeded rows by key where DDL has already committed them.
 * Children first: roster rows and punches, then employees, then sites.
 *
 * @param  list<RosterLocation|AttendanceLog|EmployeeProfile|WorkSite>  $rows
 */
function rlocmigCleanup(array $rows): void
{
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        return;
    }
    foreach ($rows as $row) {
        if ($row instanceof RosterLocation && Schema::hasTable('roster_locations')) {
            RosterLocation::query()->whereKey($row->getKey())->delete();
        } elseif ($row instanceof AttendanceLog) {
            AttendanceLog::query()->whereKey($row->getKey())->delete();
        }
    }
    foreach ($rows as $row) {
        if ($row instanceof EmployeeProfile) {
            EmployeeProfile::query()->whereKey($row->getKey())->delete();
        }
    }
    foreach ($rows as $row) {
        if ($row instanceof WorkSite) {
            WorkSite::query()->whereKey($row->getKey())->delete();
        }
    }
}

/**
 * Every CREATE, ALTER or DROP the callback sends, in order.
 *
 * @return list<string>
 */
function rlocmigDdlDuring(callable $callback): array
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
 * The location_kind column as the schema reports it, or null when absent.
 *
 * @return array<string, mixed>|null
 */
function rlocmigColumn(): ?array
{
    foreach (Schema::getColumns('attendance_logs') as $column) {
        if ($column['name'] === 'location_kind') {
            return $column;
        }
    }

    return null;
}

/** Every piece of the roster_locations table, present or absent together. */
function rlocmigAssertTable(bool $applied): void
{
    expect(Schema::hasTable('roster_locations'))->toBe($applied);
    if (! $applied) {
        return;
    }
    expect(Schema::hasIndex('roster_locations', 'rloc_employee_weekday_unq'))->toBeTrue()
        ->and(Schema::hasIndex('roster_locations', 'rloc_employee_weekday_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('roster_locations', 'rloc_site_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('roster_locations', ['employee_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('roster_locations', ['site_id']))->toBeTrue();
}

it('rolls the roster_locations table back and re-applies it twice, losing every schedule', function () {
    $migration = rlocmigTableMigration();
    $versioning = rlocmigVersioningMigration();
    $seeded = [];

    try {
        $versioning->down();
        ['site' => $site, 'employee' => $employee] = rlocmigPeople('A');
        $seeded[] = $site;
        $seeded[] = $employee;
        $before = rlocmigRow($employee, 3, $site);
        rlocmigAssertTable(true);

        $migration->down();
        rlocmigAssertTable(false);

        // A repeated rollback finds nothing to drop and does nothing.
        $migration->down();
        rlocmigAssertTable(false);

        $migration->up();
        // The retry: every piece already exists, so every step is skipped.
        $migration->up();
        rlocmigAssertTable(true);

        // ROLLBACK LOSES the schedule: the Wednesday row is gone, the
        // employee and the site it named are untouched.
        expect(RosterLocation::query()->whereKey($before->getKey())->exists())->toBeFalse()
            ->and(RosterLocation::query()->where('employee_id', (string) $employee->getKey())->count())->toBe(0)
            ->and(EmployeeProfile::query()->whereKey($employee->getKey())->exists())->toBeTrue()
            ->and(WorkSite::query()->whereKey($site->getKey())->exists())->toBeTrue();

        // And the table takes rows again once it is back, unique index included.
        $after = rlocmigRow($employee, 3, null);
        $seeded[] = $after;
        expect(RosterLocation::query()->findOrFail((string) $after->getKey())->kind)->toBe('WFH');
        expect(fn () => rlocmigRow($employee, 3, $site))->toThrow(QueryException::class);
        expect(RosterLocation::query()->where('employee_id', (string) $employee->getKey())->count())->toBe(1);
    } finally {
        // Re-migrate FIRST, even when an expectation failed between the
        // down() and up() calls above. Every step is guarded, so on a
        // passing run the create's up() is a no-op.
        $migration->up();
        $versioning->up();
        rlocmigCleanup($seeded);
    }
});

it('runs no DDL on an up() that finds the roster_locations table complete', function () {
    $migration = rlocmigTableMigration();
    $versioning = rlocmigVersioningMigration();

    try {
        $versioning->down();
        rlocmigAssertTable(true);

        $ddl = rlocmigDdlDuring(static fn () => $migration->up());

        // sqlite: nothing at all. MySQL/MariaDB: only the unguarded,
        // idempotent ROW_FORMAT restatement.
        $allowed = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? ['ALTER TABLE `roster_locations` ROW_FORMAT=DYNAMIC']
            : [];
        expect($ddl)->toBe($allowed);
        rlocmigAssertTable(true);
    } finally {
        $migration->up();
        $versioning->up();
    }
});

it('rolls the location_kind column back and re-applies it twice, reading every punch as ONSITE', function () {
    $migration = rlocmigColumnMigration();
    $seeded = [];

    try {
        ['site' => $site, 'employee' => $employee] = rlocmigPeople('B');
        $seeded[] = $site;
        $seeded[] = $employee;
        $home = rlocmigPunch($employee, null, 'WFH');
        $office = rlocmigPunch($employee, $site, 'ONSITE');
        $seeded[] = $home;
        $seeded[] = $office;
        expect((string) DB::table('attendance_logs')->where('id', $home->getKey())->value('location_kind'))->toBe('WFH');

        $migration->down();
        expect(Schema::hasColumn('attendance_logs', 'location_kind'))->toBeFalse();

        // A repeated rollback finds nothing to drop and does nothing.
        $migration->down();
        expect(Schema::hasColumn('attendance_logs', 'location_kind'))->toBeFalse();

        $migration->up();
        // The retry: the column already exists, so the step is skipped.
        $migration->up();

        expect(Schema::hasColumn('attendance_logs', 'location_kind'))->toBeTrue();
        $column = rlocmigColumn();
        expect($column)->not->toBeNull()
            ->and($column['nullable'])->toBeFalse()
            // sqlite reports the literal as written, quotes included;
            // MySQL/MariaDB report the bare value.
            ->and(in_array($column['default'], ['ONSITE', "'ONSITE'"], true))->toBeTrue();

        // No backfill: ROLLBACK LOSES which punch was from home — both
        // re-read as the default, the home one with its null site.
        foreach ([$home, $office] as $punch) {
            expect(DB::table('attendance_logs')->where('id', $punch->getKey())->value('location_kind'))->toBe('ONSITE');
            expect(AttendanceLog::query()->findOrFail((string) $punch->getKey())->location_kind)->toBe('ONSITE');
        }

        // The rest of the punch is untouched by the drop and the re-add.
        $fresh = AttendanceLog::query()->findOrFail((string) $home->getKey());
        expect($fresh->site_id)->toBeNull()
            ->and($fresh->log_type)->toBe('TIME_IN')
            ->and($fresh->source)->toBe('WEB_GPS')
            ->and($fresh->latitude)->toBe(14.65)
            ->and($fresh->longitude)->toBe(121.05)
            ->and($fresh->accuracy_meters)->toBe(9.0)
            ->and($fresh->is_geofenced)->toBeTrue()
            ->and($fresh->logged_at->format('Y-m-d H:i:s'))->toBe('2026-09-02 00:00:31');
        expect(AttendanceLog::query()->findOrFail((string) $office->getKey())->site_id)->toBe((string) $site->getKey());

        // And the column takes a WFH again once it is back.
        $fresh->forceFill(['location_kind' => 'WFH'])->save();
        expect($fresh->fresh()?->location_kind)->toBe('WFH');
        expect((string) DB::table('attendance_logs')->where('id', $home->getKey())->value('location_kind'))->toBe('WFH');
    } finally {
        $migration->up();
        rlocmigCleanup($seeded);
    }
});

it('runs no DDL on an up() that finds the location_kind column already there', function () {
    $migration = rlocmigColumnMigration();

    try {
        expect(Schema::hasColumn('attendance_logs', 'location_kind'))->toBeTrue();

        expect(rlocmigDdlDuring(static fn () => $migration->up()))->toBe([]);
        expect(Schema::hasColumn('attendance_logs', 'location_kind'))->toBeTrue();
    } finally {
        $migration->up();
    }
});
