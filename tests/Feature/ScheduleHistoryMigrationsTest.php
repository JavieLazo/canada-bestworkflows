<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift as DomainShift;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\LegacyShiftRoster;

/**
 * The dated-schedule migrations (2026_09_30_0003–0008) re-run safely and
 * change no DTR result: every existing schedule row keeps applying to every
 * date, so a schedule reads exactly as it did immediately before them. The
 * baseline marks history as Assumed from the day it runs, with "may have
 * differed" only where the audit trail records a change — never rebuilt
 * from it. A rollback keeps, per employee, only the row in effect that day.
 */
const SHMIG_FILES = [
    '2026_09_30_0003_create_roster_schedule_changes_table.php',
    '2026_09_30_0004_add_effective_from_to_shifts_table.php',
    '2026_09_30_0005_add_effective_from_to_roster_shifts_table.php',
    '2026_09_30_0006_add_effective_from_to_roster_locations_table.php',
    '2026_09_30_0007_extend_roster_day_overrides_to_periods_table.php',
    '2026_09_30_0008_backfill_baselines_in_roster_schedule_changes_table.php',
];

/** @return list<mixed> in run order */
function shmigMigrations(): array
{
    return array_map(static fn (string $file): mixed => require base_path('database/migrations/'.$file), SHMIG_FILES);
}

function shmigEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'SH-'.Str::random(8), 'first_name' => 'Migrated', 'last_name' => 'Employee',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true]);
}

function shmigPattern(string $start, string $end): ShiftType
{
    return ShiftType::query()->create(['code' => Str::random(10), 'name' => 'Mig '.Str::random(6), 'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
        'work_days' => [1, 2, 3, 4, 5], 'grace_minutes' => 0, 'break_minutes' => 0, 'requires_break_punch' => false]);
}

/** A standing shift, a weekday night, a Saturday rest, a Monday WFH, one dated night and one dated place — no dated history. */
function shmigSchedule(EmployeeProfile $employee): void
{
    $night = shmigPattern('22:00:00', '06:00:00');
    Shift::query()->create(['employee_id' => $employee->id, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5], 'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'grace_minutes' => 5, 'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false]);
    RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => 3, 'shift_type_id' => $night->id, 'shift_name' => $night->name, 'is_rest_day' => false,
        'shift_data' => ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'grace_minutes' => 0, 'mode' => 'FIXED', 'work_days' => [3]]]);
    RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => 6, 'shift_type_id' => null, 'shift_name' => 'Rest day', 'is_rest_day' => true,
        'shift_data' => array_replace(DomainShift::restDay()->toArray(), ['shift_type_id' => null])]);
    RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 1, 'kind' => 'WFH', 'site_id' => null]);
    RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-18', 'shift_type_id' => $night->id, 'shift_name' => $night->name,
        'shift_data' => ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'grace_minutes' => 0, 'mode' => 'FIXED']]);
    RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-21', 'location_kind' => 'WFH']);
    foreach ([['2026-09-14T08:10:00', '2026-09-14T17:00:00'], ['2026-09-16T22:00:00', '2026-09-17T06:05:00'], ['2026-09-18T22:30:00', '2026-09-19T06:00:00'], ['2026-09-19T09:00:00', '2026-09-19T12:00:00']] as [$in, $out]) {
        AttendanceLog::query()->create(['employee_id' => $employee->id, 'log_type' => 'TIME_IN', 'source' => 'MANUAL', 'logged_at' => ManilaClock::instant($in)->utc()]);
        AttendanceLog::query()->create(['employee_id' => $employee->id, 'log_type' => 'TIME_OUT', 'source' => 'MANUAL', 'logged_at' => ManilaClock::instant($out)->utc()]);
    }
}

/**
 * The schedule and DTR an employee reads for September.
 *
 * @return array{schedule: array<string, mixed>|null, dtr: string, places: array<string, mixed>}
 */
function shmigReading(EmployeeProfile $employee, LegacyShiftRoster|ShiftRoster $roster): array
{
    $schedule = $roster->scheduleForEmployee($employee->id, '2026-08-30', '2026-10-01');
    $logs = array_values(AttendanceLog::query()->where('employee_id', $employee->id)->get()
        ->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray(['id' => $row->id, 'log_type' => $row->log_type, 'logged_at' => $row->logged_at]))->all());
    $places = [];
    foreach (['2026-09-14', '2026-09-18', '2026-09-21', '2026-09-22'] as $date) {
        $places[$date] = (array) $roster->locationForDate($employee->id, $date);
    }

    return ['schedule' => $schedule?->toArray(), 'dtr' => (string) json_encode(DtrCalculator::compute($logs, $schedule, [], '2026-09-01', '2026-09-30')), 'places' => $places];
}

