<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\EmployeeProfile;
use App\Models\OvertimeApproval;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Timekeeping\Application\Queries\TimekeepingReports;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Attendance comparisons rank canonical numeric facts without widening scope or prematurely counting absences. */

/** @param list<string> $modules */
function attendanceRankingActor(bool $all = true, ?string $employeeId = null, array $modules = [ModuleCode::REPORTS, ModuleCode::EDTR]): Access
{
    $role = Role::create(['name' => 'Attendance ranking '.Str::random(8), 'all_data' => $all, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    return Access::forActor(User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]));
}

function attendanceRankingEmployee(string $code, string $position = 'Engineer', bool $schedule = true): EmployeeProfile
{
    $employee = EmployeeProfile::create([
        'employee_code' => $code, 'employee_id_no' => 'ID-'.$code,
        'first_name' => 'Ana', 'last_name' => $code, 'position' => $position,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ]);
    if ($schedule) {
        Shift::create([
            'employee_id' => $employee->id, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0, 'break_minutes' => 60,
        ]);
    }

    return $employee;
}

function attendanceRankingPunches(EmployeeProfile $employee, string $date, string $in, ?string $out = null): void
{
    foreach (['TIME_IN' => $in, 'TIME_OUT' => $out] as $type => $clock) {
        if ($clock !== null) {
            AttendanceLog::create([
                'employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL',
                'logged_at' => LocalClock::instant($date.'T'.$clock)->utc(),
            ]);
        }
    }
}

/**
 * @param  list<string>  $ids
 * @param  array<string, mixed>  $overrides
 */
function attendanceRankingCriteria(array $ids, array $overrides = []): ReportCriteria
{
    return ReportCriteria::from(array_replace([
        'type' => 'attendance-reports', 'view' => 'summary',
        'from' => '2026-09-28', 'to' => '2026-09-29', 'employeeIds' => $ids,
        'sort' => 'late_minutes', 'direction' => 'desc',
    ], $overrides));
}

/** @return list<array<string, string>> */
function attendanceRankingRows(ReportDocument $report): array
{
    return array_map(static fn (array $row): array => array_combine($report->columns, $row), $report->rows);
}

it('ranks numeric attendance totals and breaks equal metrics consistently by employee', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $alpha = attendanceRankingEmployee('Alpha');
    $beta = attendanceRankingEmployee('Beta');
    $gamma = attendanceRankingEmployee('Gamma');
    attendanceRankingPunches($alpha, '2026-09-28', '08:10:00', '17:00:00');
    attendanceRankingPunches($alpha, '2026-09-29', '08:05:00', '17:00:00');
    foreach ([$beta, $gamma] as $employee) {
        attendanceRankingPunches($employee, '2026-09-28', '10:00:00', '15:00:00');
    }
    $actor = attendanceRankingActor();
    $query = app(TimekeepingReports::class);
    $criteria = attendanceRankingCriteria([$gamma->id, $alpha->id, $beta->id]);
    $report = $query->generate($actor, $criteria);
    $rows = attendanceRankingRows($report);

    expect(array_column($rows, 'Employee'))->toBe(['Ana Beta', 'Ana Gamma', 'Ana Alpha'])
        ->and(array_column($rows, 'Rank'))->toBe(['1', '2', '3'])
        ->and(array_column($rows, 'Late'))->toBe(['2h', '2h', '15m'])
        ->and(array_column($rows, 'Late Days'))->toBe(['1', '1', '2'])
        ->and(array_column($rows, 'Undertime Days'))->toBe(['1', '1', '0'])
        ->and(array_column($rows, 'Undertime'))->toBe(['2h', '2h', '0m'])
        ->and($report->sections)->toBe([])
        ->and($report->columnKeys[5])->toBe('late_minutes')
        ->and($report->columnKeys[7])->toBe('undertime_minutes');
    $ascending = $query->generate($actor, attendanceRankingCriteria([$gamma->id, $alpha->id, $beta->id], ['direction' => 'asc']));
    expect(array_column(attendanceRankingRows($ascending), 'Employee'))->toBe(['Ana Alpha', 'Ana Beta', 'Ana Gamma']);
    expect($query->generate($actor, ReportCriteria::from($criteria->query()))->rows)->toBe($report->rows);
});

