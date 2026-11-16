<?php

declare(strict_types=1);

use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\TaskDateEdit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('patches only dates while preserving crew, individual reviews, evidence and hierarchy', function () {
    $owner = cpaUser();
    $first = cpaEmployee('First');
    $second = cpaEmployee('Second');
    $worker = cpaUser($first, ['TASKS']);
    $peer = cpaUser($second, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker, $peer]));
    $parent = cpaTask($owner, $project, [], title: 'Retained parent');
    $task = cpaTask($owner, $project, [$first, $second], 'INDIVIDUAL');
    $task->forceFill(['parent_id' => $parent->id])->save();
    $parentBefore = $parent->getRawOriginal();
    $actor = Access::forUser($owner);
    app(StartTask::class)->handle(Access::forUser($worker), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $task->id, 'Retained private hand-in');
    $response = ProjectTaskSubmission::query()->sole();
    app(ReviewTaskResponse::class)->handle($actor, $project->id, $task->id, $response->id, 'APPROVED', 'Retained review', 1);
    $file = new ProjectTaskFile;
    $file->forceFill(['task_id' => $task->id, 'kind' => 'SUBMISSION', 'file_name' => 'retained.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/retained.pdf', 'employee_id' => $first->id, 'uploaded_by' => $worker->id])->save();
    $tables = ['project_task_assignees', 'project_task_submissions', 'project_task_files', 'task_comments', 'notifications'];
    $before = array_map(static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $original = $task->fresh()->getRawOriginal();
    unset($original['start_date'], $original['due_date'], $original['updated_at']);
    app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskDateEdit('2026-11-10', '2026-11-21', '', '2026-11-20'));
    $after = $task->fresh()->getRawOriginal();
    unset($after['start_date'], $after['due_date'], $after['updated_at']);
    expect($after)->toBe($original)->and($parent->fresh()->getRawOriginal())->toBe($parentBefore)->and($task->fresh()->start_date->format('Y-m-d'))->toBe('2026-11-10')
        ->and($task->fresh()->due_date->format('Y-m-d'))->toBe('2026-11-21')
        ->and(array_map(static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables))->toBe($before);
});

it('rejects stale date pairs and invalid dates without rewriting the task', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $task = cpaTask($owner, $project, []);
    $actor = Access::forUser($owner);
    $before = $task->getRawOriginal();
    foreach ([new TaskDateEdit('', '2026-11-22', '', '2026-11-19'), new TaskDateEdit('2026-11-23', '2026-11-22', '', '2026-11-20'), new TaskDateEdit('2026-02-30', '2026-11-22', '', '2026-11-20'), new TaskDateEdit('', '2027-01-01', '', '2026-11-20')] as $input) {
        expect(fn () => app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, $input))->toThrow(RuntimeException::class);
        expect($task->fresh()->getRawOriginal())->toBe($before);
    }
    $task->update(['status' => 'COMPLETED']);
    expect(fn () => app(UpdateTaskDetails::class)->handle($actor, $project->id, $task->id, new TaskDateEdit('', '2026-11-22', '', '2026-11-20')))->toThrow(RuntimeException::class, 'closed');
});

it('keeps lead planning start-only, refuses crew planning and fences private clients', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $lead = cpaUser($employee);
    $project = cpaProject($owner, cpaClient($owner, [$lead]));
    $project->update(['manager_id' => $lead->id]);
    $task = cpaTask($owner, $project, []);
    app(UpdateTaskDetails::class)->handle(Access::forUser($lead), $project->id, $task->id, new TaskDateEdit('2026-11-05', '2026-11-20', '', '2026-11-20'));
    expect($task->fresh()->start_date->format('Y-m-d'))->toBe('2026-11-05');
    expect(fn () => app(UpdateTaskDetails::class)->handle(Access::forUser($lead), $project->id, $task->id, new TaskDateEdit('2026-11-06', '2026-11-21', '2026-11-05', '2026-11-20')))->toThrow(RuntimeException::class);
    $task->syncAssignees([$employee->id], $owner->id);
    expect(fn () => app(UpdateTaskDetails::class)->handle(Access::forUser($lead), $project->id, $task->id, new TaskDateEdit('2026-11-06', '2026-11-20', '2026-11-05', '2026-11-20')))->toThrow(RuntimeException::class);
    expect(fn () => app(UpdateTaskDetails::class)->handle(Access::forUser(cpaUser(allData: true)), $project->id, $task->id, new TaskDateEdit('2026-11-06', '2026-11-20', '2026-11-05', '2026-11-20')))->toThrow(RuntimeException::class);
});
