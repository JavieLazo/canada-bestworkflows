<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\OvertimeApproval;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Timekeeping\Application\Queries\TimekeepingReports;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Workforce\Application\Queries\EmployeeReport;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Reports\ReportCriteria;
use App\Support\Access;
use App\Support\Csv;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Reports require both grants, retain employee scope and expose canonical attendance and leave facts. */

/** @param list<string> $modules */
function peopleReportsActor(array $modules, bool $allData = false, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Reports '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);

    return Access::forActor($user);
}

/** @param array<string, mixed> $overrides */
function peopleReportsEmployee(string $code, array $overrides = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => $code, 'employee_id_no' => 'EMP-'.$code, 'first_name' => 'Ana', 'last_name' => $code,
        'employment_status' => 'REGULAR', 'date_hired' => '2024-01-01', 'is_active' => true,
    ], $overrides))->save();

    return $employee;
}

/** @param array<string, mixed> $overrides */
function peopleReportsCriteria(string $type, array $overrides = []): ReportCriteria
{
    return ReportCriteria::from(array_merge(['type' => $type, 'from' => '2026-09-28', 'to' => '2026-09-29'], $overrides));
}

/** @param array<string, mixed> $overrides */
function peopleReportsLeave(EmployeeProfile $employee, string $code, array $overrides = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => $code, 'employee_id' => $employee->id, 'request_type' => 'SICK',
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-09-28', 'end_date' => '2026-09-29',
        'status' => 'APPROVED', 'is_paid' => true, 'reason' => 'Private health detail',
    ], $overrides))->save();

    return $request;
}

function peopleReportsForbidden(Closure $operation): void
{
    try {
        $operation();
        test()->fail('The report query should refuse this actor.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
}

it('requires reports and the source grant when a people report is called directly', function (string $type, string $source, bool $withReports) {
    $actor = peopleReportsActor([$withReports ? ModuleCode::REPORTS : $source], true);
    $query = $type === 'employee-list' ? app(EmployeeReport::class) : app(TimekeepingReports::class);

    peopleReportsForbidden(fn () => $query->generate($actor, peopleReportsCriteria($type)));
})->with([
    'employee without source' => ['employee-list', ModuleCode::EMPLOYEES, true],
    'employee without reports' => ['employee-list', ModuleCode::EMPLOYEES, false],
    'leave without source' => ['leave-reports', ModuleCode::LEAVES, true],
    'leave without reports' => ['leave-reports', ModuleCode::LEAVES, false],
    'attendance without source' => ['attendance-reports', ModuleCode::EDTR, true],
    'attendance without reports' => ['attendance-reports', ModuleCode::EDTR, false],
]);

it('limits employee reports to current employment fields and the actors own record', function () {
    $own = peopleReportsEmployee('OWN', ['suffix' => 'Jr.', 'position' => 'Engineer', 'monthly_rate' => 54321.12, 'sss_no' => 'private-number']);
    $other = peopleReportsEmployee('OTHER');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES], false, $own->id);
    $document = app(EmployeeReport::class)->generate($actor, peopleReportsCriteria('employee-list'));

    expect($document->rows)->toHaveCount(1)
        ->and($document->rows[0])->toBe(['EMP-OWN', 'Ana OWN Jr.', 'Engineer', 'Regular', 'Active', '2024-01-01', ''])
        ->and($document->columns)->not->toContain('Salary', 'SSS number')
        ->and(array_column($document->summary, 'value', 'label'))->toBe(['Employees' => '1', 'Active' => '1', 'Inactive' => '0', 'Positions represented' => '1'])
        ->and(app(EmployeeReport::class)->generate($actor, peopleReportsCriteria('employee-list', ['employeeId' => $other->id]))->rows)->toBe([]);
});

it('returns no employee or leave rows to an unlinked scoped reports actor', function () {
    $employee = peopleReportsEmployee('EMP-1');
    peopleReportsLeave($employee, 'LEAVE-1');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES, ModuleCode::LEAVES]);

    expect(app(EmployeeReport::class)->generate($actor, peopleReportsCriteria('employee-list'))->rows)->toBe([])
        ->and(app(TimekeepingReports::class)->generate($actor, peopleReportsCriteria('leave-reports'))->rows)->toBe([]);
});

it('lets an unlinked all-data employee reader generate the permitted employee list', function () {
    peopleReportsEmployee('EMP-1');
    peopleReportsEmployee('EMP-2');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES], true);

    expect(app(EmployeeReport::class)->generate($actor, peopleReportsCriteria('employee-list'))->rows)->toHaveCount(2);
});