it('combines issue search and metric thresholds without changing the selected employee count', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $one = attendanceRankingEmployee('One', 'Engineer');
    $two = attendanceRankingEmployee('Two', 'Analyst');
    attendanceRankingPunches($one, '2026-09-28', '08:10:00', '16:00:00');
    attendanceRankingPunches($two, '2026-09-28', '10:00:00', '15:00:00');
    $actor = attendanceRankingActor();
    $report = app(TimekeepingReports::class)->generate($actor, attendanceRankingCriteria([$one->id, $two->id], [
        'sort' => 'undertime_minutes', 'filters' => ['issue' => 'undertime', 'minimum' => '60', 'search' => 'engineer'],
    ]));
    expect(array_column(attendanceRankingRows($report), 'Employee'))->toBe(['Ana One'])
        ->and(array_column($report->summary, 'value', 'label'))->toMatchArray([
            'Employees Selected' => '2', 'Employees Matching' => '1', 'Employees Shown' => '1', 'Undertime — Shown' => '1h',
        ]);
    $noMatches = app(TimekeepingReports::class)->generate($actor, attendanceRankingCriteria([$one->id, $two->id], [
        'sort' => 'undertime_days', 'filters' => ['minimum' => '2'],
    ]));
    expect($noMatches->rows)->toBe([]);
});

it('limits the ranked display while keeping matched coverage and shown totals explicit', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $ids = [];
    for ($i = 1; $i <= 12; $i++) {
        $ids[] = attendanceRankingEmployee('Rank-'.$i)->id;
    }
    $report = app(TimekeepingReports::class)->generate(attendanceRankingActor(), attendanceRankingCriteria($ids, [
        'sort' => 'absences', 'filters' => ['issue' => 'absent', 'minimum' => '2', 'limit' => '10'],
    ]));
    expect($report->rows)->toHaveCount(10)
        ->and(array_column($report->summary, 'value', 'label'))->toMatchArray([
            'Employees Selected' => '12', 'Employees Matching' => '12', 'Employees Shown' => '10', 'Absences — Shown' => '20',
        ])
        ->and($report->totals[8])->toBe('20')
        ->and(implode(' ', $report->notes))->toContain('Totals cover the employees shown.');
});

it('excludes future dates and delays absence counts until the attendance day closes', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T09:00:00+08:00'));
    $employee = attendanceRankingEmployee('Open', schedule: false);
    attendanceRankingPunches($employee, '2026-10-02', '08:00:00');
    $report = app(TimekeepingReports::class)->generate(attendanceRankingActor(), attendanceRankingCriteria([$employee->id], [
        'from' => '2026-10-02', 'to' => '2026-10-03', 'filters' => ['issue' => 'incomplete'],
    ]));
    $row = attendanceRankingRows($report)[0];
    expect($row['Absences'])->toBe('0')->and($row['Incomplete Days'])->toBe('1')
        ->and($row['Review'])->toBe('1 no schedule; 1 ongoing')
        ->and(implode(' ', $report->notes))->toContain('Future dates are excluded.', 'default hours and need review.');
    $future = app(TimekeepingReports::class)->generate(attendanceRankingActor(), attendanceRankingCriteria([$employee->id], [
        'from' => '2026-10-03', 'to' => '2026-10-03',
    ]));
    expect(attendanceRankingRows($future)[0]['Absences'])->toBe('0')
        ->and(attendanceRankingRows($future)[0]['Incomplete Days'])->toBe('0')
        ->and(attendanceRankingRows($future)[0]['Review'])->toBe('');
});

it('keeps stored approved overtime separate and preserves the individual attendance sheets', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $employee = attendanceRankingEmployee('Overtime');
    attendanceRankingPunches($employee, '2026-09-28', '08:00:00', '18:00:00');
    OvertimeApproval::create([
        'employee_id' => $employee->id, 'date' => '2026-09-28', 'decision' => 'APPROVED',
        'computed_minutes' => 60, 'approved_minutes' => 45,
    ]);
    $actor = attendanceRankingActor();
    $query = app(TimekeepingReports::class);
    $summary = $query->generate($actor, attendanceRankingCriteria([$employee->id]));
    $details = $query->generate($actor, attendanceRankingCriteria([$employee->id], ['view' => 'details']));
    $row = attendanceRankingRows($summary)[0];
    expect($row['OT Computed'])->toBe('1h')->and($row['OT Approved'])->toBe('45m')->and($row['OT Payable'])->toBe('45m')
        ->and($details->sections)->toHaveCount(1)
        ->and($details->sections[0]['rows'][0])->toBe(['2026-09-28', '8:00 AM', '—', '—', '6:00 PM', '8h', '0m', '0m', '1h', '45m', 'PARTIAL', 'PRESENT', ''])
        ->and($details->sections[0]['totals'][5] ?? null)->toBe($row['Regular Time'])
        ->and($details->sections[0]['totals'][9] ?? null)->toBe($row['OT Approved']);
});

