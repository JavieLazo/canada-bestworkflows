<?php

declare(strict_types=1);

use App\Livewire\Projects\ClientEmployeeChecklist;
use App\Livewire\Projects\Index;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Commands\ChangeTaskStatus;
use App\Modules\WorkManagement\Application\Commands\CommentOnOwnTask;
use App\Modules\WorkManagement\Application\Commands\CreateProject;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\DeleteSubmission;
use App\Modules\WorkManagement\Application\Commands\ImportTasks;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\Commands\TaskBriefs;
use App\Modules\WorkManagement\Application\Commands\UpdateProject;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\WorkLists;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\Queries\WorkSummary;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Support\Access;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('creates a client project from its filtered list without granting broad EMS access', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    cpaLogin($owner);
    Livewire::withQueryParams(['client' => $client->id]);
    $page = Livewire::test(Index::class);
    $page->assertSet('client', $client->id)->call('openCreateProject')->assertSet('draftClientId', $client->id)->assertSet('draftManagerId', (string) $owner->id)
        ->set('draftName', 'Launch')->call('createProject')->assertSet('adding', false);
    $project = Project::query()->sole();
    $page->assertRedirect(route('projects.show', $project->id));
    expect($project->client_id)->toBe($client->id)->and($project->manager_id)->toBe($owner->id)
        ->and($owner->role->all_data)->toBeFalse();
    Livewire::test(Show::class, ['project' => $project->id])->assertViewHas('canManage', true)->assertSee($client->name);
    expect(fn () => app(CreateProject::class)->handle(Access::forUser($owner), new ProjectDraft('Internal', '', 'NONE', 'PLANNING', '2026-01-01', '', 'NONE')))->toThrow(RuntimeException::class);
});

it('lets client owners assign work to My Tasks users and explicitly accept their submission', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $member = cpaUser($employee, ['TASKS']);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee]);
    cpaLogin($member);
    Livewire::test(MyTasksPage::class)->assertSee($task->code);
    expect(app(StartTask::class)->handle(Access::forUser($member), $task->id))->toBeTrue();
    expect(app(SubmitTask::class)->handle(Access::forUser($member), $task->id, 'Ready for review'))->toBeTrue();
    app(ReviewTask::class)->handle(Access::forUser($owner), $project->id, $task->id, 'COMPLETED', 'Accepted');
    expect($task->fresh()->status)->toBe('COMPLETED')->and($task->fresh()->remarks)->toBe('Ready for review')
        ->and($task->fresh()->reviewed_by)->toBe($owner->id)->and($task->assignments()->count())->toBe(1);
});

it('does not let broad data access or a matching employee account bypass client membership', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $member = cpaUser($employee, ['TASKS']);
    $outsider = cpaUser($employee, allData: true);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client, 'Hidden project');
    $task = cpaTask($owner, $project, [$employee], title: 'Hidden task');
    $actor = Access::forUser($outsider);
    expect(WorkScope::projects($actor)->count())->toBe(0)->and(WorkScope::tasks($actor)->count())->toBe(0)
        ->and(WorkScope::tasks($actor, true)->count())->toBe(0)->and(WorkRules::ownTask($actor, $task->id))->toBeNull();
    cpaLogin($outsider);
    Livewire::test(Index::class)->assertDontSee('Hidden project')->assertViewHas('visibleCount', 0);
    Livewire::test(Show::class, ['project' => $project->id])->assertViewHas('project', null)->assertDontSee('Hidden task');
    $this->get(route('projects.tasks.export', $project->id))->assertNotFound();
    expect($this->get(route('projects.export'))->streamedContent())->not->toContain('Hidden project');
});

it('keeps ordinary membership separate from leadership and task assignment', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    expect(WorkScope::projects(Access::forUser($member))->count())->toBe(0);
    $project->forceFill(['manager_id' => $member->id])->save();
    expect(WorkScope::projects(Access::forUser($member))->pluck('id')->all())->toBe([$project->id])
        ->and(WorkRules::runsProject(Access::forUser($member), $project))->toBeTrue()
        ->and(WorkRules::managesProject(Access::forUser($member), $project))->toBeFalse()
        ->and(WorkRules::managesProject(Access::forUser($owner), $project))->toBeTrue();
});

