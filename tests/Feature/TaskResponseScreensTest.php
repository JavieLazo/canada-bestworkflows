<?php

declare(strict_types=1);

use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The screens make GROUP and INDIVIDUAL submissions reachable without ever
 * showing more than the server would: the mode is offered only for a crew of
 * two or more and only while nothing has been handed in, an untouched choice
 * is never posted, a reviewer off the crew sees and decides every member's
 * response while "Complete task" waits on the rest, and a member — on the
 * project screen or on My Tasks — sees their own response in full and a
 * peer's STATE only, never a peer's note, review or file. A GROUP task reads
 * exactly as it did before.
 */

/** @param list<string> $modules module codes, one RoleModuleGrant each */
function tuiUser(array $modules, bool $allData, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Tui '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
}

/** Signs `$user` in, with a fresh Access for them. */
function tuiLogin(User $user): void
{
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
}

function tuiEmployee(string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(5)),
        'first_name' => $first,
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

function tuiProject(): Project
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-'.Str::upper(Str::random(4)),
        'name' => 'Project '.Str::random(8),
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    return $project;
}

/** @param list<EmployeeProfile> $crew */
function tuiTask(Project $project, string $mode, array $crew, string $status = 'IN_PROGRESS'): ProjectTask
{
    $task = new ProjectTask;
    $task->forceFill([
        'code' => 'TSK-'.Str::upper(Str::random(5)),
        'project_id' => $project->getKey(),
        'title' => 'Pump station survey',
        'status' => $status,
        'priority' => 'NORMAL',
        'submission_mode' => $mode,
        'assigned_at' => CarbonImmutable::now(),
    ])->save();
    $task->syncAssignees(array_map(static fn (EmployeeProfile $e): string => $e->id, $crew), null);

    return $task;
}

/** A member's own response row, written directly so each state is one line of fixture. */
function tuiResponse(ProjectTask $task, EmployeeProfile $member, string $status, string $remarks, ?string $reviewRemarks = null): ProjectTaskSubmission
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
        'revision' => 1,
    ])->save();

    return $row;
}

/** `$member`'s SUBMISSION evidence on the task, stamped with their employee id. */
function tuiFile(ProjectTask $task, EmployeeProfile $member, string $name): ProjectTaskFile
{
    $path = UploadedFile::fake()->create($name, 4, 'application/pdf')->store('task-files', 'local');
    $file = new ProjectTaskFile;
    $file->fill(['task_id' => $task->id, 'kind' => 'SUBMISSION', 'file_name' => $name, 'mime' => 'application/pdf', 'size' => 4096, 'path' => (string) $path]);
    $file->employee_id = $member->id;
    $file->save();

    return $file;
}

/**
 * Ana, Ben, Cara and Dan share one INDIVIDUAL task in progress: Ana's
 * response is approved, Ben's was returned, Cara has handed in nothing, Dan's
 * waits on review. Ana, Ben and Dan each attached evidence. Ana signs in with
 * TASKS and PROJECTS (no all_data), Ben and Cara with TASKS alone; the
 * reviewer is an all-data PROJECTS manager linked to an employee who is NOT
 * on the crew.
 *
 * @return array{task: ProjectTask, ana: EmployeeProfile, ben: EmployeeProfile, cara: EmployeeProfile, dan: EmployeeProfile, anaUser: User, benUser: User, caraUser: User, reviewer: User, danRow: ProjectTaskSubmission}
 */
function tuiFixture(): array
{
    Storage::fake('local');
    $ana = tuiEmployee('Ana', 'Cruz');
    $ben = tuiEmployee('Ben', 'Reyes');
    $cara = tuiEmployee('Cara', 'Santos');
    $dan = tuiEmployee('Dan', 'Villa');
    $task = tuiTask(tuiProject(), 'INDIVIDUAL', [$ana, $ben, $cara, $dan]);

    tuiResponse($task, $ana, 'APPROVED', 'Ana measured the east intake', 'Ana review looks complete');
    tuiResponse($task, $ben, 'RETURNED', 'Ben logged the pump hours', 'Ben please add the meter photo');
    $danRow = tuiResponse($task, $dan, 'SUBMITTED', 'Dan checked the valves');
    tuiFile($task, $ana, 'ana-intake.pdf');
    tuiFile($task, $ben, 'ben-hours.pdf');
    tuiFile($task, $dan, 'dan-valves.pdf');

    return [
        'task' => $task,
        'ana' => $ana,
        'ben' => $ben,
        'cara' => $cara,
        'dan' => $dan,
        'anaUser' => tuiUser(['TASKS', 'PROJECTS'], false, $ana->id),
        'benUser' => tuiUser(['TASKS'], false, $ben->id),
        'caraUser' => tuiUser(['TASKS'], false, $cara->id),
        'reviewer' => tuiUser(['PROJECTS'], true, tuiEmployee('Rita', 'Lim')->id),
        'danRow' => $danRow,
    ];
}

