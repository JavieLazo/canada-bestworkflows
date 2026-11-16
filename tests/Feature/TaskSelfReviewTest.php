<?php

declare(strict_types=1);

use App\Livewire\Projects\Show;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Nobody decides on work they share: an all-data PROJECTS holder who is one
 * of a task's assignees can neither accept nor return it — through the
 * ReviewTask command or the project screen — nor, on an INDIVIDUAL task,
 * approve or return a co-assignee's response or complete the task, while
 * cancelling stays theirs, a reviewer off the crew keeps every decision, and
 * the review dialog shows each viewer only the decisions the server would
 * take from them.
 */

/** @param list<string> $modules module codes, one RoleModuleGrant each */
function selfrevActor(array $modules, bool $allData, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Selfrev '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

/** An active employee with an active TASKS-only login, so a notice to them is countable. */
function selfrevEmployee(string $first): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(5)),
        'first_name' => $first,
        'last_name' => 'Reyes',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    $role = new Role;
    $role->forceFill(['name' => 'Selfrev crew '.Str::random(6), 'all_data' => false, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'TASKS'])->save();
    User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employee->getKey()]);

    return $employee;
}

/**
 * A task shared by `$crew`; a SUBMITTED one is handed in by its first member.
 *
 * @param  list<EmployeeProfile>  $crew
 */
function selfrevTask(string $status, array $crew): ProjectTask
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-'.Str::upper(Str::random(4)),
        'name' => 'Project '.Str::random(8),
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    $task = new ProjectTask;
    $task->forceFill(array_merge([
        'code' => 'TSK-'.Str::upper(Str::random(5)),
        'project_id' => $project->getKey(),
        'title' => 'Shared report',
        'status' => $status,
        'priority' => 'NORMAL',
        'assigned_at' => CarbonImmutable::now(),
    ], $status === 'SUBMITTED' ? [
        'submitted_at' => CarbonImmutable::now(),
        'submitted_by' => $crew[0]->id,
        'remarks' => 'Done po.',
    ] : []))->save();
    $task->syncAssignees(array_map(static fn (EmployeeProfile $e): string => $e->id, $crew), null);

    return $task;
}

/**
 * An INDIVIDUAL task in progress, shared by `$crew`, each member's response
 * at the given state.
 *
 * @param  list<array{0: EmployeeProfile, 1: string}>  $crew
 */
function selfrevIndividualTask(array $crew): ProjectTask
{
    $task = selfrevTask('IN_PROGRESS', array_map(static fn (array $member): EmployeeProfile => $member[0], $crew));
    $task->forceFill(['submission_mode' => 'INDIVIDUAL'])->save();
    foreach ($crew as [$member, $state]) {
        $row = new ProjectTaskSubmission;
        $row->fill(['task_id' => $task->id, 'employee_id' => $member->id, 'status' => $state, 'remarks' => 'Done po.', 'submitted_at' => CarbonImmutable::now()->utc(), 'revision' => 1]);
        $row->save();
    }

    return $task;
}

/** The task exactly as it was handed in: no decision, no thread line, no audit row, no notice. */
function selfrevExpectUndecided(ProjectTask $task): void
{
    $task->refresh();
    expect($task->status)->toBe('SUBMITTED');
    expect($task->submitted_at)->not->toBeNull();
    expect($task->getAttribute('reviewed_by'))->toBeNull();
    expect($task->review_remarks)->toBeNull();
    expect($task->completed_at)->toBeNull();
    expect(TaskComment::query()->where('task_id', $task->id)->count())->toBe(0);
    expect(AuditLog::query()->where('entity_id', $task->id)->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);
}

/*
|--------------------------------------------------------------------------
| The refusal — command and screen
|--------------------------------------------------------------------------
*/

it('refuses an accept or a return from an all-data assignee through the command', function (string $decision) {
    $mia = selfrevEmployee('Mia');
    $ben = selfrevEmployee('Ben');
    $task = selfrevTask('SUBMITTED', [$mia, $ben]);
    $lead = selfrevActor(['PROJECTS'], allData: true, employeeId: $mia->id);

    expect(fn () => app(ReviewTask::class)->handle(Access::forActor($lead), $task->project_id, $task->id, $decision, 'Fix the totals'))
        ->toThrow(RuntimeException::class, WorkRules::selfReviewMessage());

    selfrevExpectUndecided($task);
})->with(['accept' => 'COMPLETED', 'return' => 'RETURNED']);

it('refuses an accept or a return from an all-data assignee through the project screen', function (string $decision) {
    $mia = selfrevEmployee('Mia');
    $ben = selfrevEmployee('Ben');
    $task = selfrevTask('SUBMITTED', [$mia, $ben]);
    selfrevActor(['PROJECTS'], allData: true, employeeId: $mia->id);

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->set('remarks', 'Fix the totals')
        ->call('review', $decision)
        ->assertSet('reviewOpen', true);

    selfrevExpectUndecided($task);
})->with(['accept' => 'COMPLETED', 'return' => 'RETURNED']);