it('preserves an explicit no-leader choice while a client project is being planned', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    $project = app(CreateProject::class)->handle(Access::forUser($owner), new ProjectDraft('Planned', '', 'NONE', 'PLANNING', '2026-01-01', '', 'NONE', $client->id));
    expect($project->manager_id)->toBeNull()->and(WorkRules::managesProject(Access::forUser($owner), $project))->toBeTrue();
});

it('rejects leaders and assignees from another client', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    $otherEmployee = cpaEmployee('Outside');
    $other = cpaUser($otherEmployee);
    cpaClient($other);
    expect(fn () => app(CreateProject::class)->handle(Access::forUser($owner), new ProjectDraft('Invalid', '', 'NONE', 'ACTIVE', '2026-01-01', '', (string) $other->id, $client->id)))->toThrow(RuntimeException::class);
    $project = cpaProject($owner, $client);
    expect(fn () => cpaTask($owner, $project, [$otherEmployee]))->toThrow(RuntimeException::class);
    expect(ProjectTask::query()->count())->toBe(0);
    cpaLogin($owner);
    Livewire::test(ClientEmployeeChecklist::class, ['clientId' => $client->id])->assertDontSee('Outside Member');
});

it('scopes project filters, summary counts and CSV to the chosen client', function () {
    $owner = cpaUser();
    $first = cpaClient($owner);
    $second = cpaClient($owner);
    $a = cpaProject($owner, $first, 'First launch');
    cpaProject($owner, $second, 'Second launch');
    $filters = ['q' => '', 'status' => 'ALL', 'site' => 'ALL', 'manager' => 'ALL', 'queue' => 'ALL', 'client' => $first->id];
    expect(app(WorkLists::class)->projectPage(Access::forUser($owner), $filters, 'name', 'asc', '2026-10-31', 1, 20)->pluck('id')->all())->toBe([$a->id]);
    expect(app(WorkSummary::class)->counters(Access::forUser($owner), '2026-10-31', $first->id)['active'])->toBe(1);
    cpaLogin($owner);
    $csv = $this->get(route('projects.export', ['client' => $first->id]))->assertOk()->streamedContent();
    expect($csv)->toContain('First launch')->not->toContain('Second launch');
});

it('preserves individual response privacy and prevents client owners reviewing their own work', function () {
    $mine = cpaEmployee('Owner');
    $peer = cpaEmployee('Peer');
    $owner = cpaUser($mine);
    $member = cpaUser($peer);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$mine, $peer], 'INDIVIDUAL');
    app(StartTask::class)->handle(Access::forUser($member), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($member), $task->id, 'Peer private response');
    $response = ProjectTaskSubmission::query()->sole();
    expect(fn () => app(ReviewTaskResponse::class)->handle(Access::forUser($owner), $project->id, $task->id, $response->id, 'APPROVED', '', 1))->toThrow(RuntimeException::class);
    $rows = app(WorkRecords::class)->responses(Access::forUser($owner), $task->id);
    expect($rows->get($peer->id)->content_visible)->toBeFalse()->and($response->fresh()->status)->toBe('SUBMITTED');
});

it('keeps task notifications inside the client even for alternate accounts of the same employee', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $member = cpaUser($employee, ['TASKS']);
    $alternate = cpaUser($employee, ['TASKS']);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee]);
    expect(Notification::query()->where('user_id', $member->id)->where('kind', 'TASK_ASSIGNED')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $alternate->id)->count())->toBe(0);
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $member->id, false);
    app(ChangeTaskStatus::class)->handle(Access::forUser($owner), $project->id, $task->id, 'IN_PROGRESS', '', 'PENDING');
    expect(Notification::query()->where('user_id', $member->id)->where('kind', 'TASK_IN_PROGRESS')->count())->toBe(0);
});

