<?php

declare(strict_types=1);

use App\Domain\Projects\TaskPolicy;
use App\Livewire\Projects\Show;
use App\Livewire\Tasks\MyTasksPage;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\ProjectTaskFile;
use App\Models\TaskComment;
use App\Modules\WorkManagement\Application\Commands\SetClientMember;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskContent;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Support\Access;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('accepts multibyte briefs at the shared character limits on creation and editing', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $title = str_repeat('界', TaskPolicy::TITLE_LIMIT);
    $description = str_repeat('界', TaskPolicy::DESCRIPTION_LIMIT);
    $task = cpaTask($owner, $project, [], title: $title);
    expect(app(UpdateTaskContent::class)->handle(Access::forUser($owner), $project->id, $task->id, $title, $description, $title, ''))->toBeTrue()
        ->and($task->fresh()->description)->toBe($description);
});

it('updates a brief with its exact prior version and preserves task ownership and scheduling', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $member = cpaUser($employee, ['TASKS']);
    $outsider = cpaUser($employee, ['TASKS']);
    $client = cpaClient($owner, [$member]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee]);
    $task->update(['description' => "Original scope\nKeep this detail."]);
    $before = $task->getRawOriginal();
    $assignments = $task->assignments()->get()->toArray();

    expect(app(UpdateTaskContent::class)->handle(Access::forUser($owner), $project->id, $task->id, '  New title  ', "  New scope\nSecond line.  ", $task->title, (string) $task->description))->toBeTrue();
    $after = $task->fresh()->getRawOriginal();
    foreach (['title', 'description', 'updated_at'] as $column) {
        unset($before[$column], $after[$column]);
    }
    expect($after)->toBe($before)->and($task->fresh()->title)->toBe('New title')
        ->and($task->fresh()->description)->toBe("New scope\nSecond line.")
        ->and($task->assignments()->get()->toArray())->toBe($assignments);
    $comment = TaskComment::query()->sole();
    expect($comment->event)->toBe('BRIEF_UPDATED')->and($comment->author_id)->toBe($owner->id)
        ->and($comment->body)->toBe($task->title."\n\n".$task->description);
    $audit = AuditLog::query()->where('action', 'TASK_UPDATED')->sole();
    expect($audit->details)->toContain($task->code)->not->toContain('Original scope')->not->toContain('New scope');
    expect(Notification::query()->where('user_id', $member->id)->where('kind', 'TASK_UPDATED')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $outsider->id)->count())->toBe(0);
    expect(app(WorkRecords::class)->comments(Access::forUser($outsider), [$task->id]))->toBeEmpty();
    cpaLogin($member);
    Livewire::test(MyTasksPage::class)->call('openTask', $task->id)->assertSee('New title')->assertSee('Original scope');
});

it('leaves no history or notification for an unchanged brief and accepts clearing the description', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $actor = Access::forUser($owner);
    $command = app(UpdateTaskContent::class);
    expect($command->handle($actor, $project->id, $task->id, $task->title, '', $task->title, ''))->toBeFalse();
    expect(TaskComment::query()->count())->toBe(0)->and(AuditLog::query()->where('action', 'TASK_UPDATED')->count())->toBe(0);
    $task->update(['description' => 'Clear me']);
    expect($command->handle($actor, $project->id, $task->id, $task->title, '   ', $task->title, 'Clear me'))->toBeTrue()
        ->and($task->fresh()->description)->toBeNull();
});

it('rejects invalid brief text before writing', function (string $title, string $description) {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    expect(fn () => app(UpdateTaskContent::class)->handle(Access::forUser($owner), $project->id, $task->id, $title, $description, $task->title, ''))->toThrow(RuntimeException::class);
    expect($task->fresh()->title)->toBe($task->title)->and(TaskComment::query()->count())->toBe(0);
})->with(['blank title' => ['  ', ''], 'long title' => [str_repeat('a', 301), ''], 'long description' => ['Valid', str_repeat('a', 8001)]]);