it('filters employee and leave reports to the selected employees before exporting the same criteria', function (string $type) {
    $one = peopleReportsEmployee('SELECTED-1');
    $two = peopleReportsEmployee('SELECTED-2');
    $three = peopleReportsEmployee('EXCLUDED-3');
    foreach ([$one, $two, $three] as $employee) {
        peopleReportsLeave($employee, 'LEAVE-'.$employee->employee_code);
    }
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES, ModuleCode::LEAVES], true);
    $criteria = peopleReportsCriteria($type, ['employeeIds' => [$one->id, $two->id]]);
    $query = $type === 'employee-list' ? app(EmployeeReport::class) : app(TimekeepingReports::class);
    $document = $query->generate($actor, $criteria);
    $download = $query->generate($actor, ReportCriteria::from($criteria->query()));

    expect($document->rows)->toHaveCount(2)
        ->and(array_column($document->rows, $type === 'employee-list' ? 0 : 1))->toBe(['EMP-SELECTED-1', 'EMP-SELECTED-2'])
        ->and($download->rows)->toBe($document->rows);
})->with(['employee-list', 'leave-reports']);

it('retains source row scope when employee and leave filters mix own and foreign employees', function (string $type) {
    $own = peopleReportsEmployee('OWN');
    $foreign = peopleReportsEmployee('FOREIGN');
    foreach ([$own, $foreign] as $employee) {
        peopleReportsLeave($employee, 'LEAVE-'.$employee->employee_code);
    }
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES, ModuleCode::LEAVES], false, $own->id);
    $query = $type === 'employee-list' ? app(EmployeeReport::class) : app(TimekeepingReports::class);
    $document = $query->generate($actor, peopleReportsCriteria($type, ['employeeIds' => [$own->id, $foreign->id]]));

    expect($document->rows)->toHaveCount(1)
        ->and($document->rows[0][$type === 'employee-list' ? 0 : 1])->toBe('EMP-OWN');
})->with(['employee-list', 'leave-reports']);

it('shows assigned employee IDs without substituting row IDs in the preview query and downloaded CSV', function (string $type) {
    $assigned = peopleReportsEmployee('42', ['employee_id_no' => '2024-118', 'last_name' => 'Alpha']);
    $unassigned = peopleReportsEmployee('43', ['employee_id_no' => null, 'last_name' => 'Beta']);
    peopleReportsLeave($assigned, 'A-LEAVE');
    peopleReportsLeave($unassigned, 'B-LEAVE');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EMPLOYEES, ModuleCode::LEAVES], true);
    $criteria = peopleReportsCriteria($type, ['employeeIds' => [$assigned->id, $unassigned->id]]);
    $query = $type === 'employee-list' ? app(EmployeeReport::class) : app(TimekeepingReports::class);
    $document = $query->generate($actor, $criteria);
    $identityColumn = $type === 'employee-list' ? 0 : 1;

    expect($document->columns[$identityColumn])->toBe('Employee ID')
        ->and(array_column($document->rows, $identityColumn))->toBe(['2024-118', '—'])
        ->and($query->generate($actor, ReportCriteria::from($criteria->query()))->rows)->toBe($document->rows);

    $this->actingAs(User::query()->whereKey($actor->userId)->firstOrFail());
    app()->forgetInstance(Access::class);
    $csv = $this->get(route('reports.export', $criteria->query()))->assertOk()->assertDownload()->streamedContent();
    $rows = array_map(
        static fn (string $line): array => array_map('strval', str_getcsv($line, ',', '"', '')),
        explode("\r\n", substr($csv, strlen(Csv::BOM))),
    );

    expect($rows)->toBe([$document->columns, ...$document->rows]);
})->with(['employee-list', 'leave-reports']);

it('counts only the overlapping calendar days in leave reports without exposing reasons', function () {
    $own = peopleReportsEmployee('OWN');
    $other = peopleReportsEmployee('OTHER');
    peopleReportsLeave($own, 'HALF-1', ['start_date' => '2026-09-27', 'end_date' => '2026-09-30', 'day_type' => 'HALF_DAY']);
    peopleReportsLeave($own, 'BEFORE', ['start_date' => '2026-09-20', 'end_date' => '2026-09-27']);
    peopleReportsLeave($other, 'FOREIGN');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::LEAVES], false, $own->id);
    $document = app(TimekeepingReports::class)->generate($actor, peopleReportsCriteria('leave-reports'));

    expect($document->rows)->toHaveCount(1)
        ->and($document->rows[0])->toBe(['HALF-1', 'EMP-OWN', 'Ana OWN', 'Sick Leave', '2026-09-27', '2026-09-30', 'Half day', '1', 'APPROVED', 'Paid', ''])
        ->and(app(TimekeepingReports::class)->generate($actor, peopleReportsCriteria('leave-reports', ['employeeId' => $other->id]))->rows)->toBe([]);
});