it('revokes direct task actions, files, review and downloads without deleting history', function () {
    Storage::fake('local');
    $owner = cpaUser();
    $employee = cpaEmployee();
    $member = cpaUser($employee, allData: true);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee]);
    $file = new ProjectTaskFile;
    $file->forceFill(['task_id' => $task->id, 'kind' => 'SUBMISSION', 'file_name' => 'private.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/private.pdf', 'uploaded_by' => $member->id, 'employee_id' => $employee->id])->save();
    Storage::disk('local')->put($file->path, 'proof');
    cpaLogin($member);
    $page = Livewire::test(Show::class, ['project' => $project->id]);
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $member->id, false);
    $actor = Access::forUser($member);
    expect(app(StartTask::class)->handle($actor, $task->id))->toBeFalse()
        ->and(app(SubmitTask::class)->handle($actor, $task->id, 'No access'))->toBeFalse()
        ->and(app(CommentOnOwnTask::class)->handle($actor, $task->id, 'No access'))->toBeFalse();
    expect(fn () => app(DeleteSubmission::class)->handle($actor, $file->id))->toThrow(HttpException::class);
    expect(fn () => app(ReviewTask::class)->handle($actor, $project->id, $task->id, 'CANCELLED', ''))->toThrow(RuntimeException::class);
    $page->call('moveBoardTask', $task->id, 'IN_PROGRESS', 'PENDING')->assertViewHas('project', null);
    $this->get(route('tasks.files.download', $file->id))->assertForbidden();
    $this->get(route('projects.task-files.download', $file->id))->assertForbidden();
    expect($task->fresh()->status)->toBe('PENDING')->and($task->assignments()->count())->toBe(1)
        ->and(ProjectTaskFile::query()->whereKey($file->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists($file->path);
});

it('retains legacy internal project access and refuses implicit reparenting', function () {
    $admin = cpaUser(allData: true);
    $legacy = Project::create(['code' => 'PRJ-OLD', 'name' => 'Internal', 'status' => 'PLANNING', 'start_date' => '2026-01-01']);
    $owner = cpaUser();
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client);
    expect(WorkScope::projects(Access::forUser($admin))->pluck('id')->all())->toBe([$legacy->id]);
    expect(app(DeleteProject::class)->handle(Access::forUser($admin), $project->id))->toBeNull();
    expect(fn () => app(UpdateProject::class)->handle(Access::forUser($admin), $legacy->id, new ProjectDraft('Internal', '', 'NONE', null, null, '', 'NONE', $client->id)))->toThrow(RuntimeException::class);
    expect($legacy->fresh()->client_id)->toBeNull()->and($project->fresh()->client_id)->toBe($client->id);
});

it('allows project names per client while keeping internal names unique', function () {
    $owner = cpaUser();
    $first = cpaClient($owner);
    $second = cpaClient($owner);
    cpaProject($owner, $first);
    cpaProject($owner, $second);
    expect(fn () => cpaProject($owner, $first))->toThrow(RuntimeException::class);
    $admin = cpaUser(allData: true);
    $draft = new ProjectDraft('Website', '', 'NONE', 'PLANNING', '2026-01-01', '', 'NONE');
    app(CreateProject::class)->handle(Access::forUser($admin), $draft);
    expect(fn () => app(CreateProject::class)->handle(Access::forUser($admin), $draft))->toThrow(RuntimeException::class);
    expect(Project::query()->count())->toBe(3);
});

it('lets the owner edit details, attach briefs and retain historical assignees', function () {
    Storage::fake('local');
    $owner = cpaUser();
    $employee = cpaEmployee('Current');
    $member = cpaUser($employee, ['TASKS']);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee]);
    $actor = Access::forUser($owner);
    app(UpdateProject::class)->handle($actor, $project->id, new ProjectDraft('Renamed project', '', 'NONE', null, null, '2026-12-31', (string) $owner->id));
    app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskDetails('HIGH', '2026-11-21', [$employee->id]));
    app(TaskBriefs::class)->attach($actor, $project->id, $task->id, UploadedFile::fake()->create('brief.pdf', 1, 'application/pdf'));
    expect($project->fresh()->name)->toBe('Renamed project')->and($task->fresh()->priority)->toBe('HIGH')->and($task->files()->count())->toBe(1);
    app(SetClientMember::class)->handle($actor, $client->id, $member->id, false);
    cpaLogin($owner);
    Livewire::test(ClientEmployeeChecklist::class, ['clientId' => $client->id])->assertDontSee('Current Member');
    Livewire::test(ClientEmployeeChecklist::class, ['clientId' => $client->id, 'taskId' => $task->id, 'value' => [$employee->id]])->assertSee('Current Member');
    app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskDetails('NORMAL', '2026-11-21', [$employee->id]));
    expect($task->assignments()->count())->toBe(1);
});