it('counts a closed incomplete scheduled day as an absence in comparisons', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $employee = attendanceRankingEmployee('Incomplete');
    attendanceRankingPunches($employee, '2026-09-28', '08:00:00');
    $report = app(TimekeepingReports::class)->generate(attendanceRankingActor(), attendanceRankingCriteria([$employee->id], [
        'to' => '2026-09-28', 'filters' => ['issue' => 'absent'],
    ]));
    $row = attendanceRankingRows($report)[0];
    expect($row['Absences'])->toBe('1')->and($row['Incomplete Days'])->toBe('1')->and($row['Review'])->toBe('');
});

it('reads daily remarks only for individual sheets while preserving their detailed evidence', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $employee = attendanceRankingEmployee('Remarks');
    attendanceRankingPunches($employee, '2026-09-28', '08:15:00', '17:00:00');
    AttendanceRemark::create(['employee_id' => $employee->id, 'date' => '2026-09-28', 'note' => 'Daily attendance evidence']);
    $actor = attendanceRankingActor();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $summary = app(TimekeepingReports::class)->generate($actor, attendanceRankingCriteria([$employee->id]));
        $summaryQueries = array_column(DB::getQueryLog(), 'query');
        DB::flushQueryLog();
        $details = app(TimekeepingReports::class)->generate($actor, attendanceRankingCriteria([$employee->id], ['view' => 'details']));
        $detailQueries = array_column(DB::getQueryLog(), 'query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect(collect($summaryQueries)->contains(static fn (string $query): bool => str_contains($query, 'attendance_remarks')))->toBeFalse()
        ->and(collect($detailQueries)->contains(static fn (string $query): bool => str_contains($query, 'attendance_remarks')))->toBeTrue()
        ->and($summary->sections)->toBe([])
        ->and($details->sections[0]['rows'][0][12])->toBe('Daily attendance evidence')
        ->and(attendanceRankingRows($summary)[0]['Late'])->toBe($details->sections[0]['totals'][6] ?? null);
});

it('refuses foreign selections before reading attendance even when a search would hide them', function (bool $linked) {
    $own = attendanceRankingEmployee('Own');
    $foreign = attendanceRankingEmployee('Foreign');
    $source = Mockery::mock(AttendanceSource::class);
    $source->shouldNotReceive('forDtr');
    app()->instance(AttendanceSource::class, $source);
    $actor = attendanceRankingActor(false, $linked ? $own->id : null);

    expect(fn () => app(TimekeepingReports::class)->generate($actor, attendanceRankingCriteria([$own->id, $foreign->id], [
        'filters' => ['search' => 'Own'],
    ])))->toThrow(HttpException::class);
})->with([true, false]);

it('requires both report and attendance grants for comparisons', function (array $modules) {
    $employee = attendanceRankingEmployee('Permission');
    expect(fn () => app(TimekeepingReports::class)->generate(attendanceRankingActor(modules: array_values($modules)), attendanceRankingCriteria([$employee->id])))
        ->toThrow(HttpException::class);
})->with(['reports only' => [[ModuleCode::REPORTS]], 'attendance only' => [[ModuleCode::EDTR]]]);

it('rejects invalid ranking controls rather than silently widening the report', function (array $overrides) {
    $employee = attendanceRankingEmployee('Validation');
    expect(fn () => app(TimekeepingReports::class)->generate(attendanceRankingActor(), attendanceRankingCriteria([$employee->id], $overrides)))
        ->toThrow(ValidationException::class);
})->with([
    'unknown sort' => [['sort' => 'salary']],
    'unknown issue' => [['filters' => ['issue' => 'unexpected']]],
    'negative minimum' => [['filters' => ['minimum' => '-1']]],
    'unbounded limit' => [['filters' => ['limit' => '1000']]],
    'name threshold' => [['sort' => 'employee', 'filters' => ['minimum' => '1']]],
]);
