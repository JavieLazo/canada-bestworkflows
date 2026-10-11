<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeEducation;
use App\Models\EmployeeEligibility;
use App\Models\EmployeeProfile;
use App\Models\EmployeeTraining;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Workforce\Application\Queries\EmployeeReport;
use App\Modules\Workforce\Contracts\EmployeeReportContext;
use App\Platform\Reports\ReportCriteria;
use App\Support\Access;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Management analysis reconciles one scoped cohort and names the limits of current-record evidence. */

/** @param list<string> $modules */
function workforceAnalysisActor(bool $all = true, ?string $employeeId = null, array $modules = [ModuleCode::REPORTS, ModuleCode::EMPLOYEES]): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Workforce analysis '.Str::random(8), 'all_data' => $all, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }

    return Access::forActor(User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]));
}

/** @param array<string, mixed> $fields */
function workforceAnalysisEmployee(string $code, array $fields = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => $code, 'first_name' => 'Ana', 'last_name' => $code,
        'employment_status' => 'REGULAR', 'date_hired' => '2020-10-09', 'is_active' => true,
    ], $fields))->save();

    return $employee;
}

/** @param array<string, string> $filters
 * @param  array<string, mixed>  $extra
 */
function workforceAnalysisCriteria(string $topic = 'composition', array $filters = [], array $extra = []): ReportCriteria
{
    return ReportCriteria::from(array_merge([
        'type' => 'employee-list', 'view' => 'analysis', 'from' => '2026-09-01', 'to' => '2026-09-30',
        'filters' => array_merge(['analysis' => $topic, 'groupBy' => 'employment', 'crossTab' => 'sex'], $filters),
    ], $extra));
}

/** @param array<string, mixed> $fields */
function workforceAnalysisTraining(EmployeeProfile $employee, array $fields = []): void
{
    $training = new EmployeeTraining;
    $training->forceFill(array_merge([
        'employee_id' => $employee->id, 'title' => 'Skills program', 'date_from' => '2026-08-31', 'date_to' => '2026-09-02', 'hours' => 8, 'ld_type' => 'Technical',
    ], $fields))->save();
}

it('reconciles cross-tab counts and shares including unknowns from the same employee cohort', function () {
    workforceAnalysisEmployee('FEMALE', ['sex' => 'Female']);
    workforceAnalysisEmployee('MALE', ['sex' => 'Male', 'employment_status' => 'Regular']);
    workforceAnalysisEmployee('UNKNOWN', ['employment_status' => 'CONTRACTUAL', 'is_active' => false]);
    $criteria = workforceAnalysisCriteria();
    $actor = workforceAnalysisActor();
    $report = app(EmployeeReport::class)->generate($actor, $criteria);

    expect($report->columns)->toBe(['Employment status', 'Employees', 'Share of cohort', 'Female', 'Male', 'Not recorded'])
        ->and($report->rows)->toBe([['Contractual', '1', '33.3%', '0', '0', '1'], ['Regular', '2', '66.7%', '1', '1', '0']])
        ->and($report->totals)->toBe(['Total', '3', '100.0%', '1', '1', '1'])
        ->and(array_column($report->summary, 'value', 'label')['Employees'])->toBe('3')
        ->and($report->charts[0]['points'])->toBe([['label' => 'Regular', 'value' => 2], ['label' => 'Contractual', 'value' => 1]])
        ->and(app(EmployeeReport::class)->generate($actor, ReportCriteria::from($criteria->query()))->rows)->toBe($report->rows);
});

