<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift as DomainShift;
use App\Models\EmployeeProfile;
use App\Models\RosterDayOverride;
use App\Models\ShiftType;
use App\Models\WorkSite;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** MariaDB enforces date-override shape, snapshot-preserving references and resumable DDL. */
function rdateschemaEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => 'RDS-'.Str::random(8), 'first_name' => 'Date', 'last_name' => 'Schema',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true,
    ]);
}

function rdateschemaPattern(): ShiftType
{
    return ShiftType::query()->create([
        'code' => Str::random(8), 'name' => 'Date '.Str::random(8), 'mode' => 'FIXED',
        'start_time' => '22:00:00', 'end_time' => '06:00:00', 'work_days' => [1],
        'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false,
    ]);
}

function rdateschemaSite(): WorkSite
{
    return WorkSite::query()->create([
        'name' => 'Date office', 'latitude' => 14.6, 'longitude' => 121.0,
        'radius_meters' => 150, 'geofence_exempt' => true,
    ]);
}

function rdateschemaAssert(): void
{
    expect(Schema::hasIndex('roster_day_overrides', 'rdate_employee_date_unq', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('roster_day_overrides', 'rdate_type_idx'))->toBeTrue()
        ->and(Schema::hasIndex('roster_day_overrides', 'rdate_site_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('roster_day_overrides', ['employee_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('roster_day_overrides', ['shift_type_id']))->toBeTrue()
        ->and(Schema::hasForeignKey('roster_day_overrides', ['site_id']))->toBeTrue();
    foreach (['rdate_shift_chk', 'rdate_location_chk', 'rdate_override_chk'] as $name) {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'roster_day_overrides' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);
        expect((int) ($row->n ?? 0))->toBe(1);
    }
}

it('retries date schema creation after the table and after only some checks exist', function () {
    $migration = require base_path('database/migrations/2026_09_28_0002_create_roster_day_overrides_table.php');
    // 2026_09_30_0007 later drops the unique this one adds: roll it back
    // first and re-apply it last, the order production runs them.
    $periods = require base_path('database/migrations/2026_09_30_0007_extend_roster_day_overrides_to_periods_table.php');
    try {
        $periods->down();
        $migration->up();
        $migration->up();
        rdateschemaAssert();
        $migration->down();
        $migration->down();
        Schema::create('roster_day_overrides', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);
            $t->date('date');
            $t->char('shift_type_id', 26)->nullable();
            $t->string('shift_name', 160)->nullable();
            $t->boolean('is_rest_day')->default(false);
            $t->json('shift_data')->nullable();
            $t->string('location_kind', 10)->nullable();
            $t->char('site_id', 26)->nullable();
            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();
        });
        $migration->up();
        $migration->up();
        rdateschemaAssert();
        DB::statement('ALTER TABLE roster_day_overrides DROP CONSTRAINT rdate_location_chk');
        DB::statement('ALTER TABLE roster_day_overrides DROP CONSTRAINT rdate_override_chk');
        $migration->up();
        $migration->up();
        rdateschemaAssert();
    } finally {
        $migration->up();
        $periods->up();
    }
});