it('rolls the dated-schedule migrations back and re-applies them twice without moving any DTR result', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-30T10:00:00+08:00'));
    $employee = shmigEmployee();
    shmigSchedule($employee);
    $migrations = shmigMigrations();

    try {
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        // The schema as it stood before: single rows, no history table.
        expect(Schema::hasTable('roster_schedule_changes'))->toBeFalse()
            ->and(Schema::hasColumn('shifts', 'effective_from'))->toBeFalse()
            ->and(Schema::hasColumn('roster_day_overrides', 'end_date'))->toBeFalse()
            ->and(Schema::hasIndex('shifts', 'shifts_employee_unq'))->toBeTrue()
            ->and(Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_unq'))->toBeTrue()
            ->and(Schema::hasIndex('roster_locations', 'rloc_employee_weekday_unq'))->toBeTrue()
            ->and(Schema::hasIndex('roster_day_overrides', 'rdate_employee_date_unq'))->toBeTrue();
        // What the eDTR showed immediately before the migrations.
        $before = shmigReading($employee, new LegacyShiftRoster);

        // Twice: the retry of a run that already finished changes nothing.
        foreach ([...$migrations, ...$migrations] as $migration) {
            $migration->up();
        }

        expect(shmigReading($employee, app(ShiftRoster::class)))->toBe($before)
            ->and(Shift::query()->count())->toBe(1)
            ->and(RosterShift::query()->count())->toBe(2)
            ->and(RosterDayOverride::query()->count())->toBe(2)
            ->and(Schema::hasIndex('shifts', 'shifts_employee_unq'))->toBeFalse()
            ->and(Schema::hasIndex('roster_day_overrides', 'rdate_employee_date_unq'))->toBeFalse();
        $baseline = RosterScheduleChange::query()->where('employee_id', $employee->id)->sole();
        expect($baseline->source)->toBe('BASELINE')
            ->and($baseline->effective_from)->toBeNull()
            ->and($baseline->assumed_before?->format('Y-m-d'))->toBe('2026-09-30')
            ->and($baseline->may_differ_through)->toBeNull();
    } finally {
        foreach ($migrations as $migration) {
            $migration->up();
        }
    }
});

it('marks history Assumed from the day it runs and may-have-differed only where the audit trail records a change', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-30T10:00:00+08:00'));
    $pattern = shmigPattern('08:00:00', '17:00:00');
    [$assigned, $patterned, $dated, $today, $quiet] = [shmigEmployee(), shmigEmployee(), shmigEmployee(), shmigEmployee(), shmigEmployee()];
    Shift::query()->create(['employee_id' => $patterned->id, 'shift_type_id' => $pattern->id, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5], 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0]);
    $audit = static function (string $action, string $entityId, string $utc, ?string $details = null): void {
        DB::table('audit_logs')->insert(['id' => (string) Str::ulid(), 'actor_id' => null, 'actor_name' => 'Migration test', 'action' => $action,
            'entity' => 'shifts', 'entity_id' => $entityId, 'details' => $details, 'created_at' => $utc]);
    };
    $audit('SHIFT_ASSIGNED', $assigned->id, '2026-09-10 01:00:00.000');
    $audit('SHIFT_ASSIGNED', $assigned->id, '2026-09-02 01:00:00.000');
    // 20:00 UTC is 04:00 the next Manila day. Only an edit that re-copied the
    // pattern onto rostered rows is evidence; a later edit whose audit names
    // no re-copy changed nobody's shift.
    $audit('SHIFT_TYPE_UPDATED', $pattern->id, '2026-09-12 20:00:00.000', "Shift pattern {$pattern->code} updated — {$pattern->name} · re-copied onto 1 rostered employee");
    $audit('SHIFT_TYPE_UPDATED', $pattern->id, '2026-09-20 01:00:00.000', "Shift pattern {$pattern->code} updated — {$pattern->name}");
    // One date's change says nothing about any other date.
    $audit('ROSTER_DATE_SET', $dated->id, '2026-09-20 01:00:00.000');
    $audit('ROSTER_LOCATIONS_SET', $today->id, '2026-09-30 01:00:00.000');
    $backfill = shmigMigrations()[5];

    $backfill->down();
    $backfill->up();
    $backfill->up();

    $marks = RosterScheduleChange::query()->where('source', 'BASELINE')->get()->keyBy('employee_id');
    expect($marks)->toHaveCount(5)
        ->and($marks[$assigned->id]->may_differ_through?->format('Y-m-d'))->toBe('2026-09-10')
        ->and($marks[$patterned->id]->may_differ_through?->format('Y-m-d'))->toBe('2026-09-13')
        ->and($marks[$dated->id]->may_differ_through)->toBeNull()
        // A change made earlier on the day it runs could only reach dates before it.
        ->and($marks[$today->id]->may_differ_through?->format('Y-m-d'))->toBe('2026-09-29')
        ->and($marks[$quiet->id]->may_differ_through)->toBeNull();
    foreach ($marks as $mark) {
        expect($mark->assumed_before?->format('Y-m-d'))->toBe('2026-09-30')
            ->and($mark->effective_from)->toBeNull()
            ->and($mark->created_by)->toBeNull();
    }

    // A later run adds only employees still without a baseline.
    $this->travelTo(new DateTimeImmutable('2026-10-02T10:00:00+08:00'));
    $late = shmigEmployee();
    $backfill->up();
    expect(RosterScheduleChange::query()->where('source', 'BASELINE')->count())->toBe(6)
        ->and(RosterScheduleChange::query()->where('employee_id', $late->id)->sole()->assumed_before?->format('Y-m-d'))->toBe('2026-10-02')
        ->and($marks[$assigned->id]->fresh()?->assumed_before?->format('Y-m-d'))->toBe('2026-09-30');
});