it('retains source authorization and own-row scope across every analysis panel', function () {
    $own = workforceAnalysisEmployee('OWN', ['sex' => 'Female']);
    $foreign = workforceAnalysisEmployee('FOREIGN', ['sex' => 'Male', 'employment_status' => 'Secret status']);
    workforceAnalysisTraining($foreign);
    $query = app(EmployeeReport::class);
    $report = $query->generate(workforceAnalysisActor(false, $own->id), workforceAnalysisCriteria('development'));

    expect(array_column($report->summary, 'value', 'label')['Employees'])->toBe('1')
        ->and(array_column($report->summary, 'value', 'label')['Training records'])->toBe('0')
        ->and(json_encode($report))->not->toContain('FOREIGN', 'Secret status')
        ->and($query->generate(workforceAnalysisActor(false), workforceAnalysisCriteria())->rows)->toBe([]);
    try {
        $query->generate(workforceAnalysisActor(modules: [ModuleCode::REPORTS]), workforceAnalysisCriteria());
        $this->fail('A source grant is required for aggregate reports.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

it('keeps identically named units separate by department and identifies an optional unassigned unit', function () {
    foreach (['Engineering', 'Finance'] as $index => $name) {
        $department = new OrgNode;
        $department->forceFill(['code' => 'DEPT-'.$index, 'kind' => 'DEPARTMENT', 'name' => $name])->save();
        $unit = new OrgNode;
        $unit->forceFill(['code' => 'UNIT-'.$index, 'kind' => 'UNIT', 'name' => 'Operations', 'parent_id' => $department->id])->save();
        $position = new Position;
        $position->forceFill(['code' => 'POS-'.$index, 'name' => $name.' officer', 'org_node_id' => $unit->id])->save();
        workforceAnalysisEmployee('UNIT-'.$index, ['position_id' => $position->id, 'position' => $position->name]);

        if ($index === 0) {
            $direct = new Position;
            $direct->forceFill(['code' => 'POS-DIRECT', 'name' => 'Department officer', 'org_node_id' => $department->id])->save();
            workforceAnalysisEmployee('NO-UNIT', ['position_id' => $direct->id, 'position' => $direct->name]);
        }
    }
    $report = app(EmployeeReport::class)->generate(workforceAnalysisActor(), workforceAnalysisCriteria(filters: ['groupBy' => 'unit', 'crossTab' => 'none']));

    expect($report->rows)->toBe([
        ['Engineering / No unit recorded', '1', '33.3%'],
        ['Engineering / Operations', '1', '33.3%'],
        ['Finance / Operations', '1', '33.3%'],
    ])->and($report->totals)->toBe(['Total', '3', '100.0%'])
        ->and(array_column($report->charts[0]['points'], 'label'))->toBe([
            'Engineering / No unit recorded', 'Engineering / Operations', 'Finance / Operations',
        ]);
});

it('keeps age bands and numeric statistics explicit about unknown birth dates', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-09T09:00:00+08:00'));
    workforceAnalysisEmployee('AGE26', ['birth_date' => '2000-10-09', 'sex' => 'Female', 'civil_status' => 'Single']);
    workforceAnalysisEmployee('AGE40', ['birth_date' => '1986-10-09', 'sex' => 'Male', 'civil_status' => 'Married']);
    workforceAnalysisEmployee('UNKNOWN');
    $report = app(EmployeeReport::class)->generate(workforceAnalysisActor(), workforceAnalysisCriteria('demographics', ['groupBy' => 'age', 'crossTab' => 'none']));
    $summary = array_column($report->summary, 'value', 'label');

    expect($report->rows)->toBe([['25–34', '1', '33.3%'], ['35–44', '1', '33.3%'], ['Not recorded', '1', '33.3%']])
        ->and($summary['Known ages'])->toBe('2')
        ->and($summary['Age not recorded'])->toBe('1')
        ->and($summary['Average age (years)'])->toBe('33.0')
        ->and($summary['Median age (years)'])->toBe('33.0');
});

it('uses the filtered cohort for education eligibility and completed training without exporting raw professional rows', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-09T09:00:00+08:00'));
    $employee = workforceAnalysisEmployee('INCLUDED', ['college' => 'Legacy College']);
    $inactive = workforceAnalysisEmployee('EXCLUDED', ['is_active' => false]);
    $school = new EmployeeEducation;
    $school->forceFill(['employee_id' => $employee->id, 'level' => 'COLLEGE', 'school' => 'Recorded College'])->save();
    workforceAnalysisTraining($employee);
    workforceAnalysisTraining($employee, ['hours' => null, 'ld_type' => 'Managerial']);
    workforceAnalysisTraining($employee, ['date_to' => '2026-10-01', 'hours' => 50]);
    workforceAnalysisTraining($inactive, ['hours' => 99]);
    foreach ([['Board eligibility', '2026-10-01'], ['Career service', null], ['Professional license', '2026-11-01']] as [$name, $date]) {
        $entry = new EmployeeEligibility;
        $entry->forceFill(['employee_id' => $employee->id, 'name' => $name, 'license_no' => 'PRIVATE-LICENSE', 'license_valid_until' => $date])->save();
    }
    $report = app(EmployeeReport::class)->generate(workforceAnalysisActor(), workforceAnalysisCriteria('development', ['active' => 'ACTIVE', 'crossTab' => 'none']));
    $summary = array_column($report->summary, 'value', 'label');
    $training = collect($report->sections)->firstWhere('title', 'Training completed: 2026-09-01 to 2026-09-30');
    $expiry = collect($report->sections)->firstWhere('title', 'Eligibility expiry as of 2026-10-09');

    expect($summary['Employees'])->toBe('1')->and($summary['Training records'])->toBe('2')
        ->and($summary['Training participants'])->toBe('1')->and($summary['Known training hours'])->toBe('8.00')
        ->and($summary['Hours not recorded'])->toBe('1')->and($training['totals'] ?? [])->toBe(['Total', '2', '1', '8.00', '1'])
        ->and($expiry['rows'])->toBe([['Expired', '1'], ['Expires within 90 days', '1'], ['Later expiry', '0'], ['Expiry not recorded', '1']])
        ->and(json_encode($report))->not->toContain('PRIVATE-LICENSE', 'EXCLUDED', 'Recorded College', 'Legacy College');
});

it('excludes future training end dates from completed records while retaining missing hours separately', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-09T09:00:00+08:00'));
    $employee = workforceAnalysisEmployee('TRAINING');
    workforceAnalysisTraining($employee, ['date_from' => '2026-10-08', 'date_to' => '2026-10-08', 'hours' => null]);
    workforceAnalysisTraining($employee, ['date_from' => '2026-10-20', 'date_to' => '2026-10-20', 'hours' => 40]);
    $report = app(EmployeeReport::class)->generate(workforceAnalysisActor(), workforceAnalysisCriteria('development', ['crossTab' => 'none'], ['from' => '2026-10-01', 'to' => '2026-10-31']));
    $summary = array_column($report->summary, 'value', 'label');
    $training = collect($report->sections)->firstWhere('title', 'Training completed: 2026-10-01 to 2026-10-31');

    expect($summary['Training records'])->toBe('1')->and($summary['Training participants'])->toBe('1')
        ->and($summary['Known training hours'])->toBe('0.00')->and($summary['Hours not recorded'])->toBe('1')
        ->and($training['totals'] ?? [])->toBe(['Total', '1', '1', '0.00', '1'])
        ->and(implode(' ', $training['notes'] ?? []))->toContain('Completion cutoff: 2026-10-09 (Manila)', 'future end dates are excluded')
        ->and(implode(' ', $report->notes))->toContain('recorded end date through 2026-10-09 (Manila)', 'Future end dates are excluded');
});

it('counts recorded joinings regularizations and resignations within partial months without inventing historical headcount', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-09T09:00:00+08:00'));
    workforceAnalysisEmployee('IN', ['date_hired' => '2026-09-15', 'date_regular' => '2026-09-20']);
    workforceAnalysisEmployee('OUT', ['date_hired' => '2020-10-09', 'date_resigned' => '2026-09-18', 'is_active' => false]);
    workforceAnalysisEmployee('EARLY', ['date_hired' => '2026-09-01']);
    $report = app(EmployeeReport::class)->generate(workforceAnalysisActor(), workforceAnalysisCriteria('service', ['crossTab' => 'none'], ['from' => '2026-09-10', 'to' => '2026-09-25']));
    $monthly = collect($report->sections)->firstWhere('title', 'Recorded movements by month');
    $summary = array_column($report->summary, 'value', 'label');

    expect($monthly['rows'])->toBe([['2026-09', '1', '1', '1']])
        ->and($summary['Joined in period'])->toBe('1')->and($summary['Regularized in period'])->toBe('1')->and($summary['Resigned in period'])->toBe('1')
        ->and(implode(' ', $report->notes))->toContain('do not reconstruct historical headcount', 'Rehires and breaks');
});