it('allows broad project managers only after explicit client membership', function () {
    $owner = cpaUser();
    $manager = cpaUser(allData: true);
    $client = cpaClient($owner);
    expect(fn () => cpaProject($manager, $client))->toThrow(RuntimeException::class);
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $manager->id, true);
    $project = cpaProject($manager, $client);
    expect(WorkRules::managesProject(Access::forUser($manager), $project))->toBeTrue();
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $manager->id, false);
    expect(WorkRules::managesProject(Access::forUser($manager), $project))->toBeFalse();
    expect(app(DeleteProject::class)->handle(Access::forUser($manager), $project->id))->toBeNull();
});

it('refuses individual reviews after the reviewers client access is removed', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $otherEmployee = cpaEmployee('Other');
    $member = cpaUser($employee, ['TASKS']);
    $other = cpaUser($otherEmployee, ['TASKS']);
    $reviewer = cpaUser(allData: true);
    $client = cpaClient($owner, [$member, $other, $reviewer]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee, $otherEmployee], 'INDIVIDUAL');
    app(StartTask::class)->handle(Access::forUser($member), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($member), $task->id, 'Private response');
    $response = ProjectTaskSubmission::query()->sole();
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $reviewer->id, false);
    expect(fn () => app(ReviewTaskResponse::class)->handle(Access::forUser($reviewer), $project->id, $task->id, $response->id, 'APPROVED', '', 1))->toThrow(RuntimeException::class);
    expect(app(WorkRecords::class)->responses(Access::forUser($reviewer), $task->id))->toBeEmpty()
        ->and($response->fresh()->status)->toBe('SUBMITTED')->and($response->fresh()->revision)->toBe(1);
});

it('imports task assignments through the same client people scope', function () {
    $owner = cpaUser();
    $employee = cpaEmployee('Import');
    $member = cpaUser($employee, ['TASKS']);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $row = new ImportRow(2, ['Code' => '', 'Task' => 'Imported', 'Assignees' => 'Import Member', 'Priority' => 'Normal', 'Start date' => '', 'Due date' => '2026-11-20', 'Status' => 'Pending']);
    expect(app(ImportTasks::class)->row(Access::forUser($owner), $project->id, $row))->toBe(ImportOutcome::CREATED);
    expect(ProjectTask::query()->sole()->assigneeIds())->toBe([$employee->id]);
});

it('resolves board permissions once per project as the number of cards grows', function () {
    $owner = cpaUser();
    $employee = cpaEmployee('Lead');
    $leader = cpaUser($employee);
    $client = cpaClient($owner, [$leader]);
    $project = cpaProject($owner, $client);
    $project->forceFill(['manager_id' => $leader->id])->save();
    $own = cpaTask($owner, $project, [$employee]);
    for ($i = 0; $i < 24; $i++) {
        cpaTask($owner, $project, [], title: 'Card '.$i);
    }
    foreach ([$owner, $leader] as $user) {
        $actor = Access::forUser($user);
        $tasks = app(WorkRecords::class)->tasks($actor, $project->id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        WorkRules::taskPermissions($actor, $project, $tasks->take(2));
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $permissions = WorkRules::taskPermissions($actor, $project, $tasks);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect($large)->toBe($small)->and($permissions)->toHaveCount(25)
            ->and($permissions[$own->id])->toBe($user->id === $owner->id);
    }
});
