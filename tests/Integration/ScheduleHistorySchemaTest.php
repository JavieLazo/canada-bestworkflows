<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\WorkSite;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MariaDB 11.4 proves the dated-schedule chain (2026_09_30_0003–0008) the way
 * production runs it from a cron: every step resumes after a kill, a
 * finished run repeats without DDL, and the CHECKs sqlite never sees hold.
 */
/** @return list<string> */
function schistFiles(): array
{
    return [
        '2026_09_30_0003_create_roster_schedule_changes_table.php',
        '2026_09_30_0004_add_effective_from_to_shifts_table.php',
        '2026_09_30_0005_add_effective_from_to_roster_shifts_table.php',
        '2026_09_30_0006_add_effective_from_to_roster_locations_table.php',
        '2026_09_30_0007_extend_roster_day_overrides_to_periods_table.php',
        '2026_09_30_0008_backfill_baselines_in_roster_schedule_changes_table.php',
    ];
}

function schistMigration(int $i): mixed
{
    return require base_path('database/migrations/'.schistFiles()[$i]);
}

/** @return list<string> the CREATE/ALTER/DROP statements `$callback` sent */
function schistDdlDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => preg_match('/^\s*(create|alter|drop)\b/i', $sql) === 1));
}

function schistChecks(string $table, string $name): int
{
    $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$table, $name]);

    return (int) ($row->n ?? 0);
}

function schistAssertApplied(): void
{
    foreach (['rsc_source_chk', 'rsc_status_chk', 'rsc_baseline_chk', 'rsc_cancel_chk'] as $check) {
        expect(schistChecks('roster_schedule_changes', $check))->toBe(1);
    }
    expect(schistChecks('roster_shifts', 'rshift_default_chk'))->toBe(1)
        ->and(schistChecks('roster_locations', 'rloc_default_chk'))->toBe(1);
    foreach (['rdate_status_chk', 'rdate_span_chk', 'rdate_cancel_chk', 'rdate_rest_days_chk'] as $check) {
        expect(schistChecks('roster_day_overrides', $check))->toBe(1);
    }
    foreach (['shifts' => 'shifts_employee_unq', 'roster_shifts' => 'rshift_employee_weekday_unq', 'roster_locations' => 'rloc_employee_weekday_unq', 'roster_day_overrides' => 'rdate_employee_date_unq'] as $table => $unique) {
        expect(Schema::hasIndex($table, $unique))->toBeFalse()
            ->and(Schema::hasForeignKey($table, ['employee_id']))->toBeTrue();
    }
    foreach (['shifts', 'roster_shifts', 'roster_locations'] as $table) {
        expect(Schema::hasColumn($table, 'effective_from'))->toBeTrue()
            ->and(Schema::hasForeignKey($table, ['change_id']))->toBeTrue();
    }
}

function schistEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'SCH-'.Str::random(8), 'first_name' => 'Schema', 'last_name' => 'History', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true]);
}

it('repeats the finished chain with no DDL but the ROW_FORMAT restatement, and rolls back and forth twice', function () {
    schistAssertApplied();
    $migrations = array_map(schistMigration(...), array_keys(schistFiles()));

    $ddl = schistDdlDuring(static function () use ($migrations): void {
        foreach ($migrations as $migration) {
            $migration->up();
        }
    });
    expect($ddl)->toBe(['ALTER TABLE `roster_schedule_changes` ROW_FORMAT=DYNAMIC']);

    try {
        foreach ([1, 2] as $round) {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            expect(Schema::hasTable('roster_schedule_changes'))->toBeFalse()
                ->and(Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_unq'))->toBeTrue()
                ->and(schistChecks('roster_shifts', 'rshift_default_chk'))->toBe(0);
            foreach ($migrations as $migration) {
                $migration->up();
            }
            schistAssertApplied();
        }
    } finally {
        foreach ($migrations as $migration) {
            $migration->up();
        }
    }
});

it('resumes each versioning migration from a run killed part-way', function () {
    $migrations = array_map(schistMigration(...), array_keys(schistFiles()));
    try {
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        $migrations[0]->up();
        // Killed after the create, before any index, key or CHECK.
        Schema::drop('roster_schedule_changes');
        Schema::create('roster_schedule_changes', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);
            $t->date('effective_from')->nullable();
            $t->string('source', 16);
            $t->string('status', 12)->default('ACTIVE');
            $t->date('assumed_before')->nullable();
            $t->date('may_differ_through')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();
        });
        $migrations[0]->up();
        // roster_shifts killed after its columns and first index, before the
        // unique drop and the nullability changes.
        Schema::table('roster_shifts', static fn (Blueprint $t) => $t->date('effective_from')->nullable());
        Schema::table('roster_shifts', static fn (Blueprint $t) => $t->char('change_id', 26)->nullable());
        Schema::table('roster_shifts', static fn (Blueprint $t) => $t->index(['employee_id', 'weekday', 'effective_from'], 'rshift_employee_weekday_eff_idx'));
        // roster_day_overrides killed after two of its columns.
        Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->date('end_date')->nullable());
        Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->string('status', 12)->default('ACTIVE'));
        foreach ($migrations as $migration) {
            $migration->up();
            $migration->up();
        }
        schistAssertApplied();
    } finally {
        foreach ($migrations as $migration) {
            $migration->up();
        }
    }
});

