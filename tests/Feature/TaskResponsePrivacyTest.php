<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskFileView;
use App\Modules\WorkManagement\Contracts\Views\TaskResponseView;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * On an INDIVIDUAL task a member's response — their note, the decision on it
 * and the evidence they handed in — belongs to that member and to the task's
 * reviewer alone. A co-assignee, even one holding all_data + PROJECTS, reads a
 * peer's STATE and never their content, through responses(), files(),
 * counts() or either download route. A PROJECTS manager off the crew reads
 * and downloads everything, and a GROUP task stays one response the whole
 * crew shares.
 */

/**
 * The content keys a peer's entry must not carry at all — not even as null.
 *
 * @return list<string>
 */
function tprivContentKeys(): array
{
    return ['id', 'remarks', 'review_remarks', 'reviewed_by', 'submitted_at', 'reviewed_at', 'revision'];
}

/** @param list<string> $modules module codes, one RoleModuleGrant each */
function tprivUser(array $modules, bool $allData, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Tpriv '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
}

/** Signs `$user` in for the next request, with a fresh Access for them. */
function tprivLogin(User $user): void
{
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
}

function tprivEmployee(string $first): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(5)),
        'first_name' => $first,
        'last_name' => 'Santos',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/** @param list<EmployeeProfile> $crew */
function tprivTask(Project $project, string $code, string $mode, array $crew): ProjectTask
{
    $task = new ProjectTask;
    $task->forceFill([
        'code' => $code,
        'project_id' => $project->getKey(),
        'title' => 'Field report '.$code,
        'status' => 'IN_PROGRESS',
        'priority' => 'NORMAL',
        'submission_mode' => $mode,
    ])->save();
    $task->syncAssignees(array_map(static fn (EmployeeProfile $e): string => $e->id, $crew), null);

    return $task;
}

/** A member's own response row, written directly so each state is one line of fixture. */
function tprivResponse(ProjectTask $task, EmployeeProfile $member, string $status, string $remarks, ?string $reviewRemarks = null, ?int $reviewedBy = null): ProjectTaskSubmission
{
    $row = new ProjectTaskSubmission;
    $row->forceFill([
        'task_id' => $task->id,
        'employee_id' => $member->id,
        'status' => $status,
        'remarks' => $remarks,
        'submitted_at' => CarbonImmutable::now()->utc(),
        'review_remarks' => $reviewRemarks,
        'reviewed_at' => $reviewRemarks === null ? null : CarbonImmutable::now()->utc(),
        'reviewed_by' => $reviewedBy,
        'revision' => 1,
    ])->save();

    return $row;
}

/** A stored file on the task: a BRIEF, or `$member`'s SUBMISSION evidence. */
function tprivFile(ProjectTask $task, string $kind, ?EmployeeProfile $member, string $name): ProjectTaskFile
{
    $path = UploadedFile::fake()->create($name, 4, 'application/pdf')->store('task-files', 'local');
    $file = new ProjectTaskFile;
    $file->fill(['task_id' => $task->id, 'kind' => $kind, 'file_name' => $name, 'mime' => 'application/pdf', 'size' => 4096, 'path' => (string) $path]);
    // Set by property, the way the upload writer sets it — never mass-assigned.
    $file->employee_id = $member?->id;
    $file->save();

    return $file;
}

/**
 * Ana, Ben and Cara share one INDIVIDUAL task: Ana has submitted, Ben was
 * returned, Cara has handed in nothing. Ana and Ben each attached evidence
 * next to the brief. Ana signs in with TASKS alone; Ben holds all_data +
 * PROJECTS and is still on the crew; the reviewer is a PROJECTS manager
 * linked to an employee who is NOT on it. The same crew also shares a GROUP
 * task with Ben's evidence on it.
 *
 * @return array{task: ProjectTask, group: ProjectTask, ana: EmployeeProfile, ben: EmployeeProfile, cara: EmployeeProfile, anaUser: User, benUser: User, caraUser: User, reviewer: User, anaRow: ProjectTaskSubmission, benRow: ProjectTaskSubmission, brief: ProjectTaskFile, anaFile: ProjectTaskFile, benFile: ProjectTaskFile, groupFile: ProjectTaskFile}
 */