it('keeps a concurrent brief edit instead of overwriting it', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $command = app(UpdateTaskContent::class);
    $actor = Access::forUser($owner);
    $command->handle($actor, $project->id, $task->id, 'First edit', 'Latest description', $task->title, '');
    expect(fn () => $command->handle($actor, $project->id, $task->id, 'Second edit', '', $task->title, ''))->toThrow(RuntimeException::class, 'changed while you were editing');
    expect($task->fresh()->title)->toBe('First edit')->and($task->fresh()->description)->toBe('Latest description')
        ->and(TaskComment::query()->count())->toBe(1);
});

it('allows no brief changes after a hand-in, evidence or a closed state', function (string $lock) {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $peer = cpaEmployee('Peer');
    $member = cpaUser($employee, ['TASKS']);
    $other = cpaUser($peer, ['TASKS']);
    $client = cpaClient($owner, [$member, $other]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, [$employee, $peer], $lock === 'individual' ? 'INDIVIDUAL' : 'GROUP');
    if (in_array($lock, ['group', 'individual'], true)) {
        app(StartTask::class)->handle(Access::forUser($member), $task->id);
        app(SubmitTask::class)->handle(Access::forUser($member), $task->id, 'Keep my submitted work');
    } elseif ($lock === 'evidence') {
        ProjectTaskFile::create(['task_id' => $task->id, 'kind' => 'SUBMISSION', 'file_name' => 'evidence.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/evidence.pdf', 'uploaded_by' => $member->id, 'employee_id' => $employee->id]);
    } else {
        $task->update(['status' => $lock]);
    }
    $before = $task->fresh()->getRawOriginal();
    $history = TaskComment::query()->count();
    expect(fn () => app(UpdateTaskContent::class)->handle(Access::forUser($owner), $project->id, $task->id, 'Changed', '', $task->title, ''))->toThrow(RuntimeException::class, 'brief is locked');
    expect($task->fresh()->getRawOriginal())->toBe($before)->and(TaskComment::query()->count())->toBe($history);
    cpaLogin($owner);
    Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id)->call('editContent')->assertSet('editingContent', false)->assertDontSeeHtml('aria-label="Edit task title and description"');
})->with(['group', 'individual', 'evidence', 'RETURNED', 'COMPLETED', 'CANCELLED']);

it('refuses leaders, assignees, outsiders and revoked managers without changing a brief', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $leader = cpaUser();
    $member = cpaUser($employee);
    $outsider = cpaUser(allData: true);
    $manager = cpaUser(allData: true);
    $client = cpaClient($owner, [$leader, $member, $manager]);
    $project = cpaProject($owner, $client);
    $project->update(['manager_id' => $leader->id]);
    $task = cpaTask($owner, $project, [$employee]);
    app(SetClientMember::class)->handle(Access::forUser($owner), $client->id, $manager->id, false);
    foreach ([$leader, $member, $outsider, $manager] as $user) {
        expect(fn () => app(UpdateTaskContent::class)->handle(Access::forUser($user), $project->id, $task->id, 'Unauthorized', '', $task->title, ''))->toThrow(RuntimeException::class);
    }
    expect($task->fresh()->title)->toBe($task->title)->and(TaskComment::query()->count())->toBe(0);
});

it('retains an invalid or stale draft in the editor and saves a current draft', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    cpaLogin($owner);
    $page = Livewire::test(Show::class, ['project' => $project->id])->call('openTask', $task->id)->call('editContent')
        ->assertSet('seenContentTitle', $task->title)->set('contentTitle', '')->call('saveContent')->assertSet('editingContent', true);
    $page->set('contentTitle', 'Ready for launch')->set('contentDescription', 'Scope')->call('saveContent')->assertSet('editingContent', false)->assertSee('Ready for launch');
    $page->call('editContent');
    $task->refresh()->update(['description' => 'Updated elsewhere']);
    $page->set('contentDescription', 'My draft')->call('saveContent')->assertSet('editingContent', true)->assertSet('contentDescription', 'My draft');
    expect($task->fresh()->description)->toBe('Updated elsewhere');
});

it('does not trust a browser-supplied expected brief', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    cpaLogin($owner);
    expect(fn () => Livewire::test(Show::class, ['project' => $project->id])->set('seenContentTitle', 'Forged'))->toThrow(CannotUpdateLockedPropertyException::class);
});