it('still lets an all-data assignee cancel their own task', function () {
    $mia = selfrevEmployee('Mia');
    $ben = selfrevEmployee('Ben');
    $task = selfrevTask('IN_PROGRESS', [$mia, $ben]);
    $lead = selfrevActor(['PROJECTS'], allData: true, employeeId: $mia->id);

    app(ReviewTask::class)->handle(Access::forActor($lead), $task->project_id, $task->id, 'CANCELLED', '');

    expect($task->refresh()->status)->toBe('CANCELLED');
    expect(AuditLog::query()->where('action', 'TASK_CANCELLED')->where('entity_id', $task->id)->count())->toBe(1);
    expect(Notification::query()->where('kind', 'TASK_CANCELLED')->count())->toBe(1);
});

it('lets a linked all-data reviewer who is off the crew accept and return', function (string $decision) {
    $ana = selfrevEmployee('Ana');
    $ben = selfrevEmployee('Ben');
    $reviewer = selfrevEmployee('Rita');
    $task = selfrevTask('SUBMITTED', [$ana, $ben]);
    $user = selfrevActor(['PROJECTS'], allData: true, employeeId: $reviewer->id);

    app(ReviewTask::class)->handle(Access::forActor($user), $task->project_id, $task->id, $decision, 'Fix the totals');

    $task->refresh();
    expect($task->status)->toBe($decision);
    expect($task->getAttribute('reviewed_by'))->toBe($user->getKey());
    expect(AuditLog::query()->where('action', 'TASK_'.$decision)->where('entity_id', $task->id)->count())->toBe(1);
    // One notice per crew member, both of whom are on the task.
    expect(Notification::query()->where('kind', 'TASK_'.$decision)->count())->toBe(2);
})->with(['accept' => 'COMPLETED', 'return' => 'RETURNED']);

it('refuses an all-data assignee a decision on a co-assignee\'s individual response', function (string $decision) {
    $mia = selfrevEmployee('Mia');
    $ben = selfrevEmployee('Ben');
    $task = selfrevIndividualTask([[$mia, 'APPROVED'], [$ben, 'SUBMITTED']]);
    $response = ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', $ben->id)->firstOrFail();
    $lead = selfrevActor(['PROJECTS'], allData: true, employeeId: $mia->id);

    expect(fn () => app(ReviewTaskResponse::class)->handle(Access::forActor($lead), $task->project_id, $task->id, $response->id, $decision, 'Fix the totals', 1))
        ->toThrow(RuntimeException::class, WorkRules::selfReviewMessage());

    $response->refresh();
    expect($response->status)->toBe('SUBMITTED')
        ->and($response->revision)->toBe(1)
        ->and($response->reviewed_by)->toBeNull()
        ->and($response->review_remarks)->toBeNull();
    expect(AuditLog::query()->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);
})->with(['approve' => 'APPROVED', 'return' => 'RETURNED']);

it('refuses an all-data assignee the completion of their own individual task, even with every response approved', function () {
    $mia = selfrevEmployee('Mia');
    $ben = selfrevEmployee('Ben');
    $task = selfrevIndividualTask([[$mia, 'APPROVED'], [$ben, 'APPROVED']]);
    $lead = selfrevActor(['PROJECTS'], allData: true, employeeId: $mia->id);

    expect(fn () => app(ReviewTask::class)->handle(Access::forActor($lead), $task->project_id, $task->id, 'COMPLETED', ''))
        ->toThrow(RuntimeException::class, WorkRules::selfReviewMessage());

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->completed_at)->toBeNull()
        ->and($task->getAttribute('reviewed_by'))->toBeNull();
    expect(AuditLog::query()->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);

    // A reviewer off the crew completes the very same task.
    $reviewer = selfrevActor(['PROJECTS'], allData: true, employeeId: selfrevEmployee('Rita')->id);
    app(ReviewTask::class)->handle(Access::forActor($reviewer), $task->project_id, $task->id, 'COMPLETED', '');
    expect($task->refresh()->status)->toBe('COMPLETED');
});

/*
|--------------------------------------------------------------------------
| The review dialog offers only what the server would accept
|--------------------------------------------------------------------------
*/

it('hides Accept and Return from an assignee-reviewer and says why, keeping Cancel', function () {
    $mia = selfrevEmployee('Mia');
    $ben = selfrevEmployee('Ben');
    $submitted = selfrevTask('SUBMITTED', [$mia, $ben]);
    $active = selfrevTask('IN_PROGRESS', [$mia, $ben]);
    selfrevActor(['PROJECTS'], allData: true, employeeId: $mia->id);
    $callout = "You're one of this task's assignees, so another reviewer has to decide it.";

    Livewire::test(Show::class, ['project' => $submitted->project_id])
        ->call('openTask', $submitted->id)
        ->assertDontSee('Accept Task')
        ->assertDontSee('Return for Rework')
        ->assertDontSee('Review Remark')
        ->assertSee($callout, false);

    Livewire::test(Show::class, ['project' => $active->project_id])
        ->call('openTask', $active->id)
        ->assertSee('Cancel Task')
        ->assertSee($callout, false);
});