function tprivFixture(): array
{
    Storage::fake('local');
    $ana = tprivEmployee('Ana');
    $ben = tprivEmployee('Ben');
    $cara = tprivEmployee('Cara');
    $reviewer = tprivUser(['PROJECTS'], true, tprivEmployee('Dana')->id);
    $project = new Project;
    $project->forceFill(['code' => 'PRJ-001', 'name' => 'Survey', 'status' => 'ACTIVE', 'start_date' => '2026-01-01'])->save();
    $task = tprivTask($project, 'TSK-001', 'INDIVIDUAL', [$ana, $ben, $cara]);
    $group = tprivTask($project, 'TSK-002', 'GROUP', [$ana, $ben, $cara]);
    $comment = new TaskComment;
    $comment->forceFill(['task_id' => $task->id, 'author_name' => 'Dana Santos', 'side' => 'MANAGEMENT', 'body' => 'Use the new template.'])->save();

    return [
        'task' => $task,
        'group' => $group,
        'ana' => $ana,
        'ben' => $ben,
        'cara' => $cara,
        'anaUser' => tprivUser(['TASKS'], false, $ana->id),
        'benUser' => tprivUser(['PROJECTS', 'TASKS'], true, $ben->id),
        'caraUser' => tprivUser(['TASKS'], false, $cara->id),
        'reviewer' => $reviewer,
        'anaRow' => tprivResponse($task, $ana, 'SUBMITTED', 'Ana: counts attached.'),
        'benRow' => tprivResponse($task, $ben, 'RETURNED', 'Ben: first pass.', 'Redo the totals.', (int) $reviewer->getKey()),
        'brief' => tprivFile($task, 'BRIEF', null, 'brief.pdf'),
        'anaFile' => tprivFile($task, 'SUBMISSION', $ana, 'ana.pdf'),
        'benFile' => tprivFile($task, 'SUBMISSION', $ben, 'ben.pdf'),
        'groupFile' => tprivFile($group, 'SUBMISSION', $ben, 'group.pdf'),
    ];
}

/**
 * @param  iterable<int, ProjectTaskFileView>  $files
 * @return list<string>
 */
function tprivFileIds(iterable $files): array
{
    $ids = [];
    foreach ($files as $file) {
        $ids[] = $file->id;
    }
    sort($ids);

    return $ids;
}

/**
 * @param  list<ProjectTaskFile>  $files
 * @return list<string>
 */
function tprivIds(array $files): array
{
    $ids = array_map(static fn (ProjectTaskFile $file): string => $file->id, $files);
    sort($ids);

    return $ids;
}

function tprivExpectStateOnly(?TaskResponseView $entry): void
{
    expect($entry)->not->toBeNull();
    assert($entry instanceof TaskResponseView);
    expect($entry->content_visible)->toBeFalse();
    expect(array_keys($entry->toArray()))->toBe(['employee_id', 'status', 'task_status', 'is_self', 'content_visible']);
    foreach (tprivContentKeys() as $key) {
        expect($entry->toArray())->not->toHaveKey($key);
    }
}

/*
|--------------------------------------------------------------------------
| responses()
|--------------------------------------------------------------------------
*/

it('shows a member their own response in full and only the state of a peer', function () {
    $f = tprivFixture();
    $responses = app(WorkRecords::class)->responses(Access::forActor($f['anaUser']), $f['task']->id);

    $crew = [$f['ana']->id, $f['ben']->id, $f['cara']->id];
    sort($crew);
    expect($responses->keys()->all())->toBe($crew);

    $own = $responses->get($f['ana']->id);
    assert($own instanceof TaskResponseView);
    expect($own->is_self)->toBeTrue()
        ->and($own->content_visible)->toBeTrue()
        ->and($own->status)->toBe('SUBMITTED')
        ->and($own->label())->toBe('Submitted')
        ->and($own->awaitsReview())->toBeTrue()
        ->and($own->id)->toBe($f['anaRow']->id)
        ->and($own->remarks)->toBe('Ana: counts attached.')
        ->and($own->revision)->toBe(1);

    $peer = $responses->get($f['ben']->id);
    tprivExpectStateOnly($peer);
    assert($peer instanceof TaskResponseView);
    expect($peer->status)->toBe('RETURNED')
        ->and($peer->label())->toBe('Returned')
        ->and($peer->pill())->toBe('pill pill-danger')
        ->and($peer->is_self)->toBeFalse()
        ->and($peer->remarks)->toBeNull()
        ->and($peer->review_remarks)->toBeNull();

    $silent = $responses->get($f['cara']->id);
    tprivExpectStateOnly($silent);
    assert($silent instanceof TaskResponseView);
    expect($silent->status)->toBe('NONE')->and($silent->label())->toBe('Not submitted');

    // The own-queue read (My Tasks) gives the same answer.
    $mine = app(WorkRecords::class)->responses(Access::forActor($f['anaUser']), $f['task']->id, own: true);
    expect($mine->map(static fn (TaskResponseView $r): string => $r->status)->all())
        ->toBe($responses->map(static fn (TaskResponseView $r): string => $r->status)->all());
    tprivExpectStateOnly($mine->get($f['ben']->id));
});