/** The opening tag of the review dialog's "Complete task" button, as rendered. */
function tuiCompleteButton(string $html): string
{
    if (preg_match('/<button\b[^>]*aria-describedby="complete-reason"[^>]*>/', $html, $tag) !== 1) {
        throw new RuntimeException('No Complete task button was rendered.');
    }

    return $tag[0];
}

/**
 * The state pill on `$memberId`'s OWN row of the responses list. Read from
 * that row alone: the "Waiting on: Ben Reyes (Returned), …" sentence repeats
 * every name/label pair further down the page, so an ordered page-wide search
 * would pass even with the wrong pill on a row. Rows must be collapsed.
 */
function tuiRowPill(string $html, string $memberId): string
{
    if (preg_match('/<li wire:key="response-'.preg_quote($memberId, '/').'">(.*?)<\/li>/s', $html, $row) !== 1) {
        throw new RuntimeException("No response row was rendered for {$memberId}.");
    }
    if (preg_match('/<span class="pill [^"]*">\s*([^<]*?)\s*<\/span>/', $row[1], $pill) !== 1) {
        throw new RuntimeException("The response row for {$memberId} carries no pill.");
    }

    return $pill[1];
}

/**
 * The row id and revision an Approve button posts, read from the markup the
 * reviewer was shown — never from the database.
 *
 * @return array{0: string, 1: int}
 */
function tuiRenderedDecision(string $html): array
{
    if (preg_match("/reviewResponse\\('APPROVED', '([0-9A-Za-z]+)', (\\d+)\\)/", $html, $posted) !== 1) {
        throw new RuntimeException('No Approve response button was rendered.');
    }

    return [$posted[1], (int) $posted[2]];
}

/*
|--------------------------------------------------------------------------
| Choosing the mode
|--------------------------------------------------------------------------
*/

it('offers the submission choice in the add dialog only once two assignees are ticked, and creates the task in the chosen mode', function () {
    $ana = tuiEmployee('Ana', 'Cruz');
    $ben = tuiEmployee('Ben', 'Reyes');
    $project = tuiProject();
    tuiLogin(tuiUser(['PROJECTS'], true));

    Livewire::test(Show::class, ['project' => $project->id])
        ->set('adding', true)
        ->set('taskAssigneeIds', [$ana->id])
        ->assertDontSee('How do assignees submit?')
        ->set('taskAssigneeIds', [$ana->id, $ben->id])
        ->assertSee('How do assignees submit?')
        ->assertSee('One team response')
        ->assertSee('Each assignee submits their own response')
        ->set('taskTitle', 'Field survey')
        ->set('taskSubmissionMode', 'INDIVIDUAL')
        ->call('saveTask')
        ->assertSet('adding', false)
        ->assertSet('taskSubmissionMode', 'GROUP');

    $task = ProjectTask::query()->where('title', 'Field survey')->firstOrFail();
    expect($task->submission_mode)->toBe('INDIVIDUAL')
        ->and($task->assigneeIds())->toHaveCount(2);
});

it('hides the edit panel\'s mode choice once a peer\'s evidence locks it, even from an all-data assignee who cannot see that evidence', function () {
    Storage::fake('local');
    $ana = tuiEmployee('Ana', 'Cruz');
    $ben = tuiEmployee('Ben', 'Reyes');
    $project = tuiProject();
    $open = tuiTask($project, 'INDIVIDUAL', [$ana, $ben]);
    $locked = tuiTask($project, 'INDIVIDUAL', [$ana, $ben]);
    tuiFile($locked, $ben, 'ben-hours.pdf');
    tuiLogin(tuiUser(['PROJECTS'], true, $ana->id));

    // Nothing handed in: the choice is there.
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $open->id)
        ->call('toggleEditingDetails')
        ->assertSee('How do assignees submit?');

    // Ben's evidence locks the mode although Ana is never shown the file.
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $locked->id)
        ->call('toggleEditingDetails')
        ->assertSet('editingDetails', true)
        ->assertDontSee('How do assignees submit?')
        ->assertSee('submission type is locked once anyone has handed in work or attached evidence.')
        ->assertDontSee('ben-hours.pdf');
});

