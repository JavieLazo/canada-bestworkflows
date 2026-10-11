<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeEducation;
use App\Models\EmployeeProfile;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Queries\EmployeeReport;
use App\Platform\Reports\ReportCriteria;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Employee report filters, personal columns and choices retain source scope and one export definition. */
function employeeBuilderActor(bool $all = true, ?string $employeeId = null): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Employee reports '.Str::random(8), 'all_data' => $all, 'is_system' => false])->save();
    foreach ([ModuleCode::REPORTS, ModuleCode::EMPLOYEES] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }

    return Access::forActor(User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]));
}

/** @param array<string, mixed> $fields */
function employeeBuilderEmployee(string $code, array $fields = []): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(array_merge([
        'employee_code' => $code, 'first_name' => 'Ana', 'last_name' => $code,
        'employment_status' => 'REGULAR', 'date_hired' => '2020-10-09', 'is_active' => true,
    ], $fields))->save();

    return $employee;
}

/** @param array<string, mixed> $fields */
function employeeBuilderCriteria(array $fields = []): ReportCriteria
{
    return ReportCriteria::from(array_merge(['type' => 'employee-list'], $fields));
}

it('uses assigned employee IDs by default and keeps optional row IDs separately labeled and sorted', function () {
    employeeBuilderEmployee('10', ['employee_id_no' => 'HR-010', 'last_name' => 'Bravo']);
    employeeBuilderEmployee('2', ['employee_id_no' => 'HR-2042', 'last_name' => 'Alpha']);
    employeeBuilderEmployee('20', ['employee_id_no' => null, 'last_name' => 'Missing']);
    employeeBuilderEmployee('21', ['employee_id_no' => '0', 'last_name' => 'Zero']);
    $query = app(EmployeeReport::class);
    $actor = employeeBuilderActor();
    $default = $query->generate($actor, employeeBuilderCriteria());

    expect($default->columns[0])->toBe('Employee ID')
        ->and($default->columnKeys)->not->toContain('employee_code')
        ->and(array_column($default->rows, 0))->toBe(['HR-2042', 'HR-010', '—', '0']);

    $criteria = employeeBuilderCriteria(['columns' => ['employee_code', 'employee_id', 'employee'], 'sort' => 'employee_code']);
    $report = $query->generate($actor, $criteria);
    expect($report->columns)->toBe(['ID', 'Employee ID', 'Employee'])
        ->and($report->rows)->toBe([
            ['2', 'HR-2042', 'Ana Alpha'], ['10', 'HR-010', 'Ana Bravo'], ['20', '—', 'Ana Missing'], ['21', '0', 'Ana Zero'],
        ])
        ->and($query->generate($actor, ReportCriteria::from($criteria->query()))->rows)->toBe($report->rows)
        ->and($query->generate($actor, employeeBuilderCriteria(['filters' => ['missing' => 'employee_id']]))->rows[0][0])->toBe('—');
});

it('keeps personal columns and filter choices inside the employee source scope', function () {
    $own = employeeBuilderEmployee('OWN', ['position' => 'Own position', 'email' => 'own@example.test', 'birth_date' => '1990-01-01']);
    employeeBuilderEmployee('FOREIGN', ['position' => 'Private foreign position', 'email' => 'foreign@example.test']);
    $query = app(EmployeeReport::class);
    $actor = employeeBuilderActor(false, $own->id);
    $document = $query->generate($actor, employeeBuilderCriteria(['columns' => ['employee_code', 'email', 'birth_date']]));

    expect($document->rows)->toBe([['OWN', 'own@example.test', '1990-01-01']])
        ->and($query->options($actor)['position'])->toHaveKey('Own position')->not->toHaveKey('Private foreign position');
    $unlinked = employeeBuilderActor(false);
    expect($query->generate($unlinked, employeeBuilderCriteria(['columns' => ['email']]))->rows)->toBe([])
        ->and($query->options($unlinked)['position'])->toBe(['ALL' => 'Any position', 'NONE' => 'No position']);
});