it('keeps a peer response private from an all-data PROJECTS holder who is on the crew', function () {
    $f = tprivFixture();
    $responses = app(WorkRecords::class)->responses(Access::forActor($f['benUser']), $f['task']->id);

    tprivExpectStateOnly($responses->get($f['ana']->id));
    tprivExpectStateOnly($responses->get($f['cara']->id));
    $own = $responses->get($f['ben']->id);
    assert($own instanceof TaskResponseView);
    expect($own->content_visible)->toBeTrue()
        ->and($own->remarks)->toBe('Ben: first pass.')
        ->and($own->review_remarks)->toBe('Redo the totals.')
        ->and($own->reviewed_by)->toBe((int) $f['reviewer']->getKey());
});

it('shows a reviewer off the crew every member response in full', function () {
    $f = tprivFixture();
    $unlinked = tprivUser(['PROJECTS'], true);

    foreach ([$f['reviewer'], $unlinked] as $user) {
        $responses = app(WorkRecords::class)->responses(Access::forActor($user), $f['task']->id);
        expect($responses)->toHaveCount(3);
        foreach ($responses as $entry) {
            expect($entry->content_visible)->toBeTrue()->and($entry->is_self)->toBeFalse()
                ->and($entry->toArray())->toHaveKeys(tprivContentKeys());
        }
        expect($responses->get($f['ana']->id)?->remarks)->toBe('Ana: counts attached.')
            ->and($responses->get($f['ben']->id)?->review_remarks)->toBe('Redo the totals.')
            ->and($responses->get($f['cara']->id)?->status)->toBe('NONE')
            ->and($responses->get($f['cara']->id)?->id)->toBeNull();
    }
});