it('posts no mode on a priority-only save, so a stale panel neither reverts a switch nor trips the lock', function () {
    $ana = tuiEmployee('Ana', 'Cruz');
    $ben = tuiEmployee('Ben', 'Reyes');
    $project = tuiProject();
    $stale = tuiTask($project, 'GROUP', [$ana, $ben]);
    $locked = tuiTask($project, 'INDIVIDUAL', [$ana, $ben]);
    tuiResponse($locked, $ana, 'SUBMITTED', 'Ana measured the east intake');
    tuiLogin(tuiUser(['PROJECTS'], true));

    // Another reviewer switches the mode after this panel opened on GROUP.
    $component = Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $stale->id)
        ->call('toggleEditingDetails')
        ->assertSet('seenSubmissionMode', 'GROUP');
    $stale->forceFill(['submission_mode' => 'INDIVIDUAL'])->save();
    $component->set('draftPriority', 'HIGH')->call('saveDetails')->assertSet('editingDetails', false);

    $stale->refresh();
    expect($stale->priority)->toBe('HIGH')
        ->and($stale->submission_mode)->toBe('INDIVIDUAL');

    // A task whose mode is locked still takes an edit that never touched it.
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $locked->id)
        ->call('toggleEditingDetails')
        ->set('draftPriority', 'HIGH')
        ->call('saveDetails')
        ->assertSet('editingDetails', false);

    $locked->refresh();
    expect($locked->priority)->toBe('HIGH')
        ->and($locked->submission_mode)->toBe('INDIVIDUAL');
});

it('switches the mode from the edit panel while nothing has been handed in', function () {
    $ana = tuiEmployee('Ana', 'Cruz');
    $ben = tuiEmployee('Ben', 'Reyes');
    $project = tuiProject();
    $task = tuiTask($project, 'GROUP', [$ana, $ben]);
    tuiLogin(tuiUser(['PROJECTS'], true));

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('toggleEditingDetails')
        ->set('draftSubmissionMode', 'INDIVIDUAL')
        ->call('saveDetails')
        ->assertSet('editingDetails', false)
        ->assertSet('seenSubmissionMode', 'INDIVIDUAL');

    expect($task->refresh()->submission_mode)->toBe('INDIVIDUAL');
    expect(AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $task->id)->count())->toBe(1);
});

it('drops a mode choice the lock hid after a refused save, so the next edit lands', function () {
    $ana = tuiEmployee('Ana', 'Cruz');
    $ben = tuiEmployee('Ben', 'Reyes');
    $project = tuiProject();
    $individual = tuiTask($project, 'INDIVIDUAL', [$ana, $ben]);
    $group = tuiTask($project, 'GROUP', [$ana, $ben]);
    tuiLogin(tuiUser(['PROJECTS'], true));

    // The reviewer switches the radio; Ana then hands in her response.
    $component = Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $individual->id)
        ->call('toggleEditingDetails')
        ->set('draftSubmissionMode', 'GROUP');
    tuiResponse($individual, $ana, 'SUBMITTED', 'Ana measured the east intake');

    $component->call('saveDetails')
        ->assertSet('editingDetails', true)
        ->assertDontSee('How do assignees submit?')
        ->assertSee('submission type is locked once anyone has handed in work or attached evidence.')
        ->assertSet('draftSubmissionMode', 'INDIVIDUAL')
        ->assertSet('seenSubmissionMode', 'INDIVIDUAL');
    expect($individual->refresh()->submission_mode)->toBe('INDIVIDUAL');

    // The hidden choice is gone, so a priority edit is no longer refused.
    $component->set('draftPriority', 'HIGH')->call('saveDetails')->assertSet('editingDetails', false);
    $individual->refresh();
    expect($individual->priority)->toBe('HIGH')
        ->and($individual->submission_mode)->toBe('INDIVIDUAL');
    expect(AuditLog::query()->where('action', 'TASK_UPDATED')->where('entity_id', $individual->id)->count())->toBe(1);

    // The same for a GROUP task that is handed in while the panel is open.
    $component = Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $group->id)
        ->call('toggleEditingDetails')
        ->set('draftSubmissionMode', 'INDIVIDUAL');
    $group->forceFill(['status' => 'SUBMITTED', 'remarks' => 'Team survey attached', 'submitted_at' => CarbonImmutable::now()->utc()])->save();

    $component->call('saveDetails')
        ->assertSet('editingDetails', true)
        ->assertSet('draftSubmissionMode', 'GROUP')
        ->assertSet('seenSubmissionMode', 'GROUP');
    $component->set('draftPriority', 'HIGH')->call('saveDetails')->assertSet('editingDetails', false);
    $group->refresh();
    expect($group->priority)->toBe('HIGH')
        ->and($group->submission_mode)->toBe('GROUP');
});