it('searches employee contact email only within the report source scope', function () {
    $own = employeeBuilderEmployee('OWN', ['email' => 'operations@example.test']);
    employeeBuilderEmployee('FOREIGN', ['email' => 'foreign@example.test']);
    $query = app(EmployeeReport::class);
    $actor = employeeBuilderActor(false, $own->id);

    expect($query->generate($actor, employeeBuilderCriteria(['filters' => ['search' => 'operations@'], 'columns' => ['employee_code']]))->rows)->toBe([['OWN']])
        ->and($query->generate($actor, employeeBuilderCriteria(['filters' => ['search' => 'foreign@'], 'columns' => ['employee_code']]))->rows)->toBe([]);
});

it('keeps directory reads narrow and loads education only when the report needs it', function () {
    employeeBuilderEmployee('DIRECTORY', ['birth_date' => '1990-01-01', 'email' => 'private@example.test', 'college' => 'Legacy College']);
    $actor = employeeBuilderActor();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        app(EmployeeReport::class)->generate($actor, employeeBuilderCriteria());
        $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($sql)->not->toContain('birth_date', 'email', 'college', 'employee_education')
        ->and(app(EmployeeReport::class)->generate($actor, employeeBuilderCriteria(['columns' => ['email', 'education']]))->rows)->toBe([['private@example.test', 'College — Legacy College']]);
});

it('combines workforce filters and prints selected columns from batched organization labels', function () {
    $department = new OrgNode;
    $department->forceFill(['code' => '1', 'kind' => 'DEPARTMENT', 'name' => 'Engineering'])->save();
    $unit = new OrgNode;
    $unit->forceFill(['code' => '2', 'kind' => 'UNIT', 'name' => 'Systems', 'parent_id' => $department->id])->save();
    $position = new Position;
    $position->forceFill(['code' => '1', 'name' => 'Engineer', 'org_node_id' => $unit->id])->save();
    $site = new WorkSite;
    $site->forceFill(['name' => 'North Office'])->save();
    employeeBuilderEmployee('MATCH', ['employee_id_no' => '1234', 'position_id' => $position->id, 'position' => 'Engineer', 'work_site_id' => $site->id, 'sex' => 'Female', 'civil_status' => 'Single']);
    employeeBuilderEmployee('INACTIVE', ['position_id' => $position->id, 'position' => 'Engineer', 'is_active' => false]);
    employeeBuilderEmployee('OTHER');
    $actor = employeeBuilderActor();
    $filters = ['search' => '1234 Engineer', 'active' => 'ACTIVE', 'employment' => 'REGULAR', 'department' => $department->id, 'position' => 'Engineer', 'worksite' => $site->id, 'sex' => 'Female', 'civilStatus' => 'Single', 'hiredFrom' => '2020-01-01', 'hiredTo' => '2020-12-31'];
    $criteria = employeeBuilderCriteria(['filters' => $filters, 'columns' => ['employee_code', 'department', 'unit', 'worksite']]);
    $document = app(EmployeeReport::class)->generate($actor, $criteria);

    expect($document->rows)->toBe([['MATCH', 'Engineering', 'Systems', 'North Office']])
        ->and($document->columnKeys)->toBe(['employee_code', 'department', 'unit', 'worksite'])
        ->and(implode(' ', $document->notes))->toContain('Engineering', 'Systems', 'North Office', 'Ordered by Employee (asc)')
        ->and(app(EmployeeReport::class)->generate($actor, ReportCriteria::from($criteria->query()))->rows)->toBe($document->rows)
        ->and(app(EmployeeReport::class)->generate($actor, employeeBuilderCriteria(['filters' => ['department' => $unit->id]]))->rows)->toHaveCount(2);
});

it('matches blank missing information without treating a recorded zero as blank', function () {
    employeeBuilderEmployee('NULL', ['email' => null, 'employee_id_no' => '0']);
    employeeBuilderEmployee('SPACE', ['email' => '   ', 'employee_id_no' => 'ID-1']);
    employeeBuilderEmployee('PRESENT', ['email' => 'hello@example.test', 'employee_id_no' => 'ID-2']);
    $query = app(EmployeeReport::class);
    $actor = employeeBuilderActor();
    $report = $query->generate($actor, employeeBuilderCriteria(['filters' => ['missing' => 'email'], 'columns' => ['employee_code', 'email', 'missing']]));

    expect(array_column($report->rows, 0))->toBe(['NULL', 'SPACE'])
        ->and($report->rows[0][2])->toContain('Email')->not->toContain('Employee ID')
        ->and($query->generate($actor, employeeBuilderCriteria(['filters' => ['missing' => 'employee_id']]))->rows)->toBe([])
        ->and($query->generate($actor, employeeBuilderCriteria(['filters' => ['department' => 'NONE']]))->rows)->toHaveCount(3);
});

