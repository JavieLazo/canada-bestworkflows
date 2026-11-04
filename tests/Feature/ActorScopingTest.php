<?php

declare(strict_types=1);

use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\Concerns\ScopesToActor;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLeavePolicy;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryRate;
use App\Models\InventoryItem;
use App\Models\InventoryReceipt;
use App\Models\LeaveAttendanceCharge;
use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveEarningPosting;
use App\Models\LeaveTravelRequest;
use App\Models\OvertimeApproval;
use App\Models\PayrollAdjustment;
use App\Models\PayrollEmployeeBenefit;
use App\Models\PayrollRecurringLineEntry;
use App\Models\PayrollTaxBalance;
use App\Models\Payslip;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskFile;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\TaskComment;
use App\Support\Access;
use Illuminate\Support\Str;

/**
 * The non-negotiable regression test named in 01-STARTER-PACK.md section 5.3 and in
 * the ScopesToActor docblock, and listed in the plan's risk register as the
 * EARLY WARNING that the RBAC scoping leak has returned:
 *
 *   "no test asserts that an employee-less account gets an empty collection
 *    from every scoped screen"
 *
 * The leak: where($column, null) compiles to IS NULL, so an account with no
 * linked employee record is handed EVERY unassigned row - including a freshly
 * bootstrapped admin, before anyone has assigned them anything.
 *
 * These assert the generated SQL, not merely a row count, because a table that
 * happens to be empty makes a leaking query look correct.
 */

/**
 * Every directly scoped model, with its employee foreign key and module code.
 *
 * @return array<string, array{class-string<AttendanceLog|AttendanceRemark|Shift|RosterLocation|RosterShift|RosterDayOverride|RosterScheduleChange|LeaveTravelRequest|LeaveCredit|LeaveCreditEntry|OvertimeApproval|EmployeeDocument|Payslip|InventoryItem|InventoryReceipt|EmployeeLeavePolicy|LeaveEarningPosting|LeaveAttendanceCharge|LeaveCashOut|PayrollAdjustment|EmployeeSalaryRate|PayrollEmployeeBenefit|PayrollTaxBalance|PayrollRecurringLineEntry>, string, string}>
 */
function scopedModels(): array
{
    return [
        'AttendanceLog' => [AttendanceLog::class, 'employee_id', 'EDTR'],
        'AttendanceRemark' => [AttendanceRemark::class, 'employee_id', 'EDTR'],
        'Shift' => [Shift::class, 'employee_id', 'ROSTER'],
        'RosterLocation' => [RosterLocation::class, 'employee_id', 'ROSTER'],
        'EmployeeSalaryRate' => [EmployeeSalaryRate::class, 'employee_id', 'EMPLOYEES'],
        'RosterShift' => [RosterShift::class, 'employee_id', 'ROSTER'],
        'RosterDayOverride' => [RosterDayOverride::class, 'employee_id', 'ROSTER'],
        'RosterScheduleChange' => [RosterScheduleChange::class, 'employee_id', 'ROSTER'],
        'LeaveTravelRequest' => [LeaveTravelRequest::class, 'employee_id', 'LEAVES'],
        'LeaveCredit' => [LeaveCredit::class, 'employee_id', 'LEAVES'],
        'LeaveCreditEntry' => [LeaveCreditEntry::class, 'employee_id', 'LEAVES'],
        'EmployeeLeavePolicy' => [EmployeeLeavePolicy::class, 'employee_id', 'LEAVES'],
        'LeaveEarningPosting' => [LeaveEarningPosting::class, 'employee_id', 'LEAVES'],
        'LeaveAttendanceCharge' => [LeaveAttendanceCharge::class, 'employee_id', 'LEAVES'],
        'LeaveCashOut' => [LeaveCashOut::class, 'employee_id', 'LEAVES'],
        'PayrollAdjustment' => [PayrollAdjustment::class, 'employee_id', 'PAYROLL'],
        'PayrollEmployeeBenefit' => [PayrollEmployeeBenefit::class, 'employee_id', 'PAYROLL'],
        'PayrollTaxBalance' => [PayrollTaxBalance::class, 'employee_id', 'PAYROLL'],
        'PayrollRecurringLineEntry' => [PayrollRecurringLineEntry::class, 'employee_id', 'PAYROLL'],
        'OvertimeApproval' => [OvertimeApproval::class, 'employee_id', 'EDTR'],
        'EmployeeDocument' => [EmployeeDocument::class, 'employee_id', 'EMPLOYEES'],
        'Payslip' => [Payslip::class, 'employee_id', 'PAYROLL'],
        'InventoryItem' => [InventoryItem::class, 'employee_id', 'INVENTORY'],
        'InventoryReceipt' => [InventoryReceipt::class, 'employee_id', 'INVENTORY'],
    ];
}