it('offers Accept and Return, without the callout, to a reviewer off the crew', function () {
    $ana = selfrevEmployee('Ana');
    $reviewer = selfrevEmployee('Rita');
    $task = selfrevTask('SUBMITTED', [$ana]);
    selfrevActor(['PROJECTS'], allData: true, employeeId: $reviewer->id);

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->assertSee('Accept Task')
        ->assertSee('Return for Rework')
        ->assertDontSee('so another reviewer has to decide it');
});

it('shows no decision buttons to a viewer without all-data PROJECTS', function (string $status) {
    $ana = selfrevEmployee('Ana');
    $task = selfrevTask($status, [$ana]);
    selfrevActor(['PROJECTS'], allData: false, employeeId: $ana->id);

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->assertSet('openTaskId', $task->id)
        ->assertDontSee('Accept Task')
        ->assertDontSee('Return for Rework')
        ->assertDontSee('Cancel Task')
        ->assertDontSee('Review Remark')
        ->assertDontSee('so another reviewer has to decide it');
})->with(['submitted' => 'SUBMITTED', 'in progress' => 'IN_PROGRESS']);

/*
|--------------------------------------------------------------------------
| INDIVIDUAL tasks through the project screen
|--------------------------------------------------------------------------
*/

it('refuses Complete task through the project screen to an all-data assignee of an individual task, and lets a reviewer off the crew complete it', function () {
    $mia = selfrevEmployee('Mia');
    $ben = selfrevEmployee('Ben');
    $task = selfrevIndividualTask([[$mia, 'APPROVED'], [$ben, 'APPROVED']]);
    selfrevActor(['PROJECTS'], allData: true, employeeId: $mia->id);

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->call('review', 'COMPLETED')
        ->assertSet('reviewOpen', true);

    $task->refresh();
    expect($task->status)->toBe('IN_PROGRESS')
        ->and($task->completed_at)->toBeNull()
        ->and($task->getAttribute('reviewed_by'))->toBeNull();
    expect(AuditLog::query()->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);

    // The same screen, a reviewer off the crew: every response is approved,
    // so the map's IN_PROGRESS → COMPLETED goes through.
    $reviewer = selfrevActor(['PROJECTS'], allData: true, employeeId: selfrevEmployee('Rita')->id);
    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->call('review', 'COMPLETED')
        ->assertSet('reviewOpen', false);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED')
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->getAttribute('reviewed_by'))->toBe($reviewer->getKey());
    expect(AuditLog::query()->where('action', 'TASK_COMPLETED')->where('entity_id', $task->id)->count())->toBe(1);
});

it('refuses an all-data assignee a decision on a co-assignee\'s response through the project screen', function () {
    $mia = selfrevEmployee('Mia');
    $ben = selfrevEmployee('Ben');
    $task = selfrevIndividualTask([[$mia, 'APPROVED'], [$ben, 'SUBMITTED']]);
    $response = ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', $ben->id)->firstOrFail();
    selfrevActor(['PROJECTS'], allData: true, employeeId: $mia->id);

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->set('responseRemark', 'Fix the totals')
        ->call('reviewResponse', 'APPROVED', $response->id, 1)
        ->call('reviewResponse', 'RETURNED', $response->id, 1);

    $response->refresh();
    expect($response->status)->toBe('SUBMITTED')
        ->and($response->revision)->toBe(1)
        ->and($response->reviewed_by)->toBeNull();
    expect(AuditLog::query()->count())->toBe(0);
    expect(Notification::query()->count())->toBe(0);
});

it('hides Complete and every response decision from an assignee-reviewer of an individual task, keeping Cancel and the callout', function () {
    $mia = selfrevEmployee('Mia');
    $ben = selfrevEmployee('Ben');
    $task = selfrevIndividualTask([[$mia, 'APPROVED'], [$ben, 'SUBMITTED']]);
    selfrevActor(['PROJECTS'], allData: true, employeeId: $mia->id);

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->call('selectResponse', $ben->id)
        ->assertDontSee('Complete Task')
        ->assertDontSee('Accept Task')
        ->assertDontSee('Approve Response')
        ->assertDontSee('Return for Rework')
        ->assertSee('Cancel Task')
        ->assertSee("You're one of this task's assignees, so another reviewer has to decide it.", false);
});

it('shows no decision on an individual task to a viewer without all-data PROJECTS', function () {
    $ana = selfrevEmployee('Ana');
    $ben = selfrevEmployee('Ben');
    $task = selfrevIndividualTask([[$ana, 'SUBMITTED'], [$ben, 'SUBMITTED']]);
    selfrevActor(['PROJECTS'], allData: false, employeeId: $ana->id);

    Livewire::test(Show::class, ['project' => $task->project_id])
        ->call('openTask', $task->id)
        ->assertSet('openTaskId', $task->id)
        ->call('selectResponse', $ana->id)
        ->assertDontSee('Complete Task')
        ->assertDontSee('Approve Response')
        ->assertDontSee('Return for Rework')
        ->assertDontSee('Cancel Task')
        ->assertDontSee('so another reviewer has to decide it');
});