it('distinguishes approved unpaid leave and official business from pending requests', function () {
    $employee = peopleReportsEmployee('EMP-1');
    peopleReportsLeave($employee, 'A-UNPAID', ['is_paid' => false]);
    peopleReportsLeave($employee, 'B-OB', ['request_type' => 'BUSINESS_TRAVEL', 'is_paid' => null]);
    peopleReportsLeave($employee, 'C-PENDING', ['status' => 'PENDING', 'is_paid' => null]);
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::LEAVES], true);
    $document = app(TimekeepingReports::class)->generate($actor, peopleReportsCriteria('leave-reports'));

    expect(array_column($document->rows, 9))->toBe(['Unpaid', 'Not applicable', 'Not decided'])
        ->and(array_column($document->summary, 'value', 'label'))->toBe(['Requests' => '3', 'Employees' => '1', 'Approved leave days' => '2', 'Pending requests' => '1'])
        ->and($document->totals[7])->toBe('6');
});

it('refuses a foreign attendance employee and every employee for an unlinked scoped actor', function (bool $linked) {
    $own = peopleReportsEmployee('OWN');
    $other = peopleReportsEmployee('OTHER');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EDTR], false, $linked ? $own->id : null);

    peopleReportsForbidden(fn () => app(TimekeepingReports::class)->generate($actor, peopleReportsCriteria('attendance-reports', ['employeeId' => $other->id])));
})->with(['linked' => true, 'unlinked' => false]);

it('requires selected employees and a period of at most 31 days for attendance', function (array $overrides) {
    $employee = peopleReportsEmployee('EMP-1');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EDTR], true);
    $criteria = peopleReportsCriteria('attendance-reports', array_merge(['employeeId' => $employee->id], $overrides));

    expect(fn () => app(TimekeepingReports::class)->generate($actor, $criteria))->toThrow(ValidationException::class);
})->with([
    'missing employee' => [['employeeId' => '']],
    '32 days' => [['from' => '2026-08-01', 'to' => '2026-09-01']],
]);

it('reports canonical attendance with separate computed and approved overtime', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $employee = peopleReportsEmployee('EMP-1');
    Shift::query()->create([
        'employee_id' => $employee->id, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60,
        'requires_break_punch' => true,
    ]);
    foreach ([['TIME_IN', '08:00:00'], ['TIME_OUT', '12:00:00'], ['TIME_IN', '13:00:00'], ['TIME_OUT', '18:00:00']] as [$type, $clock]) {
        AttendanceLog::query()->create([
            'employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL',
            'logged_at' => ManilaClock::instant('2026-09-28T'.$clock)->utc(),
        ]);
    }
    OvertimeApproval::query()->create([
        'employee_id' => $employee->id, 'date' => '2026-09-28', 'decision' => 'APPROVED',
        'computed_minutes' => 60, 'approved_minutes' => 45,
    ]);
    AttendanceRemark::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-28', 'note' => 'Verified punches']);
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EDTR], false, $employee->id);
    $document = app(TimekeepingReports::class)->generate($actor, peopleReportsCriteria('attendance-reports', ['employeeId' => $employee->id]));

    expect($document->rows)->toHaveCount(2)
        ->and($document->sections)->toHaveCount(1)
        ->and($document->sections[0]['rows'][0])->toBe(['2026-09-28', '8:00 AM', '12:00 PM', '1:00 PM', '6:00 PM', '8h', '0m', '0m', '1h', '45m', 'PARTIAL', 'PRESENT', 'Verified punches'])
        ->and(array_column($document->sections[0]['highlights'] ?? [], 'value', 'label'))->toBe([
            'Regular time' => '8h', 'Late' => '0m', 'Undertime' => '0m', 'Computed overtime' => '1h', 'Payable overtime' => '45m', 'Absence days' => '1',
        ])
        ->and($document->sections[0]['totals'] ?? [])->toBe(['Totals', '', '', '', '', '8h', '0m', '0m', '1h', '45m', '', '', ''])
        ->and(implode(' ', $document->sections[0]['notes'] ?? []))->toContain('absences 1.', 'Current payable OT is 45m');
});

it('omits future attendance and does not count an ongoing day as an absence', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T08:00:00+08:00'));
    $employee = peopleReportsEmployee('EMP-1');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EDTR], true);
    $criteria = peopleReportsCriteria('attendance-reports', ['employeeId' => $employee->id, 'from' => '2026-10-02', 'to' => '2026-10-03']);
    $document = app(TimekeepingReports::class)->generate($actor, $criteria);

    expect($document->rows)->toHaveCount(1)
        ->and($document->sections[0]['rows'][0][0])->toBe('2026-10-02')
        ->and(implode(' ', $document->sections[0]['notes'] ?? []))->toContain('absences 0.', '1 day(s) have no assigned schedule');
});

