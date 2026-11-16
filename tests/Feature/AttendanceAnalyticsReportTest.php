<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\LeaveTravelRequest;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\RosterScheduleChange;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Timekeeping\Application\Queries\TimekeepingReports;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Attendance analytics use observed, closed and scoped opportunities, with complete denominator and exclusion evidence. */

/** @param list<string> $modules */
function attendanceAnalyticsActor(bool $all = true, ?string $employeeId = null, array $modules = [ModuleCode::REPORTS, ModuleCode::EDTR]): Access
{
    $role = Role::create(['name' => 'Attendance analysis '.Str::random(8), 'all_data' => $all, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    return Access::forActor(User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]));
}

/** @param array<string, mixed> $attributes
 * @param  array<string, mixed>|null  $shift
 */
function attendanceAnalyticsEmployee(string $code, array $attributes = [], ?array $shift = []): EmployeeProfile
{
    $employee = EmployeeProfile::create(array_replace([
        'employee_code' => $code, 'employee_id_no' => 'ID-'.$code,
        'first_name' => 'Analysis', 'last_name' => $code, 'position' => 'Engineer',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ], $attributes));
    if ($shift !== null) {
        Shift::create(array_replace([
            'employee_id' => $employee->id, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0, 'break_minutes' => 60,
        ], $shift));
    }

    return $employee;
}

function attendanceAnalyticsPunch(EmployeeProfile $employee, string $date, string $in, ?string $out = '17:00:00'): void
{
    foreach (['TIME_IN' => $in, 'TIME_OUT' => $out] as $type => $clock) {
        if ($clock !== null) {
            AttendanceLog::create(['employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL', 'logged_at' => ManilaClock::instant($date.'T'.$clock)->utc()]);
        }
    }
}

/** @param list<string> $ids
 * @param  array<string, mixed>  $overrides
 */
function attendanceAnalyticsCriteria(array $ids, array $overrides = []): ReportCriteria
{
    return ReportCriteria::from(array_replace([
        'type' => 'attendance-reports', 'view' => 'analysis', 'from' => '2026-09-28', 'to' => '2026-09-29',
        'employeeIds' => $ids, 'filters' => ['groupBy' => 'position', 'compare' => 'none', 'threshold' => '3'],
    ], $overrides));
}

/** @return list<list<string>> */
function attendanceAnalyticsSection(ReportDocument $report, string $title): array
{
    foreach ($report->sections as $section) {
        if ($section['title'] === $title) {
            return $section['rows'];
        }
    }

    throw new RuntimeException('Missing analysis section: '.$title);
}

it('weights grouped rates by qualified opportunities and excludes dates before employment', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $one = attendanceAnalyticsEmployee('One');
    $two = attendanceAnalyticsEmployee('Two', ['date_hired' => '2026-09-29']);
    attendanceAnalyticsPunch($one, '2026-09-28', '08:15:00');
    attendanceAnalyticsPunch($two, '2026-09-29', '08:00:00');
    $criteria = attendanceAnalyticsCriteria([$one->id, $two->id]);
    $report = app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), $criteria);
    $row = array_combine($report->columns, $report->rows[0]);
    expect($row['Qualified Days'])->toBe('3')->and($row['Completed Days'])->toBe('2')
        ->and($row['Completion Rate'])->toBe('66.7% (2/3)')->and($row['Late Rate'])->toBe('50.0% (1/2)')
        ->and($row['Absence Rate'])->toBe('33.3% (1/3)')
        ->and(attendanceAnalyticsSection($report, 'Coverage Excluded from Attendance Rates'))->toContain(['Outside recorded employment', '1', '1'])
        ->and($report->charts[0]['points'])->toBe([['label' => '2026-09-28', 'value' => 15.0], ['label' => '2026-09-29', 'value' => 0.0]])
        ->and($report->charts[1]['points'])->toBe([['label' => 'Engineer', 'value' => 1.0]]);
    $roundTrip = app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), ReportCriteria::from($criteria->query()));
    expect($roundTrip->rows)->toBe($report->rows)->and($roundTrip->sections)->toBe($report->sections);
});

it('keeps flexible attendance out of the lateness denominator while measuring its undertime', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $fixed = attendanceAnalyticsEmployee('Fixed');
    $flex = attendanceAnalyticsEmployee('Flex', shift: ['mode' => 'FLEXIBLE', 'required_hours' => 8, 'break_minutes' => 0]);
    attendanceAnalyticsPunch($fixed, '2026-09-28', '08:10:00');
    attendanceAnalyticsPunch($flex, '2026-09-28', '10:00:00');
    $report = app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), attendanceAnalyticsCriteria([$fixed->id, $flex->id], ['to' => '2026-09-28']));
    $row = array_combine($report->columns, $report->rows[0]);
    expect($row['Completion Rate'])->toBe('100.0% (2/2)')->and($row['Late Rate'])->toBe('100.0% (1/1)')
        ->and($row['Undertime Rate'])->toBe('50.0% (1/2)')->and($row['Undertime'])->toBe('1h');
});