it('keeps a mode choice the panel still shows when a save is refused for another reason', function () {
    $ana = tuiEmployee('Ana', 'Cruz');
    $ben = tuiEmployee('Ben', 'Reyes');
    $project = tuiProject();
    $task = tuiTask($project, 'GROUP', [$ana, $ben]);
    tuiLogin(tuiUser(['PROJECTS'], true));

    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $task->id)
        ->call('toggleEditingDetails')
        ->set('draftSubmissionMode', 'INDIVIDUAL')
        ->set('draftPriority', 'URGENT-ISH')
        ->call('saveDetails')
        ->assertSet('editingDetails', true)
        ->assertSee('How do assignees submit?')
        ->assertSet('draftSubmissionMode', 'INDIVIDUAL')
        ->assertSet('seenSubmissionMode', 'GROUP');

    expect($task->refresh()->submission_mode)->toBe('GROUP');
});

/*
|--------------------------------------------------------------------------
| The reviewer's dialog
|--------------------------------------------------------------------------
*/

it('prints an individual task\'s progress under its status in the task table', function () {
    $f = tuiFixture();
    tuiLogin($f['reviewer']);

    Livewire::test(Show::class, ['project' => $f['task']->project_id])->set('taskView', 'board')
        ->assertSee('1 of 4 approved · 1 awaiting review · 1 returned · 1 not submitted');
});

it('shows the reviewer every member\'s state, the progress line and a disabled Complete task with who it waits on', function () {
    $f = tuiFixture();
    tuiLogin($f['reviewer']);

    $component = Livewire::test(Show::class, ['project' => $f['task']->project_id])
        ->call('openTask', $f['task']->id);
    $component->assertSee('Individual Responses')
        ->assertSee('1 of 4 approved · 1 awaiting review · 1 returned · 1 not submitted')
        ->assertSee('Complete Task')
        ->assertDontSee('Accept Task')
        ->assertSee('Waiting on: Ben Reyes (Returned), Cara Santos (Not submitted), Dan Villa (Submitted).')
        ->assertSee('Cancel Task');

    // Each member's own row carries their state.
    $html = $component->html();
    expect(tuiRowPill($html, $f['ana']->id))->toBe('Approved')
        ->and(tuiRowPill($html, $f['ben']->id))->toBe('Returned')
        ->and(tuiRowPill($html, $f['cara']->id))->toBe('Not submitted')
        ->and(tuiRowPill($html, $f['dan']->id))->toBe('Submitted');

    // The Complete button itself is disabled and points at the reason.
    expect(tuiCompleteButton($html))->toContain('disabled="disabled"');

    // A collapsed row shows no content; an expanded one shows it all.
    $component->assertDontSee('Dan checked the valves')
        ->call('selectResponse', $f['dan']->id)
        ->assertSet('expandedMemberId', $f['dan']->id)
        ->assertSee('Dan checked the valves')
        ->assertSee('dan-valves.pdf')
        ->assertSee('Approve Response')
        ->assertSee('Return for Rework');
});

it('enables Complete task once every current assignee\'s response is approved', function () {
    $ana = tuiEmployee('Ana', 'Cruz');
    $ben = tuiEmployee('Ben', 'Reyes');
    $task = tuiTask(tuiProject(), 'INDIVIDUAL', [$ana, $ben]);
    tuiResponse($task, $ana, 'APPROVED', 'Ana measured the east intake');
    tuiResponse($task, $ben, 'APPROVED', 'Ben logged the pump hours');
    tuiLogin(tuiUser(['PROJECTS'], true));

    $component = Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id);
    $component->assertSee('2 of 2 approved')
        ->assertSee('Every response is approved.')
        ->assertDontSee('Waiting on:');
    expect(tuiCompleteButton($component->html()))->not->toContain('disabled="disabled"');
});

