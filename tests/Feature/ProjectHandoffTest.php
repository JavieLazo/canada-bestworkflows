<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\RoleModuleGrant;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Platform\Notifications\NotificationRecipients;
use App\Support\Access;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('hands editor-created submissions to the current project reviewer without exposing individual notes', function (string $mode) {
    $owner = cpaUser();
    $lead = cpaUser();
    $editor = cpaUser();
    $worker = cpaUser(cpaEmployee('Worker'), ['TASKS']);
    $peer = cpaUser(cpaEmployee('Peer'), ['TASKS']);
    $client = cpaClient($owner, [$lead, $editor, $worker, $peer]);
    $project = cpaProject($owner, $client);
    $project->update(['manager_id' => $lead->id]);
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $editor->id, 'EDITOR');
    $task = cpaTask($editor, $project, [$worker->employee, $peer->employee], $mode);
    app(StartTask::class)->handle(Access::forUser($worker), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $task->id, 'Hand-in note from worker');
    $kind = $mode === 'INDIVIDUAL' ? 'TASK_RESPONSE_SUBMITTED' : 'TASK_SUBMITTED';
    $notice = Notification::query()->where('user_id', $lead->id)->where('kind', $kind)->first();
    expect($notice)->not->toBeNull();
    expect($notice->href)->toBe('/projects/'.$project->id.'?task='.$task->id)
        ->and($notice->title)->toContain($task->title, $task->code);
    if ($mode === 'INDIVIDUAL') {
        expect(Notification::query()->where('kind', $kind)->pluck('body')->implode(' '))->not->toContain('Hand-in note from worker');
    }
})->with(['GROUP', 'INDIVIDUAL']);

it('deduplicates assigner lead and owner notices and leaves group crew awareness intact', function () {
    $owner = cpaUser();
    $worker = cpaUser(cpaEmployee('Worker'), ['TASKS']);
    $peer = cpaUser(cpaEmployee('Peer'), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker, $peer]));
    $task = cpaTask($owner, $project, [$worker->employee, $peer->employee]);
    app(StartTask::class)->handle(Access::forUser($worker), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $task->id, 'Shared response');
    $notices = Notification::query()->where('kind', 'TASK_SUBMITTED')->get();
    expect($notices)->toHaveCount(2)->and($notices->where('user_id', $owner->id))->toHaveCount(1)
        ->and($notices->where('user_id', $worker->id))->toHaveCount(0)
        ->and($notices->firstWhere('user_id', $peer->id)->href)->toBe('/my-tasks?task='.$task->id);
});

it('keeps a removed editor out of subsequent notices while notifying the current reviewer', function () {
    $owner = cpaUser();
    $editor = cpaUser();
    $worker = cpaUser(cpaEmployee(), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$editor, $worker]));
    $set = app(SetProjectMember::class);
    $set->handle(Access::forUser($owner), $project->id, $editor->id, 'EDITOR');
    $task = cpaTask($editor, $project, [$worker->employee]);
    $set->handle(Access::forUser($owner), $project->id, $editor->id, null);
    app(StartTask::class)->handle(Access::forUser($worker), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $task->id, 'Shared response');
    expect(Notification::query()->where('kind', 'TASK_SUBMITTED')->where('user_id', $editor->id)->count())->toBe(0)
        ->and(Notification::query()->where('kind', 'TASK_SUBMITTED')->where('user_id', $owner->id)->count())->toBe(1);
});

it('does not send review notices to a lead on the crew or an account without current client access', function () {
    $owner = cpaUser();
    $lead = cpaUser(cpaEmployee('Lead'));
    $worker = cpaUser(cpaEmployee(), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$lead, $worker]));
    $project->update(['manager_id' => $lead->id]);
    $task = cpaTask($owner, $project, [$worker->employee, $lead->employee], 'INDIVIDUAL');
    app(StartTask::class)->handle(Access::forUser($worker), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $task->id, 'Private note');
    expect(Notification::query()->where('kind', 'TASK_RESPONSE_SUBMITTED')->where('user_id', $lead->id)->count())->toBe(0);
    $other = cpaTask($owner, $project, [$worker->employee], title: 'Revoked lead task');
    app(SetClientMember::class)->handle(Access::forUser($owner), $project->client_id, $lead->id, false);
    app(StartTask::class)->handle(Access::forUser($worker), $other->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $other->id, 'Shared note');
    expect(Notification::query()->where('kind', 'TASK_SUBMITTED')->where('user_id', $lead->id)->count())->toBe(0);
});

it('filters inactive or module-revoked reviewers and keeps task events fail-soft', function () {
    $owner = cpaUser();
    $lead = cpaUser();
    $worker = cpaUser(cpaEmployee(), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$lead, $worker]));
    $project->update(['manager_id' => $lead->id]);
    $task = cpaTask($owner, $project, [$worker->employee]);
    RoleModuleGrant::query()->where('role_id', $lead->role_id)->where('module', 'PROJECTS')->delete();
    app(StartTask::class)->handle(Access::forUser($worker), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $task->id, 'Ready');
    expect(Notification::query()->where('kind', 'TASK_SUBMITTED')->where('user_id', $lead->id)->count())->toBe(0);
    $lead->update(['is_active' => false]);
    expect(iterator_to_array(app(NotificationRecipients::class)->accounts([$lead->id])))->toBe([]);
    $next = cpaTask($owner, $project, [$worker->employee]);
    app(StartTask::class)->handle(Access::forUser($worker), $next->id);
    app()->instance(NotificationRecipients::class, Mockery::mock(NotificationRecipients::class)
        ->shouldReceive('accounts')->andThrow(new RuntimeException('Synthetic delivery failure'))->getMock());
    expect(app(SubmitTask::class)->handle(Access::forUser($worker), $next->id, 'Still committed'))->toBeTrue()
        ->and($next->fresh()->status)->toBe('SUBMITTED');
});
