<?php

declare(strict_types=1);

use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\TaskComment;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\MoveTaskToGroup;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\SaveTaskGroup;
use App\Modules\WorkManagement\Application\Commands\StartTask;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(function (): void {
    if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), [':memory:', 'ems_testing'], true)) {
        throw new RuntimeException('Only the guarded synthetic database may be cleaned.');
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::table('project_tasks')->delete();
    DB::table('project_task_groups')->delete();
    DB::table('projects')->delete();
});

it('reruns hierarchy schema and reverses empty hierarchy without rewriting work history or grouping', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $worker = cpaUser($employee);
    $peerEmployee = cpaEmployee('Peer');
    $peer = cpaUser($peerEmployee);
    $project = cpaProject($owner, cpaClient($owner, [$worker, $peer]));
    $task = cpaTask($owner, $project, [$employee, $peerEmployee], 'INDIVIDUAL');
    $actor = Access::forUser($owner);
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Retained group');
    app(MoveTaskToGroup::class)->handle($actor, $project->id, $task->id, $group->id, null);
    app(StartTask::class)->handle(Access::forUser($worker), $task->id);
    app(SubmitTask::class)->handle(Access::forUser($worker), $task->id, 'Retained private response');
    $response = ProjectTaskSubmission::query()->where('task_id', $task->id)->sole();
    app(ReviewTaskResponse::class)->handle($actor, $project->id, $task->id, $response->id, 'APPROVED', 'Retained private review', 1);
    $file = new ProjectTaskFile;
    $file->forceFill(['task_id' => $task->id, 'kind' => 'BRIEF', 'file_name' => 'history.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/history.pdf', 'uploaded_by' => $owner->id])->save();
    $comment = new TaskComment;
    $comment->forceFill(['task_id' => $task->id, 'side' => 'MANAGEMENT', 'body' => 'Retain this history', 'author_id' => $owner->id, 'author_name' => 'Reviewer'])->save();
    $tables = ['projects', 'project_tasks', 'project_task_groups', 'project_task_assignees', 'project_task_submissions', 'project_task_files', 'task_comments'];
    $columns = [];
    foreach ($tables as $table) {
        $columns[$table] = array_values(array_diff(Schema::getColumnListing($table), ['parent_id']));
    }
    $snapshot = static fn (): array => array_map(static fn (string $table): string => DB::table($table)->select($columns[$table])->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    $migration = require database_path('migrations/2026_11_01_0002_add_project_subtasks.php');
    try {
        $migration->up();
        $migration->up();
        $migration->down();
        $migration->down();
        expect($snapshot())->toBe($before)->and(Schema::hasColumn('project_tasks', 'parent_id'))->toBeFalse();
        $migration->up();
        $migration->up();
        expect($snapshot())->toBe($before)->and($task->fresh()->parent_id)->toBeNull();
        if (DB::connection()->getDriverName() === 'sqlite') {
            expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
        }
    } finally {
        $migration->up();
    }
});

it('enforces same-project parent links and deletes the full family through project deletion', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client, 'Family');
    $other = cpaProject($owner, $client, 'Other');
    $actor = Access::forUser($owner);
    $parent = cpaTask($owner, $project, []);
    $child = app(CreateTask::class)->handle($actor, $project->id, new TaskDraft('Child', '', 'NORMAL', '', [], parentId: $parent->id));
    $otherTask = cpaTask($owner, $other, []);
    expect(fn () => DB::table('project_tasks')->where('id', $otherTask->id)->update(['parent_id' => $parent->id]))->toThrow(QueryException::class);
    expect(fn () => DB::table('project_tasks')->where('id', $child->id)->update(['parent_id' => 'missing']))->toThrow(QueryException::class);
    $migration = require database_path('migrations/2026_11_01_0002_add_project_subtasks.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'while subtasks exist');
    app(DeleteProject::class)->handle($actor, $project->id);
    expect(ProjectTask::query()->whereKey($parent->id)->exists())->toBeFalse()
        ->and(ProjectTask::query()->whereKey($child->id)->exists())->toBeFalse()
        ->and($otherTask->fresh()->parent_id)->toBeNull();
});

it('sees hand-in or evidence committed after the creator opened its snapshot', function (string $kind) {
    $owner = cpaUser();
    $first = cpaEmployee('First');
    $second = cpaEmployee('Second');
    $worker = cpaUser($first, ['TASKS']);
    $peer = cpaUser($second, ['TASKS']);
    $project = cpaProject($owner, cpaClient($owner, [$worker, $peer]));
    $parent = cpaTask($owner, $project, [$first, $second], $kind === 'individual' ? 'INDIVIDUAL' : 'GROUP');
    app(StartTask::class)->handle(Access::forUser($worker), $parent->id);
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $committed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$committed, $other, $parent, $first, $worker, $kind): void {
        if ($committed || ! str_contains($sql, '`project_tasks`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $committed = true;
        $at = now()->format('Y-m-d H:i:s.v');
        $other->beginTransaction();
        $lock = $other->prepare('SELECT id FROM project_tasks WHERE id = ? FOR UPDATE');
        $lock->execute([$parent->id]);
        if ($kind === 'group') {
            $write = $other->prepare("UPDATE project_tasks SET status = 'SUBMITTED', submitted_by = ?, submitted_at = ?, remarks = 'Concurrent group hand-in' WHERE id = ?");
            $write->execute([$first->id, $at, $parent->id]);
        } elseif ($kind === 'individual') {
            $write = $other->prepare("INSERT INTO project_task_submissions (id, task_id, employee_id, status, remarks, submitted_at, revision, created_at) VALUES (?, ?, ?, 'SUBMITTED', 'Private concurrent response', ?, 1, ?)");
            $write->execute([(string) Str::ulid(), $parent->id, $first->id, $at, $at]);
        } else {
            $write = $other->prepare("INSERT INTO project_task_files (id, task_id, kind, file_name, mime, size, path, uploaded_by, employee_id, created_at) VALUES (?, ?, 'SUBMISSION', 'concurrent.pdf', 'application/pdf', 5, 'task-files/concurrent.pdf', ?, ?, ?)");
            $write->execute([(string) Str::ulid(), $parent->id, $worker->id, $first->id, $at]);
        }
        $other->commit();
    });
    DB::beginTransaction();
    DB::table('project_tasks')->where('id', $parent->id)->first();
    expect(fn () => app(CreateTask::class)->handle(Access::forUser($owner), $project->id, new TaskDraft('Late scope', '', 'NORMAL', '', [], parentId: $parent->id)))->toThrow(RuntimeException::class, 'handed in or closed');
    DB::rollBack();
    expect($committed)->toBeTrue()->and(ProjectTask::query()->whereNotNull('parent_id')->count())->toBe(0);
})->with(['group', 'individual', 'evidence'])->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires real MariaDB snapshots.');