it('separates missing information from record exceptions and does not require an optional unit', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-09T09:00:00+08:00'));
    workforceAnalysisEmployee('REVIEW', ['employee_id_no' => '0', 'email' => 'invalid email', 'date_resigned' => '2019-01-01', 'is_active' => true]);
    $report = app(EmployeeReport::class)->generate(workforceAnalysisActor(), workforceAnalysisCriteria('quality', ['crossTab' => 'none']));
    $completeness = collect($report->sections)->firstWhere('title', 'Field completeness');
    $missing = collect($report->sections)->firstWhere('title', 'Employees with missing information');
    $exceptions = collect($report->sections)->firstWhere('title', 'Record exceptions to review');

    expect($completeness['rows'][0])->toBe(['Employee ID', '1', '0', '100.0%'])
        ->and(array_column($completeness['rows'], 0))->not->toContain('Unit')
        ->and($missing['rows'][0][4])->not->toContain('Employee ID', 'Email')
        ->and($exceptions['rows'][0][3])->toContain('Resigned before date joined', 'Active with a recorded resignation', 'Email format needs review');
});

it('shows assigned Employee IDs or an em dash across movement and record quality details without using internal row IDs', function () {
    workforceAnalysisEmployee('98701', ['last_name' => 'Assigned', 'employee_id_no' => 'HR-2042', 'date_hired' => '2026-09-15', 'email' => 'invalid']);
    workforceAnalysisEmployee('98702', ['last_name' => 'Missing', 'date_hired' => '2026-09-15', 'email' => 'invalid']);
    $actor = workforceAnalysisActor();
    $query = app(EmployeeReport::class);
    $service = $query->generate($actor, workforceAnalysisCriteria('service', ['crossTab' => 'none']));
    $quality = $query->generate($actor, workforceAnalysisCriteria('quality', ['crossTab' => 'none']));
    $movements = collect($service->sections)->firstWhere('title', 'Employees behind recorded movements');
    $missing = collect($quality->sections)->firstWhere('title', 'Employees with missing information');
    $exceptions = collect($quality->sections)->firstWhere('title', 'Record exceptions to review');
    $completeness = collect($quality->sections)->firstWhere('title', 'Field completeness');

    foreach ([$movements, $missing, $exceptions] as $section) {
        expect(array_column($section['rows'], 0, 1))->toBe(['Ana Assigned' => 'HR-2042', 'Ana Missing' => '—']);
    }
    expect($completeness['rows'][0])->toBe(['Employee ID', '1', '1', '50.0%'])
        ->and(json_encode([$service, $quality]))->not->toContain('98701', '98702');
});