it('gives no responses for a GROUP task or a task outside the reader scope', function () {
    $f = tprivFixture();
    $records = app(WorkRecords::class);
    $outsider = tprivUser(['TASKS'], false, tprivEmployee('Eli')->id);

    expect($records->responses(Access::forActor($f['anaUser']), $f['group']->id))->toBeEmpty()
        ->and($records->responses(Access::forActor($f['reviewer']), $f['group']->id))->toBeEmpty()
        ->and($records->responses(Access::forActor($outsider), $f['task']->id))->toBeEmpty()
        ->and($records->responses(Access::forGuest(), $f['task']->id))->toBeEmpty()
        // The own-queue read is membership only, even for a reviewer.
        ->and($records->responses(Access::forActor($f['reviewer']), $f['task']->id, own: true))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| files() and counts()
|--------------------------------------------------------------------------
*/

it('leaves a peer evidence out of files and counts but keeps the brief and one own', function () {
    $f = tprivFixture();
    $records = app(WorkRecords::class);
    $taskIds = [$f['task']->id, $f['group']->id];

    $ana = Access::forActor($f['anaUser']);
    expect(tprivFileIds($records->files($ana, $taskIds)))->toBe(tprivIds([$f['brief'], $f['anaFile'], $f['groupFile']]));
    $counts = $records->counts($ana, $taskIds);
    expect($counts['files']->all())->toEqual([$f['task']->id => 2, $f['group']->id => 1])
        // The thread stays shared.
        ->and($counts['comments']->all())->toEqual([$f['task']->id => 1]);

    $ben = Access::forActor($f['benUser']);
    expect(tprivFileIds($records->files($ben, [$f['task']->id])))->toBe(tprivIds([$f['brief'], $f['benFile']]))
        ->and($records->counts($ben, [$f['task']->id])['files']->all())->toEqual([$f['task']->id => 2]);

    $cara = Access::forActor($f['caraUser']);
    expect(tprivFileIds($records->files($cara, [$f['task']->id])))->toBe(tprivIds([$f['brief']]))
        ->and($records->counts($cara, [$f['task']->id])['files']->all())->toEqual([$f['task']->id => 1]);

    // Evidence names its member.
    $own = $records->files($ana, [$f['task']->id])->firstWhere('id', $f['anaFile']->id);
    expect($own?->employee_id)->toBe($f['ana']->id);
});

it('shows a reviewer off the crew every file of an individual task', function () {
    $f = tprivFixture();
    $records = app(WorkRecords::class);
    $all = tprivIds([$f['brief'], $f['anaFile'], $f['benFile']]);

    foreach ([$f['reviewer'], tprivUser(['PROJECTS'], true)] as $user) {
        $actor = Access::forActor($user);
        expect(tprivFileIds($records->files($actor, [$f['task']->id])))->toBe($all)
            ->and($records->counts($actor, [$f['task']->id])['files']->all())->toEqual([$f['task']->id => 3]);
    }
});

it('keeps a removed member evidence with the reviewer alone', function () {
    $f = tprivFixture();
    $f['task']->syncAssignees([$f['ana']->id, $f['cara']->id], null);
    $records = app(WorkRecords::class);

    expect(ProjectTaskFile::query()->whereKey($f['benFile']->id)->exists())->toBeTrue()
        ->and(tprivFileIds($records->files(Access::forActor($f['anaUser']), [$f['task']->id])))->toBe(tprivIds([$f['brief'], $f['anaFile']]))
        ->and(tprivFileIds($records->files(Access::forActor($f['reviewer']), [$f['task']->id])))->toBe(tprivIds([$f['brief'], $f['anaFile'], $f['benFile']]))
        ->and($records->responses(Access::forActor($f['reviewer']), $f['task']->id)->keys()->sort()->values()->all())
        ->toBe(collect([$f['ana']->id, $f['cara']->id])->sort()->values()->all());
});

/*
|--------------------------------------------------------------------------
| Downloads — both routes
|--------------------------------------------------------------------------
*/

it('refuses a peer evidence to a member and to an all-data assignee', function (string $route) {
    $f = tprivFixture();

    tprivLogin($f['anaUser']);
    $this->get(route($route, $f['benFile']->id))->assertForbidden();

    tprivLogin($f['benUser']);
    $this->get(route($route, $f['anaFile']->id))->assertForbidden();

    expect(ProjectTaskFile::query()->whereKey([$f['anaFile']->id, $f['benFile']->id])->count())->toBe(2)
        ->and(Storage::disk('local')->exists($f['anaFile']->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($f['benFile']->path))->toBeTrue();
})->with(['tasks.files.download', 'projects.task-files.download']);

it('still serves a member their own evidence and the brief', function (string $route) {
    $f = tprivFixture();

    tprivLogin($f['anaUser']);
    foreach ([$f['anaFile'], $f['brief']] as $file) {
        $response = $this->get(route($route, $file->id))->assertOk();
        expect($response->streamedContent())->toBe(Storage::disk('local')->get($file->path));
    }

    tprivLogin($f['benUser']);
    $response = $this->get(route($route, $f['benFile']->id))->assertOk();
    expect($response->streamedContent())->toBe(Storage::disk('local')->get($f['benFile']->path));
})->with(['tasks.files.download', 'projects.task-files.download']);

it('serves a reviewer off the crew every member evidence', function (string $route) {
    $f = tprivFixture();

    tprivLogin($f['reviewer']);
    foreach ([$f['anaFile'], $f['benFile']] as $file) {
        $response = $this->get(route($route, $file->id))->assertOk();
        expect($response->streamedContent())->toBe(Storage::disk('local')->get($file->path));
    }
})->with(['tasks.files.download', 'projects.task-files.download']);

it('keeps a GROUP task evidence shared by the whole crew', function (string $route) {
    $f = tprivFixture();

    expect(tprivFileIds(app(WorkRecords::class)->files(Access::forActor($f['caraUser']), [$f['group']->id])))->toBe(tprivIds([$f['groupFile']]));
    tprivLogin($f['anaUser']);
    $response = $this->get(route($route, $f['groupFile']->id))->assertOk();
    expect($response->streamedContent())->toBe(Storage::disk('local')->get($f['groupFile']->path));
})->with(['tasks.files.download', 'projects.task-files.download']);