it('separates approved half day paid leave unpaid leave and official business without manufacturing attendance rates', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T09:00:00+08:00'));
    $employee = attendanceAnalyticsEmployee('Leave');
    foreach ([['2026-09-28', 'VACATION', 'HALF_DAY', true], ['2026-09-29', 'SICK', 'WHOLE_DAY', false], ['2026-09-30', 'BUSINESS_TRAVEL', 'WHOLE_DAY', null]] as [$date, $type, $dayType, $paid]) {
        LeaveTravelRequest::create([
            'code' => 'ANL-'.$date, 'employee_id' => $employee->id, 'request_type' => $type,
            'start_date' => $date, 'end_date' => $date, 'day_type' => $dayType,
            'status' => 'APPROVED', 'is_paid' => $paid, 'reason' => 'Private reason excluded from analysis',
        ]);
    }
    Holiday::create(['code' => 'ANL-HOL', 'name' => 'Analysis holiday', 'date' => '2026-10-01', 'schedule' => 'ONE_TIME', 'type' => 'REGULAR', 'percent' => 100]);
    $report = app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), attendanceAnalyticsCriteria([$employee->id], ['to' => '2026-10-03']));
    $row = array_combine($report->columns, $report->rows[0]);
    expect($row['Qualified Days'])->toBe('0')->and($row['Completion Rate'])->toBe('N/A (0 days)')
        ->and($row['Late Rate'])->toBe('N/A (0 days)')->and($row['Absence Days'])->toBe('0');
    expect(attendanceAnalyticsSection($report, 'Approved Leave and Official Business'))->toContain(
        ['Vacation — paid', '0.5', '1'], ['Sick — unpaid', '1', '1'], ['Official business', '1', '1'],
    );
    expect(attendanceAnalyticsSection($report, 'Coverage Excluded from Attendance Rates'))->toContain(
        ['Approved leave', '2', '1'], ['Approved official business', '1', '1'], ['Holiday', '1', '1'], ['Ongoing day', '1', '1'], ['Future date', '1', '1'],
    );
    expect(json_encode($report))->not->toContain('Private reason');
});

it('excludes uncertain and unassigned schedules and dates after recorded separation', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $assumed = attendanceAnalyticsEmployee('Assumed');
    $changed = attendanceAnalyticsEmployee('Changed');
    $none = attendanceAnalyticsEmployee('None', shift: null);
    $left = attendanceAnalyticsEmployee('Left', ['date_resigned' => '2026-09-28', 'is_active' => false]);
    foreach ([[$assumed, null], [$changed, '2026-09-30']] as [$employee, $mayDiffer]) {
        RosterScheduleChange::create(['employee_id' => $employee->id, 'source' => 'BASELINE', 'status' => 'ACTIVE', 'assumed_before' => '2026-10-01', 'may_differ_through' => $mayDiffer]);
    }
    $report = app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), attendanceAnalyticsCriteria([$assumed->id, $changed->id, $none->id, $left->id]));
    $exclusions = attendanceAnalyticsSection($report, 'Coverage Excluded from Attendance Rates');
    expect($exclusions)->toContain(['Assumed schedule history', '2', '1'], ['Schedule history may differ', '2', '1'], ['No assigned schedule', '2', '1'], ['Outside recorded employment', '1', '1'])
        ->and($report->rows[0][1])->toBe('1');
});

it('keeps an overnight day provisional until its scheduled close even after midnight', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-01T02:00:00+08:00'));
    $employee = attendanceAnalyticsEmployee('Night', shift: ['start_time' => '22:00:00', 'end_time' => '06:00:00', 'break_minutes' => 0]);
    $report = app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), attendanceAnalyticsCriteria([$employee->id], ['from' => '2026-09-30', 'to' => '2026-09-30']));
    expect($report->rows[0][1])->toBe('0')
        ->and(attendanceAnalyticsSection($report, 'Coverage Excluded from Attendance Rates'))->toBe([['Ongoing day', '1', '1']]);
});