it('rejects unknown analysis choices and excessive comparison cardinality before displaying a partial matrix', function () {
    $actor = workforceAnalysisActor();
    expect(fn () => app(EmployeeReport::class)->generate($actor, workforceAnalysisCriteria(filters: ['crossTab' => 'salary'])))
        ->toThrow(ValidationException::class);
    foreach (range(1, 21) as $number) {
        workforceAnalysisEmployee('EMP-'.$number, ['employment_status' => 'Status '.$number]);
    }
    expect(fn () => app(EmployeeReport::class)->generate($actor, workforceAnalysisCriteria(filters: ['crossTab' => 'employment'])))
        ->toThrow(ValidationException::class, '20 categories');
});

it('returns narrow attendance employment context only for employees permitted by the attendance source', function () {
    $own = workforceAnalysisEmployee('OWN', ['date_hired' => '2020-01-01', 'date_resigned' => '2026-09-30', 'email' => 'private@example.test', 'monthly_rate' => 12345]);
    $other = workforceAnalysisEmployee('OTHER');
    $actor = workforceAnalysisActor(false, $own->id, [ModuleCode::REPORTS, ModuleCode::EDTR]);
    $context = app(EmployeeReportContext::class)->attendanceContext($actor, [$own->id, $other->id]);

    expect(array_keys($context))->toBe([$own->id])
        ->and($context[$own->id]['date_hired'])->toBe('2020-01-01')
        ->and($context[$own->id]['date_resigned'])->toBe('2026-09-30')
        ->and($context[$own->id])->not->toHaveKeys(['email', 'monthly_rate'])
        ->and(app(EmployeeReportContext::class)->attendanceContext(workforceAnalysisActor(false, modules: [ModuleCode::REPORTS, ModuleCode::EDTR]), [$own->id]))->toBe([]);
});