/**
 * The models scoped THROUGH a relation: task membership lives in the
 * project_task_assignees pivot (project_tasks has no employee column), so
 * tasks narrow via `assignments` and projects two hops away via
 * `tasks.assignments`.
 *
 * @return array<string, array{class-string<ProjectTask|Project>, string, string, string}>
 */
function viaScopedModels(): array
{
    return [
        'ProjectTask' => [ProjectTask::class, 'assignments', 'employee_id', 'PROJECTS'],
        'Project' => [Project::class, 'tasks.assignments', 'employee_id', 'PROJECTS'],
    ];
}

function scopingEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(4)),
        'first_name' => 'Ana',
        'last_name' => 'Garcia',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/**
 * A project holding an UNASSIGNED task and a task assigned to $assignee.
 *
 * The third element is null exactly when no assignee was given.
 *
 * @return ($assignee is null ? array{Project, ProjectTask, null} : array{Project, ProjectTask, ProjectTask})
 */
function scopingFixture(?EmployeeProfile $assignee = null): array
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-'.Str::upper(Str::random(4)),
        'name' => 'Project '.Str::random(8),
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    $unassigned = new ProjectTask;
    $unassigned->forceFill([
        'code' => 'TSK-'.Str::upper(Str::random(5)),
        'project_id' => $project->getKey(),
        'title' => 'Nobody owns this',
        'status' => 'PENDING',
        'priority' => 'NORMAL',
    ])->save();

    $assigned = null;

    if ($assignee !== null) {
        $assigned = new ProjectTask;
        $assigned->forceFill([
            'code' => 'TSK-'.Str::upper(Str::random(5)),
            'project_id' => $project->getKey(),
            'title' => 'Owned',
            'status' => 'PENDING',
            'priority' => 'NORMAL',
        ])->save();

        $row = new ProjectTaskAssignee;
        $row->fill(['task_id' => $assigned->getKey(), 'employee_id' => $assignee->getKey()])->save();
    }

    return [$project, $unassigned, $assigned];
}

/**
 * A signed-in account holding the grant but with NO employee link.
 *
 * @param  list<string>  $modules  ModuleCode values
 */
function employeelessActor(array $modules, bool $allData = false): Access
{
    return new Access(
        userId: '1',
        employeeId: null,
        fullName: 'Unlinked Account',
        roleName: 'Admin',
        allData: $allData,
        modules: $modules,
    );
}

it('reports ROWS_NONE for an account with no employee link', function () {
    $actor = employeelessActor(['EDTR']);

    expect($actor->hasEmployeeLink())->toBeFalse();
    expect($actor->rowVisibility('EDTR'))->toBe(Access::ROWS_NONE);
});

it('compiles to an impossible predicate, never IS NULL, on every scoped model', function () {
    $leaks = [];
    $missing = [];

    foreach (scopedModels() as $label => [$class, $column, $module]) {
        $sql = strtolower(
            $class::query()
                ->toEmployee(employeelessActor([$module]), $column, $module)
                ->toRawSql()
        );

        if (! str_contains($sql, '1 = 0')) {
            $missing[] = "{$label}: {$sql}";
        }

        // The leak signature, in either quoting style.
        if (str_contains($sql, "\"{$column}\" is null") || str_contains($sql, "`{$column}` is null")) {
            $leaks[] = "{$label}: {$sql}";
        }
    }

    expect($leaks)->toBe([], 'DATA LEAK — a scope compiled to IS NULL for an employee-less actor.');
    expect($missing)->toBe([], 'A scoped model did not narrow to the impossible predicate.');
});

it('compiles to an impossible predicate, never IS NULL, on every via-scoped model', function () {
    $leaks = [];
    $missing = [];

    foreach (viaScopedModels() as $label => [$class, $relation, $column, $module]) {
        $sql = strtolower(
            $class::query()
                ->toEmployeeVia(employeelessActor([$module]), $relation, $column, $module)
                ->toRawSql()
        );

        if (! str_contains($sql, '1 = 0')) {
            $missing[] = "{$label}: {$sql}";
        }

        // The ROWS_NONE arm must not even reach the relation: no EXISTS, no
        // pivot, and never the leak signature in either quoting style.
        if (str_contains($sql, "\"{$column}\" is null") || str_contains($sql, "`{$column}` is null") || str_contains($sql, 'exists')) {
            $leaks[] = "{$label}: {$sql}";
        }
    }

    expect($leaks)->toBe([], 'DATA LEAK — a via-scope compiled to IS NULL or an unguarded EXISTS for an employee-less actor.');
    expect($missing)->toBe([], 'A via-scoped model did not narrow to the impossible predicate.');
});