it('prints separate attendance tables and totals for every selected employee', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $one = peopleReportsEmployee('SELECTED-1', ['employee_id_no' => 'ID-1', 'position' => 'Engineer']);
    $two = peopleReportsEmployee('SELECTED-2', ['employee_id_no' => 'ID-2', 'position' => 'Analyst']);
    peopleReportsEmployee('EXCLUDED-3');
    peopleReportsLeave($one, 'LEAVE-1');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EDTR], true);
    $document = app(TimekeepingReports::class)->generate($actor, peopleReportsCriteria('attendance-reports', ['employeeIds' => [$one->id, $two->id]]));

    expect($document->rows)->toHaveCount(4)
        ->and(array_column($document->rows, 0))->toBe(['ID-1', 'ID-1', 'ID-2', 'ID-2'])
        ->and(array_column($document->sections, 'title'))->toBe(['ID-1 — Ana SELECTED-1', 'ID-2 — Ana SELECTED-2'])
        ->and($document->sections[0]['rows'])->toHaveCount(2)
        ->and($document->sections[1]['rows'])->toHaveCount(2)
        ->and(array_column($document->summary, 'value', 'label'))->toBe(['Employees' => '2', 'Employee-days shown' => '4', 'Days in selected period' => '2'])
        ->and(implode(' ', $document->sections[0]['notes'] ?? []))->toContain('Position: Engineer.', 'absences 0.')
        ->and(implode(' ', $document->sections[1]['notes'] ?? []))->toContain('Position: Analyst.', 'absences 2.');
});

it('rejects an attendance batch containing a foreign employee before reading any attendance', function () {
    $own = peopleReportsEmployee('OWN');
    $other = peopleReportsEmployee('OTHER');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EDTR], false, $own->id);
    $source = Mockery::mock(AttendanceSource::class);
    $source->shouldNotReceive('forDtr');
    app()->instance(AttendanceSource::class, $source);

    peopleReportsForbidden(fn () => app(TimekeepingReports::class)->generate($actor, peopleReportsCriteria('attendance-reports', ['employeeIds' => [$own->id, $other->id]])));
});

it('rejects an unavailable attendance employee before calculating the available employee', function () {
    $employee = peopleReportsEmployee('AVAILABLE');
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EDTR], true);
    $source = Mockery::mock(AttendanceSource::class);
    $source->shouldNotReceive('forDtr');
    app()->instance(AttendanceSource::class, $source);

    expect(fn () => app(TimekeepingReports::class)->generate($actor, peopleReportsCriteria('attendance-reports', ['employeeIds' => [$employee->id, 'unavailable']])))->toThrow(ValidationException::class, 'unavailable');
});

it('bounds the entire attendance employee-day request before loading its sources including future periods', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EDTR], true);
    $ids = array_map(static fn (int $number): string => 'EMP-'.$number, range(1, 162));
    $directory = Mockery::mock(OperationalDirectory::class);
    $directory->shouldNotReceive('list');
    app()->instance(OperationalDirectory::class, $directory);
    $criteria = peopleReportsCriteria('attendance-reports', ['employeeIds' => $ids, 'from' => '2027-01-01', 'to' => '2027-01-31']);

    expect(fn () => app(TimekeepingReports::class)->generate($actor, $criteria))->toThrow(ValidationException::class, 'exceeds 5,000');
});

it('allows exactly 5000 requested attendance employee-days without classifying future dates', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-02T12:00:00+08:00'));
    $actor = peopleReportsActor([ModuleCode::REPORTS, ModuleCode::EDTR], true);
    // Pin the workload boundary independently of the chooser's selection cap: 200 × 25 = 5,000.
    $ids = array_map(static fn (int $number): string => 'EMP-'.$number, range(1, 200));
    $employees = collect($ids)->map(static fn (string $id): DirectoryEmployee => new DirectoryEmployee(['id' => $id, 'first_name' => 'Ana', 'last_name' => $id]));
    $directory = Mockery::mock(OperationalDirectory::class);
    $directory->shouldReceive('list')->once()->with($actor, $ids)->andReturn($employees);
    app()->instance(OperationalDirectory::class, $directory);
    $source = Mockery::mock(AttendanceSource::class);
    $source->shouldNotReceive('forDtr');
    app()->instance(AttendanceSource::class, $source);
    $criteria = peopleReportsCriteria('attendance-reports', ['employeeIds' => $ids, 'from' => '2027-01-01', 'to' => '2027-01-25']);
    $document = app(TimekeepingReports::class)->generate($actor, $criteria);

    expect($document->rows)->toBe([])->and($document->sections)->toHaveCount(200);
});