it('approves a response through the screen with the row id and revision it rendered', function () {
    $f = tuiFixture();
    tuiLogin($f['reviewer']);

    $component = Livewire::test(Show::class, ['project' => $f['task']->project_id])
        ->call('openTask', $f['task']->id)
        ->call('selectResponse', $f['dan']->id);
    [$responseId, $revision] = tuiRenderedDecision($component->html());
    expect($responseId)->toBe($f['danRow']->id)
        ->and($revision)->toBe(1);

    // A return without a note is refused before anything is written.
    $component->call('reviewResponse', 'RETURNED', $responseId, $revision);
    expect($f['danRow']->refresh()->status)->toBe('SUBMITTED');

    $component->set('responseRemark', 'Valves look right')
        ->call('reviewResponse', 'APPROVED', $responseId, $revision)
        ->assertSet('reviewOpen', true)
        ->assertSet('expandedMemberId', null)
        ->assertSet('responseRemark', '');

    $row = $f['danRow']->refresh();
    expect($row->status)->toBe('APPROVED')
        ->and($row->revision)->toBe(2)
        ->and($row->review_remarks)->toBe('Valves look right')
        ->and($row->reviewed_by)->toBe($f['reviewer']->getKey());
    expect(AuditLog::query()->where('action', 'TASK_RESPONSE_APPROVED')->where('entity_id', $row->id)->count())->toBe(1);

    // The same click again carries a spent revision and changes nothing.
    $component->set('responseRemark', 'Take it back')
        ->call('reviewResponse', 'RETURNED', $responseId, $revision);
    expect($row->refresh()->status)->toBe('APPROVED')
        ->and($row->revision)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| What a member sees — on both screens
|--------------------------------------------------------------------------
*/

it('shows a member on the project screen only their own response and no decisions', function () {
    $f = tuiFixture();
    tuiLogin($f['anaUser']);

    $component = Livewire::test(Show::class, ['project' => $f['task']->project_id])
        ->call('openTask', $f['task']->id);
    $component->assertSet('openTaskId', $f['task']->id)
        ->assertSeeInOrder(['Ben Reyes', 'Returned', 'Dan Villa', 'Submitted']);
    $component->call('selectResponse', $f['ana']->id)
        ->assertSee('Ana measured the east intake')
        ->assertSee('ana-intake.pdf')
        ->assertSee('Ana review looks complete');

    // Expanding a peer opens nothing: their content never reaches the view.
    $component->call('selectResponse', $f['ben']->id)
        ->assertSet('expandedMemberId', $f['ben']->id)
        ->assertDontSee('Ben logged the pump hours')
        ->assertDontSee('Ben please add the meter photo')
        ->assertDontSee('ben-hours.pdf')
        ->assertDontSee('Dan checked the valves')
        ->assertDontSee('dan-valves.pdf')
        ->assertDontSee('Complete Task')
        ->assertDontSee('Approve Response')
        ->assertDontSee('Return for Rework')
        ->assertDontSee('Cancel Task');

    // And the action refuses them outright.
    $component->set('responseRemark', 'Looks fine')
        ->call('reviewResponse', 'APPROVED', $f['danRow']->id, 1);
    expect($f['danRow']->refresh()->status)->toBe('SUBMITTED');
    expect(AuditLog::query()->where('action', 'TASK_RESPONSE_APPROVED')->count())->toBe(0);
});

it('names each co-assignee with their state on My Tasks and never prints their note, review or files', function () {
    $f = tuiFixture();
    tuiLogin($f['caraUser']);

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $f['task']->id)
        ->assertSet('openId', $f['task']->id)
        ->assertSee('Working with:')
        ->assertSee('Ana Cruz (Approved), Ben Reyes (Returned), Dan Villa (Submitted)')
        ->assertDontSee('Ana measured the east intake')
        ->assertDontSee('Ana review looks complete')
        ->assertDontSee('ana-intake.pdf')
        ->assertDontSee('Ben logged the pump hours')
        ->assertDontSee('Ben please add the meter photo')
        ->assertDontSee('ben-hours.pdf')
        ->assertDontSee('Dan checked the valves')
        ->assertDontSee('dan-valves.pdf');
});

it('shows a returned member their own response, the review of it and the form to resubmit', function () {
    $f = tuiFixture();
    tuiLogin($f['benUser']);

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $f['task']->id)
        ->assertSee('Your response: Returned')
        ->assertSee('Management sent your response back — update it and resubmit.')
        ->assertSee('Returned for Rework')
        ->assertSee('Ben please add the meter photo')
        ->assertSee('Ben logged the pump hours')
        ->assertSee('ben-hours.pdf')
        ->assertSee('Submit My Response')
        ->assertSee('Only you and the reviewers see this.')
        ->assertDontSee('Management cancelled')
        ->assertDontSee('This task is closed')
        ->assertDontSee('Dan checked the valves')
        ->assertDontSee('ana-intake.pdf');
});

