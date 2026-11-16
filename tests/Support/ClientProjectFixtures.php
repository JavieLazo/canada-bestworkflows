<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\CreateProject;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\SaveClient;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Contracts\Views\ClientView;
use App\Support\Access;
use Illuminate\Support\Str;

/** @param list<string> $modules */
function cpaUser(?EmployeeProfile $employee = null, array $modules = ['PROJECTS', 'TASKS'], bool $allData = false): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Association '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
}

function cpaLogin(User $user): void
{
    test()->actingAs($user->fresh());
    app()->forgetInstance(Access::class);
}

function cpaEmployee(string $first = 'Client'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'CPA-'.Str::random(8), 'first_name' => $first, 'last_name' => 'Member', 'employment_status' => 'Regular', 'date_hired' => '2026-01-01', 'is_active' => true])->save();

    return $employee;
}

/** @param list<User> $members */
function cpaClient(User $owner, array $members = []): ClientView
{
    $actor = Access::forUser($owner);
    $client = app(SaveClient::class)->handle($actor, null, 'Client '.Str::random(8));
    foreach ($members as $member) {
        app(SetClientMember::class)->handle($actor, $client->id, $member->id, true);
    }

    return $client;
}

function cpaProject(User $owner, ClientView $client, string $name = 'Website'): Project
{
    $view = app(CreateProject::class)->handle(Access::forUser($owner), new ProjectDraft($name, '', 'NONE', 'ACTIVE', '2026-01-01', '2026-12-31', (string) $owner->id, $client->id));

    return Project::query()->findOrFail($view->id);
}

/** @param list<EmployeeProfile> $employees */
function cpaTask(User $owner, Project $project, array $employees, string $mode = 'GROUP', string $title = 'Client deliverable'): ProjectTask
{
    $view = app(CreateTask::class)->handle(Access::forUser($owner), $project->id, new TaskDraft($title, '', 'NORMAL', '2026-11-20', array_map(static fn (EmployeeProfile $employee): string => $employee->id, $employees), $mode));
    if ($view === null) {
        throw new LogicException('The fixture project must accept its task.');
    }

    return ProjectTask::query()->findOrFail($view->id);
}
