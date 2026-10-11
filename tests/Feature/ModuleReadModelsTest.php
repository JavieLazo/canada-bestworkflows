<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Payroll\RunWorkspace;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Queries\GetAccounts;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\Queries\PayrollRecords;
use App\Modules\Workforce\Application\Queries\AccountEmployees;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Platform\ReadModels\Record;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Read models enforce scope without exposing writable models, lazy relations or private profile data. */

/** @param list<string> $modules */
function readSideUser(array $modules, bool $all = false, ?EmployeeProfile $employee = null): User
{
    $role = Role::create(['name' => 'Reads '.Str::random(8), 'all_data' => $all, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }

    return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
}

function readSideEmployee(string $code): EmployeeProfile
{
    return EmployeeProfile::create(['employee_code' => $code, 'first_name' => 'Read', 'last_name' => $code, 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'monthly_rate' => 98765, 'address' => 'Private address']);
}

it('keeps operational readers out of private employee profiles', function () {
    $employee = readSideEmployee('COP-PRIVATE');
    $actor = Access::forActor(readSideUser([ModuleCode::ROSTER], true));
    $row = app(OperationalDirectory::class)->find($actor, $employee->id);
    expect($row)->toBeInstanceOf(DirectoryEmployee::class);
    // One key each: toHaveKeys() throws on the FIRST key it cannot find, so the
    // list form passed as soon as any single column was absent — `monthly_rate`
    // could have leaked behind a misspelled `sss_no`.
    expect($row->toArray())->not->toHaveKey('monthly_rate')
        ->and($row->toArray())->not->toHaveKey('address')
        ->and($row->toArray())->not->toHaveKey('sss_no')
        ->and($row->toArray())->not->toHaveKey('tin');
    expect(app(EmployeeRecords::class)->find($actor, $employee->id))->toBeNull();
    $methods = array_map(static fn (ReflectionMethod $method): string => $method->name, (new ReflectionClass(DirectoryEmployee::class))->getMethods());
    expect($methods)->not->toContain('save', 'employee', 'query', 'delete');
});

it('pins personal directory reads to the linked employee without an all-data module', function () {
    $own = readSideEmployee('COP-OWN');
    $other = readSideEmployee('COP-OTHER');
    $actor = Access::forActor(readSideUser([ModuleCode::PROFILE], true, $own));
    expect(app(OperationalDirectory::class)->list($actor)->pluck('id')->all())->toBe([$own->id]);
    expect(app(EmployeeRecords::class)->find($actor, $other->id))->toBeNull();
    expect(app(OperationalDirectory::class)->list(Access::forGuest()))->toBeEmpty();
});

it('detaches data so rendering cannot issue a hidden relationship query', function () {
    $employee = readSideEmployee('COP-DETACHED');
    $actor = Access::forActor(readSideUser([ModuleCode::EMPLOYEES], true));
    $row = app(EmployeeRecords::class)->find($actor, $employee->id);
    expect($row)->toBeInstanceOf(Record::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($row->getAttribute('user'))->toBeNull();
    expect($row->date_hired->format('Y-m-d'))->toBe('2024-01-01');
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    expect(fn () => $row->first_name = 'Changed')->toThrow(Error::class);
    expect($employee->fresh()->first_name)->toBe('Read');
});

it('rejects persistence objects nested inside a read projection', function () {
    $employee = readSideEmployee('COP-NESTED');
    foreach ([$employee, EmployeeProfile::query(), DB::table('employee_profiles')] as $value) {
        expect(fn () => Snapshot::of($employee, DirectoryEmployee::class, ['nested' => collect([['value' => $value]])]))
            ->toThrow(InvalidArgumentException::class, 'Read projections cannot contain persistence objects.');
    }
});

it('keeps account reads module gated and excludes credential material', function () {
    $employee = readSideEmployee('COP-ACCOUNT');
    $allowed = readSideUser([ModuleCode::USERS]);
    $denied = readSideUser([], true);
    $query = app(GetAccounts::class);
    $row = $query->account(Access::forActor($allowed), (string) $allowed->id);
    expect($row->toArray())->toHaveKeys(['id', 'name', 'email', 'role_id', 'employee_id', 'is_active']);
    expect($row->toArray())->not->toHaveKeys(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes']);
    expect($query->account(Access::forActor($denied), (string) $allowed->id))->toBeNull();
    expect($query->handle(Access::forActor($denied))['accounts'])->toBeEmpty();
    $choices = app(AccountEmployees::class)->list(Access::forActor($allowed));
    expect($choices->first()->id)->toBe($employee->id);
    expect($choices->first()->toArray())->not->toHaveKeys(['monthly_rate', 'address']);
    expect(app(AccountEmployees::class)->list(Access::forActor($denied)))->toBeEmpty();
});

it('keeps saved payroll overrides private to the permitted employee', function () {
    $own = readSideEmployee('COP-PAY-OWN');
    $other = readSideEmployee('COP-PAY-OTHER');
    $user = readSideUser([ModuleCode::PAYROLL], false, $own);
    $run = PayrollRun::create(['code' => 'PR-001', 'name' => 'Private draft', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'status' => 'DRAFT']);
    foreach ([$own, $other] as $position => $employee) {
        PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => $position, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'allowance' => 500]);
    }
    $query = app(PayrollRecords::class);
    expect($query->find(Access::forActor($user), $run->id, true)->members->pluck('employee_id')->all())->toBe([$own->id]);
    expect($query->find(Access::forGuest(), $run->id))->toBeNull();
    $this->actingAs($user);
    Livewire::test(RunWorkspace::class, ['id' => $run->id])
        ->assertSet('members', [$own->id])->assertSet('draftAllowances', [$own->id => 500.0]);
});

it('shows co-assignee attribution without permitting private profiles or other tasks', function () {
    $own = readSideEmployee('COP-TASK-OWN');
    $peer = readSideEmployee('COP-TASK-PEER');
    $actor = Access::forActor(readSideUser([ModuleCode::TASKS], false, $own));
    $project = Project::create(['code' => 'PRJ-001', 'name' => 'Scoped', 'status' => 'PLANNING', 'start_date' => '2026-09-01']);
    $task = ProjectTask::create(['code' => 'TSK-001', 'project_id' => $project->id, 'title' => 'Shared', 'status' => 'PENDING', 'priority' => 'MEDIUM']);
    $other = ProjectTask::create(['code' => 'TSK-002', 'project_id' => $project->id, 'title' => 'Other', 'status' => 'PENDING', 'priority' => 'MEDIUM']);
    $task->syncAssignees([$own->id, $peer->id], null);
    $other->syncAssignees([$peer->id], null);
    $rows = app(WorkRecords::class)->tasks($actor, own: true);
    expect($rows->pluck('id')->all())->toBe([$task->id]);
    expect($rows->first()->assignees)->toHaveCount(2);
    foreach ($rows->first()->assignees as $assignee) {
        expect($assignee->toArray())->not->toHaveKeys(['monthly_rate', 'address']);
    }
    expect(app(WorkRecords::class)->task($actor, $other->id, own: true))->toBeNull();
    expect(app(EmployeeRecords::class)->find($actor, $peer->id))->toBeNull();
});

it('batches task attribution instead of querying once per task', function (int $count) {
    $employee = readSideEmployee('COP-BATCH');
    $actor = Access::forActor(readSideUser([ModuleCode::PROJECTS], true));
    $project = Project::create(['code' => 'PRJ-001', 'name' => 'Batch', 'status' => 'PLANNING', 'start_date' => '2026-09-01']);
    for ($i = 1; $i <= $count; $i++) {
        $task = ProjectTask::create(['code' => 'TSK-'.$i, 'project_id' => $project->id, 'title' => 'Batch '.$i, 'status' => 'PENDING', 'priority' => 'MEDIUM']);
        $task->syncAssignees([$employee->id], null);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = app(WorkRecords::class)->tasks($actor);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($rows)->toHaveCount($count);
    expect($queries)->toBeLessThanOrEqual(5);
    foreach ($rows as $row) {
        expect($row->assignees->first()->id)->toBe($employee->id);
    }
})->with(['one task' => 1, 'one hundred tasks' => 100]);

it('fails closed when payroll serialization has lost its sentinel row', function () {
    DB::table('locks')->where('name', 'copems:payroll-membership')->delete();
    expect(fn () => DB::transaction(static fn () => PayrollData::lockMembership()))
        ->toThrow(RuntimeException::class, 'The payroll membership lock is missing.');
    expect(PayrollRun::count())->toBe(0);
});