it('requires one meaningful date override and enforces nullable location combinations', function () {
    $employee = rdateschemaEmployee();
    $site = rdateschemaSite();
    $base = ['employee_id' => $employee->id, 'date' => '2026-09-22', 'is_rest_day' => false];
    expect(fn () => RosterDayOverride::query()->create($base))->toThrow(QueryException::class, 'rdate_override_chk');
    $shift = ['shift_name' => 'Regular', 'shift_data' => DomainShift::default()->toArray()];
    foreach ([
        ['location_kind' => null, 'site_id' => $site->id],
        ['location_kind' => 'WFH', 'site_id' => $site->id],
        ['location_kind' => 'ONSITE', 'site_id' => null],
        ['location_kind' => 'UNKNOWN', 'site_id' => null],
    ] as $invalid) {
        expect(fn () => RosterDayOverride::query()->create(array_merge($base, $shift, $invalid)))
            ->toThrow(QueryException::class, 'rdate_location_chk');
    }
    $home = RosterDayOverride::query()->create(array_merge($base, ['location_kind' => 'WFH']));
    expect($home->shift_data)->toBeNull();
    // 2026_09_30_0007 keeps a second assignment on the same date as history
    // (the replaced one is cancelled, not deleted).
    RosterDayOverride::query()->create(array_merge($base, $shift));
    RosterDayOverride::query()->create(array_merge($base, $shift, ['date' => '2026-09-23']));
    RosterDayOverride::query()->create(array_merge($base, ['date' => '2026-09-24', 'location_kind' => 'ONSITE', 'site_id' => $site->id]));
    expect(RosterDayOverride::query()->count())->toBe(4);

    // A period ends on or after it begins; a status is one of two; a
    // cancellation records when, and the last date it still applied.
    expect(fn () => RosterDayOverride::query()->create(array_merge($base, $shift, ['date' => '2026-09-25', 'end_date' => '2026-09-24'])))->toThrow(QueryException::class, 'rdate_span_chk');
    expect(fn () => RosterDayOverride::query()->create(array_merge($base, $shift, ['status' => 'PAUSED'])))->toThrow(QueryException::class, 'rdate_status_chk');
    expect(fn () => RosterDayOverride::query()->create(array_merge($base, $shift, ['status' => 'CANCELLED', 'cut_after' => '2026-09-21'])))->toThrow(QueryException::class, 'rdate_cancel_chk');
    expect(fn () => RosterDayOverride::query()->create(array_merge($base, $shift, ['status' => 'CANCELLED', 'cancelled_at' => '2026-09-20 00:00:00.000'])))->toThrow(QueryException::class, 'rdate_cancel_chk');
    expect(fn () => RosterDayOverride::query()->create(array_merge($base, $shift, ['cancelled_at' => '2026-09-20 00:00:00.000'])))->toThrow(QueryException::class, 'rdate_cancel_chk');
    RosterDayOverride::query()->create(array_merge($base, $shift, ['date' => '2026-09-25', 'end_date' => '2026-10-02', 'include_rest_days' => false,
        'status' => 'CANCELLED', 'cut_after' => '2026-09-24', 'cancelled_at' => '2026-09-20 00:00:00.000']));
    expect(RosterDayOverride::query()->count())->toBe(5);
});

it('rejects incomplete copied hours and rest payloads with working days or snapshot pattern links', function () {
    $employee = rdateschemaEmployee();
    $pattern = rdateschemaPattern();
    $base = ['employee_id' => $employee->id, 'date' => '2026-09-22', 'location_kind' => 'WFH'];
    foreach ([
        ['shift_data' => null, 'shift_name' => 'Orphan name'],
        ['shift_data' => null, 'is_rest_day' => true],
        ['shift_data' => DomainShift::default()->toArray(), 'shift_name' => null],
        ['shift_data' => DomainShift::default()->toArray(), 'shift_name' => 'Rest', 'is_rest_day' => true],
        ['shift_data' => ['work_days' => [-1]], 'shift_name' => 'Rest', 'is_rest_day' => true],
        ['shift_data' => array_replace(DomainShift::restDay()->toArray(), ['shift_type_id' => $pattern->id]), 'shift_name' => 'Rest', 'is_rest_day' => true],
    ] as $invalid) {
        expect(fn () => RosterDayOverride::query()->create(array_merge($base, $invalid)))
            ->toThrow(QueryException::class, 'rdate_shift_chk');
    }
    RosterDayOverride::query()->create(array_merge($base, [
        'shift_data' => DomainShift::restDay()->toArray(), 'shift_name' => 'Rest', 'is_rest_day' => true,
    ]));
    expect(RosterDayOverride::query()->count())->toBe(1);
});

it('keeps copied date hours after pattern deletion and restricts site deletion until the employee is removed', function () {
    $employee = rdateschemaEmployee();
    $pattern = rdateschemaPattern();
    $site = rdateschemaSite();
    $row = RosterDayOverride::query()->create([
        'employee_id' => $employee->id, 'date' => '2026-09-22', 'shift_type_id' => $pattern->id,
        'shift_name' => $pattern->name, 'shift_data' => DomainShift::fromArray($pattern->toArray())->toArray(),
        'is_rest_day' => false, 'location_kind' => 'ONSITE', 'site_id' => $site->id,
    ]);
    $before = $row->shift_data;
    $name = $row->shift_name;
    $pattern->delete();
    expect($row->fresh()?->shift_type_id)->toBeNull()
        ->and($row->fresh()?->shift_data)->toBe($before)
        ->and($row->fresh()?->shift_name)->toBe($name);
    expect(fn () => $site->delete())->toThrow(QueryException::class);
    $employee->delete();
    expect(RosterDayOverride::query()->count())->toBe(0);
    $site->delete();
    expect(WorkSite::query()->count())->toBe(0);
});