it('shows exact repeated lateness dates and keeps a closed incomplete day inside the absence numerator', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-03T12:00:00+08:00'));
    $employee = attendanceAnalyticsEmployee('Repeated');
    foreach (['2026-09-28', '2026-09-29', '2026-09-30'] as $date) {
        attendanceAnalyticsPunch($employee, $date, '08:10:00');
    }
    attendanceAnalyticsPunch($employee, '2026-10-01', '08:00:00', null);
    $report = app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), attendanceAnalyticsCriteria([$employee->id], ['to' => '2026-10-01']));
    expect(attendanceAnalyticsSection($report, 'Repeated Lateness — At Least 3 Qualifying Days'))->toBe([
        ['ID-Repeated', 'Analysis Repeated', '3', '3', '30m', '2026-09-28, 2026-09-29, 2026-09-30'],
    ]);
    $row = array_combine($report->columns, $report->rows[0]);
    expect($row['Absence Days'])->toBe('1')->and($row['Incomplete Days'])->toBe('1')->and($row['Absence Rate'])->toBe('25.0% (1/4)');
});

it('compares the same selected cohort over equal preceding calendar periods with explicit rate changes', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-03T12:00:00+08:00'));
    $employee = attendanceAnalyticsEmployee('Compare');
    attendanceAnalyticsEmployee('NotSelected');
    attendanceAnalyticsPunch($employee, '2026-09-28', '08:00:00');
    attendanceAnalyticsPunch($employee, '2026-09-29', '08:00:00');
    attendanceAnalyticsPunch($employee, '2026-09-30', '08:10:00');
    $report = app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), attendanceAnalyticsCriteria([$employee->id], [
        'from' => '2026-09-30', 'to' => '2026-10-01', 'filters' => ['groupBy' => 'position', 'compare' => 'previous', 'threshold' => '3'],
    ]));
    expect(attendanceAnalyticsSection($report, 'Previous-Period Comparison'))->toBe([
        ['Engineer', '2', '2', '50.0% (1/2)', '100.0% (2/2)', '-50.0 pp', '100.0% (1/1)', '0.0% (0/2)', '+100.0 pp', '50.0% (1/2)', '0.0% (0/2)', '+50.0 pp'],
    ]);
    expect(json_encode($report))->toContain('2026-09-28 through 2026-09-29')->not->toContain('NotSelected');
});

it('limits exception examples explicitly while keeping every qualified absence in the aggregates', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-03T12:00:00+08:00'));
    $ids = [];
    for ($i = 1; $i <= 10; $i++) {
        $ids[] = attendanceAnalyticsEmployee('Cap-'.$i)->id;
    }
    $report = app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), attendanceAnalyticsCriteria($ids, ['from' => '2026-09-01', 'to' => '2026-09-30']));
    $row = array_combine($report->columns, $report->rows[0]);
    expect($row['Qualified Days'])->toBe('220')->and($row['Absence Days'])->toBe('220')
        ->and(attendanceAnalyticsSection($report, 'Attendance Exceptions to Review'))->toHaveCount(200)
        ->and(json_encode($report))->toContain('Showing 200 of 220 employee-days with exceptions');
});

it('bounds the combined comparison workload before any selected employee or attendance reads', function () {
    $directory = Mockery::mock(OperationalDirectory::class);
    $directory->shouldNotReceive('list');
    app()->instance(OperationalDirectory::class, $directory);
    $ids = array_map(static fn (int $i): string => 'ANL-'.$i, range(1, 81));
    $criteria = attendanceAnalyticsCriteria($ids, ['from' => '2027-01-01', 'to' => '2027-01-31', 'filters' => ['compare' => 'previous']]);
    expect(fn () => app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), $criteria))->toThrow(ValidationException::class, 'exceeds 5,000');
});

it('refuses foreign analysis selections before loading attendance even with broad unrelated grants', function () {
    $own = attendanceAnalyticsEmployee('Own');
    $foreign = attendanceAnalyticsEmployee('Foreign');
    $source = Mockery::mock(AttendanceSource::class);
    $source->shouldNotReceive('forDtr');
    app()->instance(AttendanceSource::class, $source);
    $actor = attendanceAnalyticsActor(false, $own->id, [ModuleCode::REPORTS, ModuleCode::EDTR, ModuleCode::EMPLOYEES]);
    expect(fn () => app(TimekeepingReports::class)->generate($actor, attendanceAnalyticsCriteria([$own->id, $foreign->id])))->toThrow(HttpException::class);
});

it('rejects invalid analytics controls and hidden comparison filters', function (array $filters) {
    $employee = attendanceAnalyticsEmployee('Invalid');
    expect(fn () => app(TimekeepingReports::class)->generate(attendanceAnalyticsActor(), attendanceAnalyticsCriteria([$employee->id], ['filters' => $filters])))
        ->toThrow(ValidationException::class);
})->with([
    'zero threshold' => [['threshold' => '0']],
    'fractional threshold' => [['threshold' => '1.5']],
    'unknown grouping' => [['groupBy' => 'salary']],
    'unknown comparison' => [['compare' => 'year']],
    'hidden ranking issue' => [['issue' => 'late']],
]);