it('keeps only the row in effect that day, per employee and weekday, when the history is rolled back', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-30T10:00:00+08:00'));
    $employee = shmigEmployee();
    $day = ['mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5], 'end_time' => '17:00:00', 'grace_minutes' => 0];
    $past = RosterScheduleChange::query()->create(['employee_id' => $employee->id, 'effective_from' => '2026-09-15', 'source' => 'MANUAL']);
    $future = RosterScheduleChange::query()->create(['employee_id' => $employee->id, 'effective_from' => '2026-10-05', 'source' => 'MANUAL']);
    Shift::query()->create(['employee_id' => $employee->id, 'start_time' => '07:00:00'] + $day);
    $current = Shift::query()->create(['employee_id' => $employee->id, 'start_time' => '08:00:00', 'effective_from' => '2026-09-15', 'change_id' => $past->id] + $day);
    Shift::query()->create(['employee_id' => $employee->id, 'start_time' => '09:00:00', 'effective_from' => '2026-10-05', 'change_id' => $future->id] + $day);
    RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 2, 'kind' => 'WFH', 'site_id' => null]);
    RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 2, 'kind' => null, 'site_id' => null, 'effective_from' => '2026-09-15', 'change_id' => $past->id]);
    [, $shifts, , $places] = shmigMigrations();

    try {
        $shifts->down();
        $places->down();
        expect(Shift::query()->where('employee_id', $employee->id)->pluck('id')->all())->toBe([$current->id])
            // Tuesday went back to the Assigned Worksite: no row, as before.
            ->and(RosterLocation::query()->where('employee_id', $employee->id)->count())->toBe(0);
    } finally {
        $shifts->up();
        $places->up();
    }
});

it('stands the three earlier roster migrations down on the finished schema, so a re-run restores no retired unique', function () {
    // 2026_09_26_0001, 2026_09_28_0001 and 2026_09_28_0002 are guarded:
    // each would otherwise re-add its unique whenever it is missing, which
    // the dated chain makes permanent.
    $employee = shmigEmployee();
    // History the old uniques would refuse: two rows for one weekday / date.
    foreach ([null, '2026-10-05'] as $from) {
        RosterLocation::query()->create(['employee_id' => $employee->id, 'weekday' => 1, 'kind' => 'WFH', 'site_id' => null, 'effective_from' => $from]);
        RosterShift::query()->create(['employee_id' => $employee->id, 'weekday' => 1, 'shift_name' => 'Rest day', 'is_rest_day' => true,
            'shift_data' => array_replace(DomainShift::restDay()->toArray(), ['shift_type_id' => null]), 'effective_from' => $from]);
        RosterDayOverride::query()->create(['employee_id' => $employee->id, 'date' => '2026-10-06', 'location_kind' => 'WFH']);
    }

    foreach (['2026_09_26_0001_create_roster_locations_table.php', '2026_09_28_0001_create_roster_shifts_table.php', '2026_09_28_0002_create_roster_day_overrides_table.php'] as $file) {
        $migration = require base_path('database/migrations/'.$file);
        $migration->up();
        $migration->up();
    }

    expect(Schema::hasIndex('roster_locations', 'rloc_employee_weekday_unq'))->toBeFalse()
        ->and(Schema::hasIndex('roster_shifts', 'rshift_employee_weekday_unq'))->toBeFalse()
        ->and(Schema::hasIndex('roster_day_overrides', 'rdate_employee_date_unq'))->toBeFalse()
        ->and(RosterLocation::query()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and(RosterShift::query()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and(RosterDayOverride::query()->where('employee_id', $employee->id)->count())->toBe(2);
});