it('tells an approved member they are waiting on the rest of the team', function () {
    $f = tuiFixture();
    tuiLogin($f['anaUser']);

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $f['task']->id)
        ->assertSee('Your response: Approved')
        ->assertSee("Your response was approved. The task completes once everyone's response is approved.")
        ->assertSee('Approved — waiting for the rest of the team.')
        ->assertSee('Ana review looks complete')
        ->assertDontSee('Submit My Response')
        ->assertDontSee('Management cancelled');
});

it('gives a member with no response the submit form and never a closed-task sentence, and takes their response from it', function () {
    $f = tuiFixture();
    tuiLogin($f['caraUser']);

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $f['task']->id)
        ->assertSee('Your response: Not submitted')
        ->assertSee('Submit your response once your part is done.')
        ->assertSee('Submit My Response')
        ->assertDontSee('Submit for Review')
        ->assertDontSee('Management cancelled')
        ->assertDontSee('This task is closed')
        ->set('remarks', 'Cara walked the outflow')
        ->call('submitTask');

    $row = ProjectTaskSubmission::query()->where('task_id', $f['task']->id)->where('employee_id', $f['cara']->id)->firstOrFail();
    expect($row->status)->toBe('SUBMITTED')
        ->and($row->remarks)->toBe('Cara walked the outflow');
    expect($f['task']->refresh()->status)->toBe('IN_PROGRESS');
});

/*
|--------------------------------------------------------------------------
| A GROUP task reads as it always did
|--------------------------------------------------------------------------
*/

it('renders group submissions with their existing notes and decisions', function () {
    $ana = tuiEmployee('Ana', 'Cruz');
    $ben = tuiEmployee('Ben', 'Reyes');
    $project = tuiProject();
    $working = tuiTask($project, 'GROUP', [$ana, $ben]);
    $submitted = tuiTask($project, 'GROUP', [$ana, $ben], 'SUBMITTED');
    $submitted->forceFill(['submitted_at' => CarbonImmutable::now(), 'submitted_by' => $ben->id, 'remarks' => 'Both halves done'])->save();
    $cancelled = tuiTask($project, 'GROUP', [$ana, $ben], 'CANCELLED');
    tuiLogin(tuiUser(['TASKS'], false, $ana->id));

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $working->id)
        ->assertSee('Submit it for review once the work is done.')
        ->assertSee('Working with:')
        ->assertSee('Ben Reyes')
        ->assertDontSee('Ben Reyes (')
        ->assertSee('The reviewer reads this alongside the task.')
        ->assertSee('Submit for Review')
        ->assertDontSee('Your response')
        ->assertDontSee('Submit My Response');

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $submitted->id)
        ->assertSee('Submitted and waiting for management review.')
        ->assertSee('Waiting for management review.')
        ->assertSee('Last submission by Ben Reyes')
        ->assertSee('Both halves done');

    Livewire::test(MyTasksPage::class)
        ->call('openTask', $cancelled->id)
        ->assertSee('This task was called off.')
        ->assertSee('Management cancelled this task — nothing further is needed.');

    tuiLogin(tuiUser(['PROJECTS'], true));
    Livewire::test(Show::class, ['project' => $project->id])
        ->call('openTask', $submitted->id)
        ->assertSee('Team response')
        ->assertSee('Both halves done')
        ->assertSee('Accept Task')
        ->assertSee('Return for Rework')
        ->assertDontSee('Complete Task')
        ->assertDontSee('Individual Responses');
});