it('qualifies the via column against the LAST relation of a dotted path', function () {
    $actor = new Access(
        userId: '1',
        employeeId: '01J0000000000000000000ABCD',
        fullName: 'Linked',
        roleName: 'Staff',
        allData: false,
        modules: ['PROJECTS'],
    );

    $tasks = strtolower(ProjectTask::query()->toEmployeeVia($actor, 'assignments', 'employee_id', 'PROJECTS')->toRawSql());
    $projects = strtolower(Project::query()->toEmployeeVia($actor, 'tasks.assignments', 'employee_id', 'PROJECTS')->toRawSql());

    foreach ([$tasks, $projects] as $sql) {
        expect($sql)->toContain('exists')
            ->toContain('project_task_assignees')
            ->toContain('01j0000000000000000000abcd')
            ->not->toContain('is null');
        expect(preg_match('/"project_task_assignees"\."employee_id" = |`project_task_assignees`\.`employee_id` = /', $sql))->toBe(1, $sql);
    }

    // Two hops for a project: projects → tasks → assignees.
    expect(substr_count($projects, 'exists'))->toBe(2);
    expect($projects)->toContain('project_tasks');
});

it('returns zero rows for an employee-less actor even when unassigned and assigned rows exist', function () {
    $other = scopingEmployee();
    [$project] = scopingFixture($other);
    $actor = employeelessActor(['PROJECTS']);

    // An unassigned row is precisely what IS NULL would have handed over;
    // another employee's row is what a missing guard would.
    expect(ProjectTask::query()->toEmployeeVia($actor, 'assignments', 'employee_id', 'PROJECTS')->count())->toBe(0);
    expect(Project::query()->toEmployeeVia($actor, 'tasks.assignments', 'employee_id', 'PROJECTS')->count())->toBe(0);
    expect(Project::query()->whereKey($project->getKey())->count())->toBe(1);
});

it('narrows a linked actor to the tasks they are one of the assignees of, and their projects', function () {
    $mine = scopingEmployee();
    $other = scopingEmployee();
    [$project, $unassigned, $assigned] = scopingFixture($mine);
    [$theirProject] = scopingFixture($other);

    $actor = new Access(
        userId: '1',
        employeeId: (string) $mine->getKey(),
        fullName: 'Ana Garcia',
        roleName: 'Staff',
        allData: false,
        modules: ['PROJECTS'],
    );

    $taskIds = ProjectTask::query()->toEmployeeVia($actor, 'assignments', 'employee_id', 'PROJECTS')->pluck('id')->all();
    $projectIds = Project::query()->toEmployeeVia($actor, 'tasks.assignments', 'employee_id', 'PROJECTS')->pluck('id')->all();

    expect($taskIds)->toBe([$assigned->getKey()]);
    expect($projectIds)->toBe([$project->getKey()]);
    expect($projectIds)->not->toContain($theirProject->getKey());
    expect($taskIds)->not->toContain($unassigned->getKey());
});

it('widens to every row only when the role carries all_data', function () {
    $admin = employeelessActor(['EDTR'], allData: true);

    expect($admin->rowVisibility('EDTR'))->toBe(Access::ROWS_ALL);

    $sql = AttendanceLog::query()->toEmployee($admin, 'employee_id', 'EDTR')->toRawSql();

    expect($sql)->not->toContain('1 = 0');
});

it('fails closed for a guest', function () {
    $guest = Access::forGuest();

    expect($guest->isSignedIn())->toBeFalse();
    expect($guest->hasEmployeeLink())->toBeFalse();
    expect($guest->rowVisibility('EDTR'))->toBe(Access::ROWS_NONE);
});

it('registers every model that uses ScopesToActor, or names it as trait-only', function () {
    // The IS NULL scans above see only the models listed in scopedModels() and
    // viaScopedModels(). A new `use ScopesToActor;` that is not registered
    // here would get no check at all. TaskComment and ProjectTaskFile carry
    // the trait but are read by task_id behind the parent task's gate, and
    // nothing calls their scopes — so they are named as trait-only rather than
    // silently skipped.
    $traitOnly = [ProjectTaskFile::class, TaskComment::class];
    $registered = array_merge(
        array_map(static fn (array $row): string => $row[0], array_values(scopedModels())),
        array_map(static fn (array $row): string => $row[0], array_values(viaScopedModels())),
    );

    $withTrait = [];
    $unregistered = [];
    foreach (glob(app_path('Models/*.php')) ?: [] as $path) {
        $class = 'App\\Models\\'.basename($path, '.php');
        if (! in_array(ScopesToActor::class, class_uses_recursive($class), true)) {
            continue;
        }
        $withTrait[] = $class;
        if (! in_array($class, $registered, true) && ! in_array($class, $traitOnly, true)) {
            $unregistered[] = $class;
        }
    }

    expect($unregistered)->toBe([], 'A model uses ScopesToActor but is in neither scopedModels(), viaScopedModels() nor the trait-only list. Register it so the IS NULL guard covers it.');

    // Both lists stay honest: every entry really carries the trait, and
    // nothing is counted twice.
    foreach (array_merge($registered, $traitOnly) as $class) {
        expect($withTrait)->toContain($class);
    }
    expect(count($withTrait))->toBe(count($registered) + count($traitOnly));
});
