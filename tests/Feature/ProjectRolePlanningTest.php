<?php

declare(strict_types=1);

use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\CommentOnProjectTask;
use App\Modules\WorkManagement\Application\Commands\ImportTasks;
use App\Modules\WorkManagement\Application\Commands\SetProjectMember;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\Transfer\ImportOutcome;
use App\Platform\Transfer\ImportRow;
use App\Support\Access;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('uses the project employee chooser for editor CSV planning without directory administration', function () {
    $owner = cpaUser();
    $editor = cpaUser();
    $worker = cpaUser(cpaEmployee(), ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$editor, $worker]));
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $editor->id, 'EDITOR');
    $row = new ImportRow(2, ['Code' => '', 'Task' => 'Imported plan', 'Priority' => 'High', 'Status' => 'Pending', 'Start date' => '2026-11-02', 'Due date' => '2026-11-20', 'Assignees' => $worker->employee->employee_code]);
    expect(app(ImportTasks::class)->row(Access::forUser($editor), $project->id, $row))->toBe(ImportOutcome::CREATED)
        ->and(ProjectTask::query()->sole()->assigneeIds())->toBe([$worker->employee_id]);
});

it('requires current Project Management access for a retained viewer or editor role', function (string $role) {
    $owner = cpaUser();
    $member = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    cpaTask($owner, $project, []);
    app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $member->id, $role);
    RoleModuleGrant::query()->where('role_id', $member->role_id)->where('module', 'PROJECTS')->delete();
    $actor = Access::forUser($member->fresh());
    expect(WorkScope::tasks($actor)->count())->toBe(0)->and(WorkRules::editsWork($actor, $project))->toBeFalse();
})->with(['VIEWER', 'EDITOR']);

it('pages a larger project team before resolving account labels', function () {
    $owner = cpaUser();
    $people = array_map(static fn (): User => cpaUser(), range(1, 22));
    $project = cpaProject($owner, cpaClient($owner, $people));
    foreach ($people as $person) {
        app(SetProjectMember::class)->handle(Access::forUser($owner), $project->id, $person->id, 'VIEWER');
    }
    $query = app(ProjectPeople::class);
    $first = $query->members(Access::forUser($owner), $project->id);
    $second = $query->members(Access::forUser($owner), $project->id, 2);
    expect($first->total())->toBe(22)->and($first->count())->toBe(20)->and($second->count())->toBe(2)
        ->and(array_intersect($first->pluck('id')->all(), $second->pluck('id')->all()))->toBe([]);
    expect(array_intersect(array_map(static fn (User $person): int => $person->id, $people), $query->candidates(Access::forUser($owner), $project->id)->pluck('id')->all()))->toBe([]);
});

it('lets an editor discuss shared work and refuses a viewer after the role changes', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    $task = cpaTask($owner, $project, []);
    $set = app(SetProjectMember::class);
    $set->handle(Access::forUser($owner), $project->id, $member->id, 'EDITOR');
    $comment = app(CommentOnProjectTask::class);
    expect($comment->handle(Access::forUser($member), $project->id, $task->id, 'Shared clarification'))->toBeTrue();
    expect(TaskComment::query()->sole()->side)->toBe('MANAGEMENT');
    $set->handle(Access::forUser($owner), $project->id, $member->id, 'VIEWER');
    expect(fn () => $comment->handle(Access::forUser($member), $project->id, $task->id, 'Refuse this'))->toThrow(RuntimeException::class);
    expect(TaskComment::query()->count())->toBe(1);
});

it('refuses a stale add without replacing an existing project role or position', function () {
    $owner = cpaUser();
    $member = cpaUser();
    $project = cpaProject($owner, cpaClient($owner, [$member]));
    $set = app(SetProjectMember::class);
    $actor = Access::forUser($owner);
    $set->handle($actor, $project->id, $member->id, 'VIEWER', 'Design reviewer');
    $before = ProjectMember::query()->sole()->getRawOriginal();
    expect(fn () => $set->handle($actor, $project->id, $member->id, 'PARTICIPANT', '', mustBeNew: true))->toThrow(ValidationException::class);
    expect(ProjectMember::query()->sole()->getRawOriginal())->toBe($before);
});