it('calculates completed age and tenure on Manila dates and sorts numbers with unknown ages last', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-08T17:00:00Z'));
    employeeBuilderEmployee('TODAY', ['birth_date' => '2000-10-09', 'date_hired' => '2020-10-09']);
    employeeBuilderEmployee('TOMORROW', ['birth_date' => '2000-10-10', 'date_hired' => '2020-10-10']);
    employeeBuilderEmployee('OLDER', ['birth_date' => '1926-10-09', 'date_hired' => '2020-10-09', 'date_resigned' => '2022-10-08', 'is_active' => false]);
    employeeBuilderEmployee('UNKNOWN', ['birth_date' => null]);
    $query = app(EmployeeReport::class);
    $actor = employeeBuilderActor();
    $report = $query->generate($actor, employeeBuilderCriteria(['columns' => ['employee_code', 'age', 'tenure'], 'sort' => 'age', 'direction' => 'desc']));

    expect($report->rows)->toBe([['OLDER', '100', '1'], ['TODAY', '26', '6'], ['TOMORROW', '25', '5'], ['UNKNOWN', '', '6']])
        ->and($report->numericColumns)->toBe([1, 2])
        ->and($query->generate($actor, employeeBuilderCriteria(['filters' => ['ageMin' => '26', 'ageMax' => '26', 'tenureMin' => '6', 'tenureMax' => '6'], 'columns' => ['employee_code']]))->rows)->toBe([['TODAY']]);
});

it('uses recorded education with per-level legacy fallback and explains that attendance is not graduation', function () {
    $structured = employeeBuilderEmployee('STRUCTURED', ['college' => 'Old College', 'college_course' => 'Old course']);
    $school = new EmployeeEducation;
    $school->forceFill(['employee_id' => $structured->id, 'level' => 'COLLEGE', 'school' => 'Current College', 'course' => 'Computer Science', 'attended_to' => 2025])->save();
    employeeBuilderEmployee('LEGACY', ['graduate_school' => 'Legacy Graduate School', 'graduate_course' => 'Public Administration']);
    employeeBuilderEmployee('EMPTY');
    $query = app(EmployeeReport::class);
    $actor = employeeBuilderActor();
    $report = $query->generate($actor, employeeBuilderCriteria(['columns' => ['employee_code', 'education']]));

    expect($report->rows)->toBe([
        ['EMPTY', ''], ['LEGACY', 'Graduate studies — Legacy Graduate School · Public Administration'], ['STRUCTURED', 'College — Current College · Computer Science'],
    ])->and(implode(' ', $report->notes))->toContain('whether completed or attended')
        ->and($query->generate($actor, employeeBuilderCriteria(['filters' => ['education' => 'COLLEGE'], 'columns' => ['employee_code']]))->rows)->toBe([['STRUCTURED']])
        ->and($query->generate($actor, employeeBuilderCriteria(['filters' => ['education' => 'NONE'], 'columns' => ['employee_code']]))->rows)->toBe([['EMPTY']]);
});

it('rejects unknown columns and filters rather than silently changing a requested report', function (array $input) {
    expect(fn () => app(EmployeeReport::class)->generate(employeeBuilderActor(), employeeBuilderCriteria($input)))
        ->toThrow(ValidationException::class);
})->with([
    'private column' => [['columns' => ['monthly_rate']]],
    'unknown sort' => [['sort' => 'salary']],
    'unknown filter' => [['filters' => ['salary' => '50000']]],
    'invalid active value' => [['filters' => ['active' => 'perhaps']]],
    'reversed age bounds' => [['filters' => ['ageMin' => '50', 'ageMax' => '20']]],
    'negative tenure' => [['filters' => ['tenureMin' => '-1']]],
    'invalid hire date' => [['filters' => ['hiredFrom' => 'yesterday']]],
    'invalid missing field' => [['filters' => ['missing' => 'salary']]],
    'invalid education level' => [['filters' => ['education' => 'DOCTORATE']]],
]);