it('holds the history CHECKs sqlite never sees', function () {
    $employee = schistEmployee();
    $change = ['employee_id' => $employee->id, 'source' => 'MANUAL', 'effective_from' => '2026-10-01'];
    expect(fn () => RosterScheduleChange::query()->create(array_replace($change, ['source' => 'GUESS'])))->toThrow(QueryException::class, 'rsc_source_chk')
        ->and(fn () => RosterScheduleChange::query()->create(array_replace($change, ['status' => 'PAUSED'])))->toThrow(QueryException::class, 'rsc_status_chk')
        ->and(fn () => RosterScheduleChange::query()->create(array_replace($change, ['effective_from' => null])))->toThrow(QueryException::class, 'rsc_baseline_chk')
        ->and(fn () => RosterScheduleChange::query()->create(array_replace($change, ['source' => 'BASELINE'])))->toThrow(QueryException::class, 'rsc_baseline_chk')
        ->and(fn () => RosterScheduleChange::query()->create($change + ['assumed_before' => '2026-09-30']))->toThrow(QueryException::class, 'rsc_baseline_chk')
        ->and(fn () => RosterScheduleChange::query()->create($change + ['status' => 'CANCELLED']))->toThrow(QueryException::class, 'rsc_cancel_chk');
    $baseline = RosterScheduleChange::query()->create(['employee_id' => $employee->id, 'source' => 'BASELINE', 'assumed_before' => '2026-09-30']);
    expect(fn () => $baseline->forceFill(['status' => 'CANCELLED', 'cancelled_at' => '2026-10-01 00:00:00.000'])->save())->toThrow(QueryException::class, 'rsc_cancel_chk');
    (new RosterScheduleChange)->forceFill($change + ['status' => 'CANCELLED', 'cancelled_at' => '2026-09-30 00:00:00.000'])->save();

    // Weekday rows go back to the default (no hours) or to the Assigned
    // Worksite (no kind, no site) — and nothing in between.
    RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => 2, 'shift_name' => null, 'is_rest_day' => false, 'shift_data' => null, 'effective_from' => '2026-10-01']);
    RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 2, 'kind' => null, 'site_id' => null, 'effective_from' => '2026-10-01']);
    $site = WorkSite::query()->create(['name' => 'History site', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 150, 'geofence_exempt' => false]);
    expect(fn () => RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 3, 'kind' => null, 'site_id' => $site->id]))->toThrow(QueryException::class, 'rloc_default_chk');
    expect(RosterScheduleChange::query()->count())->toBe(2)
        ->and(RosterShift::query()->whereNull('shift_data')->count())->toBe(1)
        ->and(RosterLocation::query()->whereNull('kind')->count())->toBe(1);
});

it('re-runs the three earlier roster migrations on the finished schema without restoring a retired unique', function () {
    schistAssertApplied();
    $employee = schistEmployee();
    foreach ([null, '2026-10-05'] as $from) {
        RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => 1, 'shift_name' => null, 'is_rest_day' => false, 'shift_data' => null, 'effective_from' => $from]);
        RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 1, 'kind' => 'WFH', 'site_id' => null, 'effective_from' => $from]);
    }
    $ddl = schistDdlDuring(static function (): void {
        foreach (['2026_09_26_0001_create_roster_locations_table.php', '2026_09_28_0001_create_roster_shifts_table.php', '2026_09_28_0002_create_roster_day_overrides_table.php'] as $file) {
            $migration = require base_path('database/migrations/'.$file);
            $migration->up();
        }
    });

    // Only the idempotent ROW_FORMAT restatements; no index is added.
    expect($ddl)->toBe([
        'ALTER TABLE `roster_locations` ROW_FORMAT=DYNAMIC',
        'ALTER TABLE `roster_shifts` ROW_FORMAT=DYNAMIC',
        'ALTER TABLE `roster_day_overrides` ROW_FORMAT=DYNAMIC',
    ]);
    schistAssertApplied();
});

it('adds work_sites.is_active instantly, keeps every site active, and repeats without DDL', function () {
    $migration = require base_path('database/migrations/2026_09_30_0009_add_is_active_to_work_sites_table.php');
    $site = WorkSite::query()->create(['name' => 'Status site', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 150, 'geofence_exempt' => false]);
    try {
        expect(schistDdlDuring(static fn () => $migration->up()))->toBe([]);

        $migration->down();
        expect(Schema::hasColumn('work_sites', 'is_active'))->toBeFalse()
            ->and(schistDdlDuring(static fn () => $migration->down()))->toBe([]);

        $ddl = schistDdlDuring(static fn () => $migration->up());
        expect($ddl)->toHaveCount(1)->and($ddl[0])->toContain('`is_active`');
        expect((int) DB::table('work_sites')->where('id', $site->id)->value('is_active'))->toBe(1);

        // The same statement with ALGORITHM=INSTANT is refused outright where
        // the engine would have to copy the table.
        $migration->down();
        DB::statement($ddl[0].', ALGORITHM=INSTANT');
        expect(Schema::hasColumn('work_sites', 'is_active'))->toBeTrue();
    } finally {
        $migration->up();
    }
});
