<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\ProjectMember;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Commands\ChangeTaskStatus;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\SaveTaskGroup;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\Commands\UpdateProject;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectMemberView;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Support\Access;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('adds project metadata separately from task visibility and never widens My Tasks', function (string $role, int $visible) {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $member = cpaUser($employee);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $other = cpaProject($owner, $client, 'Other');
    $own = cpaTask($owner, $project, [$employee]);
    cpaTask($owner, $project, []);
    cpaTask($owner, $other, []);
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $member->id, $role, 'Designer');
    $actor = Access::forUser($member);
    expect(WorkScope::projects($actor)->pluck('projects.id')->all())->toBe([$project->id])
        ->and(WorkScope::tasks($actor)->count())->toBe($visible)
        ->and(WorkScope::tasks($actor, true)->pluck('project_tasks.id')->all())->toBe([$own->id]);
})->with([['PARTICIPANT', 1], ['VIEWER', 2], ['EDITOR', 2]]);

it('gives an unassigned participant project details without hidden task totals', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    cpaTask($owner, $project, []);
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $member->id, 'PARTICIPANT');
    $actor = Access::forUser($member);
    expect(app(WorkRecords::class)->project($actor, $project->id))->not->toBeNull()
        ->and(WorkScope::tasks($actor)->count())->toBe(0);
});

it('keeps client and module access as prerequisites for project membership', function () {
    $owner = cpaUser();
    $outsider = cpaUser();
    $tasksOnly = cpaUser(cpaEmployee(), ['TASKS']);
    $client = cpaClient($owner, [$tasksOnly]);
    $project = cpaProject($owner, $client);
    $set = app(SetProjectMember::class);
    $actor = Access::forUser($owner);
    expect(fn () => $set->handle($actor, $project->id, $outsider->id, 'EDITOR'))->toThrow(ValidationException::class);
    expect(fn () => $set->handle($actor, $project->id, $tasksOnly->id, 'VIEWER'))->toThrow(ValidationException::class);
    $set->handle($actor, $project->id, $tasksOnly->id, 'PARTICIPANT');
    expect(Access::forUser($tasksOnly)->hasModule(ModuleCode::PROJECTS))->toBeFalse();
});

it('revokes project roles immediately when client access is removed and preserves records', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $member = cpaUser($employee);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee]);
    $actor = Access::forUser($owner);
    app(SetProjectMember::class)->handle($actor, $project->id, $member->id, 'EDITOR');
    $before = $task->fresh()->getRawOriginal();
    app(SetClientMember::class)->handle($actor, $client->id, $member->id, false);
    expect(WorkScope::projects(Access::forUser($member))->count())->toBe(0)
        ->and(WorkScope::tasks(Access::forUser($member), true)->count())->toBe(0)
        ->and($task->fresh()->getRawOriginal())->toBe($before)
        ->and(ProjectMember::query()->count())->toBe(1);
});

it('allows delegated task planning without reviews status settings or team administration', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $editor = cpaUser($employee);
    $worker = cpaUser(cpaEmployee('Worker'), ['TASKS']);
    $client = cpaClient($owner, [$editor, $worker]);
    $project = cpaProject($owner, $client);
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $editor->id, 'EDITOR');
    $actor = Access::forUser($editor);
    $task = app(CreateTask::class)->handle($actor, $project->id, new TaskDraft('Delegated', '', 'NORMAL', '2026-11-20', [$worker->employee_id]));
    app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Delivery');
    app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskDetails('HIGH', '2026-11-21', [$worker->employee_id]));
    expect(WorkRules::reviewsTask($actor, $task))->toBeFalse()->and(WorkRules::runsTask($actor, $task))->toBeFalse();
    expect(fn () => app(ReviewTask::class)->handle($actor, $project->id, $task->id, 'ACCEPT', ''))->toThrow(RuntimeException::class);
    expect(fn () => app(SetProjectMember::class)->handle($actor, $project->id, $worker->id, 'PARTICIPANT'))->toThrow(RuntimeException::class);
    expect(fn () => app(ChangeTaskStatus::class)->handle($actor, $project->id, $task->id, 'IN_PROGRESS', '', 'PENDING'))->toThrow(RuntimeException::class);
    expect(fn () => app(UpdateProject::class)->handle($actor, $project->id, new ProjectDraft('Changed', '', 'NONE', 'ACTIVE', '2026-01-01', '2026-12-31', (string) $owner->id, $client->id)))->toThrow(RuntimeException::class);
});

it('preserves handed-in responses when an editor attempts to change the crew', function () {
    $owner = cpaUser();
    $editor = cpaUser();
    $first = cpaUser(cpaEmployee('First'), ['TASKS']);
    $second = cpaUser(cpaEmployee('Second'), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$editor, $first, $second]));
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $editor->id, 'EDITOR');
    $task = cpaTask($owner, $project, [$first->employee, $second->employee], 'INDIVIDUAL');
    app(StartTask::class)->handle(Access::forUser($first), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($first), $task->id, 'Private response');
    $before = ProjectTaskSubmission::query()->firstOrFail()->getRawOriginal();
    expect(fn () => app(UpdateTaskDetails::class)->handle(Access::forUser($editor), $project->id, $task->id, new TaskDetails('NORMAL', null, [$second->employee_id])))->toThrow(RuntimeException::class, 'project manager');
    expect(ProjectTaskSubmission::query()->firstOrFail()->getRawOriginal())->toBe($before)
        ->and($task->fresh()->assignments)->toHaveCount(2);
});

it('removes explicit membership without deleting assignments or inherited lead rights', function () {
    $owner = cpaUser();
    $member = cpaUser(cpaEmployee());
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    $task = cpaTask($owner, $project, [$member->employee]);
    $set = app(SetProjectMember::class);
    $set->handle(Access::forUser($owner), $project->id, $member->id, 'EDITOR');
    $set->handle(Access::forUser($owner), $project->id, $member->id, null);
    expect(WorkScope::tasks(Access::forUser($member))->count())->toBe(1)
        ->and(WorkRules::editsWork(Access::forUser($member), $project))->toBeFalse()
        ->and($task->fresh()->assignments)->toHaveCount(1);
    $set->handle(Access::forUser($owner), $project->id, $owner->id, 'VIEWER');
    $set->handle(Access::forUser($owner), $project->id, $owner->id, null);
    expect(WorkRules::managesProject(Access::forUser($owner), $project))->toBeTrue();
});

it('validates role and position and returns only detached scoped team labels', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $outsider = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    $actor = Access::forUser($owner);
    $set = app(SetProjectMember::class);
    expect(fn () => $set->handle($actor, $project->id, $member->id, 'ADMIN'))->toThrow(ValidationException::class);
    expect(fn () => $set->handle($actor, $project->id, $member->id, 'VIEWER', str_repeat('x', 81)))->toThrow(ValidationException::class);
    expect($set->handle($actor, $project->id, $member->id, 'VIEWER', '  Designer  '))->toBeTrue()
        ->and($set->handle($actor, $project->id, $member->id, 'VIEWER', 'Designer'))->toBeFalse();
    $row = app(ProjectPeople::class)->members(Access::forUser($member), $project->id)->first();
    expect($row)->toBeInstanceOf(ProjectMemberView::class)
        ->and($row->position)->toBe('Designer');
    expect(fn () => app(ProjectPeople::class)->members(Access::forUser($outsider), $project->id))->toThrow(HttpException::class);
});
