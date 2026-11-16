<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\Show;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Platform\Transfer\ImportReport;
use App\Platform\Transfer\ImportRunner;
use App\Platform\Transfer\TableTransfers;
use App\Support\Access;
use App\Support\Csv;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The projects list and a project's task list import back from their own
 * CSV exports. A blank Code creates through CreateProject / CreateTask; a
 * known Code saves through UpdateProject, or UpdateTaskDetails then
 * ChangeTaskStatus; an untouched export changes nothing. People and sites
 * are named as the tables name them. Only a Projects manager is offered the
 * import, and a task import stays inside its own project.
 */

/** @param list<string> $modules */
function pxferActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Projects transfer '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId, 'name' => 'Lead '.Str::random(5)]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function pxferEmployee(string $code, string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => $code, 'first_name' => $first, 'last_name' => $last, 'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true])->save();

    return $employee;
}

/** @return list<array<string, string>> */
function pxferRows(string $url): array
{
    $csv = test()->get($url)->assertOk()->streamedContent();
    $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\r\n", substr($csv, strlen(Csv::BOM))));
    $headers = array_map('strval', array_shift($lines));

    return array_map(static fn (array $cells): array => array_combine($headers, array_map('strval', $cells)), $lines);
}

/** @param list<array<string, string>> $rows */
function pxferImport(string $table, string $context, array $rows): ImportReport
{
    $bytes = Csv::build(array_keys($rows[0]), array_map(static fn (array $row): array => array_values($row), $rows));

    return app(ImportRunner::class)->commit(app(Access::class), app(TableTransfers::class)->import($table), $context, $bytes);
}

it('creates and renames projects from the projects export, and leaves an untouched row alone', function () {
    $manager = pxferActor([ModuleCode::PROJECTS], allData: true);
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-001', 'name' => 'Payroll revamp', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();
    $audits = AuditLog::query()->count();

    expect(pxferImport('projects', '', pxferRows('/projects/export'))->unchanged)->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audits);

    $rows = pxferRows('/projects/export');
    $rows[0]['Project'] = 'Payroll revamp II';
    $rows[0]['Task Leader'] = $manager->name;
    $new = $rows[0];
    $new['Code'] = '';
    $new['Project'] = 'Roster app';
    $new['Status'] = '';
    $new['Due date'] = '2026-12-31';
    $report = pxferImport('projects', '', [$rows[0], $new]);

    expect([$report->created, $report->updated, $report->saved])->toBe([1, 1, true])
        ->and($project->fresh()?->name)->toBe('Payroll revamp II')
        ->and((string) $project->fresh()?->manager_id)->toBe((string) $manager->getKey())
        ->and(Project::query()->where('name', 'Roster app')->value('code'))->toBe('PRJ-002')
        ->and(Project::query()->where('name', 'Roster app')->value('status'))->toBe('PLANNING');
});

it('adds tasks with named assignees and saves an edited task, all inside its own project', function () {
    pxferActor([ModuleCode::PROJECTS], allData: true);
    $ana = pxferEmployee('11', 'Ana', 'Garcia');
    $ben = pxferEmployee('12', 'Ben', 'Reyes');
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-010', 'name' => 'Launch', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();
    $task = new ProjectTask;
    $task->forceFill(['code' => 'TSK-001', 'project_id' => $project->id, 'title' => 'Draft plan', 'status' => 'PENDING', 'priority' => 'NORMAL'])->save();

    $rows = pxferRows('/projects/'.$project->id.'/tasks/export');
    $rows[0]['Assignees'] = 'Ana Garcia; 12';
    $rows[0]['Priority'] = 'High';
    $rows[0]['Status'] = 'In Progress';
    $new = ['Code' => '', 'Task' => 'Book venue', 'Assignees' => 'ben reyes', 'Priority' => 'Low', 'Start date' => '', 'Due date' => '2026-10-15', 'Status' => ''];
    $report = pxferImport('projects.tasks', $project->id, [$rows[0], $new]);

    expect([$report->created, $report->updated, $report->saved])->toBe([1, 1, true])
        ->and($task->fresh()?->priority)->toBe('HIGH')
        ->and($task->fresh()?->status)->toBe('IN_PROGRESS')
        ->and(collect($task->fresh()?->assigneeIds())->sort()->values()->all())->toBe(collect([$ana->id, $ben->id])->sort()->values()->all())
        ->and(ProjectTask::query()->where('title', 'Book venue')->value('project_id'))->toBe($project->id);
});

it('refuses a renamed task, a stranger and another project\'s code, and saves none of the file', function () {
    pxferActor([ModuleCode::PROJECTS], allData: true);
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-020', 'name' => 'One', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();
    $other = new Project;
    $other->forceFill(['code' => 'PRJ-021', 'name' => 'Two', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();
    $task = new ProjectTask;
    $task->forceFill(['code' => 'TSK-050', 'project_id' => $project->id, 'title' => 'Keep me', 'status' => 'PENDING', 'priority' => 'NORMAL'])->save();
    $foreign = new ProjectTask;
    $foreign->forceFill(['code' => 'TSK-051', 'project_id' => $other->id, 'title' => 'Not here', 'status' => 'PENDING', 'priority' => 'NORMAL'])->save();

    $base = pxferRows('/projects/'.$project->id.'/tasks/export')[0];
    $renamed = $base;
    $renamed['Task'] = 'Renamed';
    $stranger = $base;
    $stranger['Assignees'] = 'Nobody Here';
    $alien = $base;
    $alien['Code'] = 'TSK-051';
    $report = pxferImport('projects.tasks', $project->id, [$renamed, $stranger, $alien]);

    expect($report->saved)->toBeFalse()
        ->and($report->errors)->toBe([
            ['row' => 2, 'message' => 'Task titles cannot be changed; TSK-050 is "Keep me".'],
            ['row' => 3, 'message' => 'Assignees: nobody is named Nobody Here.'],
            ['row' => 4, 'message' => 'This project has no task TSK-051. Leave Code blank to add a new task.'],
        ])
        ->and($task->fresh()?->title)->toBe('Keep me');
});

it('serves the header alone as each template, and offers imports only to a Projects manager', function () {
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-030', 'name' => 'Three', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();

    pxferActor([ModuleCode::PROJECTS], allData: true);
    expect(test()->get('/projects/export?template=1')->streamedContent())->toBe(Csv::BOM.'"Code","Project","Site","Task Leader","Start date","Due date","Progress","Health","Status"')
        ->and(test()->get('/projects/'.$project->id.'/tasks/export?template=1')->streamedContent())->toBe(Csv::BOM.'"Code","Task","Assignees","Priority","Start date","Due date","Status"');
    Livewire::test(Index::class)->assertSee('Import CSV');
    $workspace = Livewire::test(Show::class, ['project' => $project->id]);
    $workspace->assertSee('Import CSV');
    $workspace->set('taskView', 'overview')->assertSee('Import CSV');

    pxferActor([ModuleCode::PROJECTS], allData: false);
    Livewire::test(Index::class)->assertSee('Export CSV')->assertDontSee('Import CSV');
    expect(app(TableTransfers::class)->import('projects.tasks')->allows(app(Access::class), $project->id))->toBeFalse();
});
