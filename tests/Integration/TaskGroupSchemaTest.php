<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskGroup;
use App\Models\ProjectTaskSubmission;
use App\Models\TaskComment;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\MoveTaskToGroup;
use App\Modules\WorkManagement\Application\Commands\SaveTaskGroup;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

afterEach(function (): void {
    if (! app()->environment('testing') || ! in_array(DB::connection()->getDatabaseName(), [':memory:', 'ems_testing'], true)) {
        throw new RuntimeException('Only the guarded synthetic database may be cleaned.');
    }
    tgroupSchemaCleanup(array_values(Project::query()->get()->map(static fn (Project $project): string => $project->id)->all()));
});

/** @param list<string> $ids */
function tgroupSchemaCleanup(array $ids): void
{
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::table('project_tasks')->whereIn('project_id', $ids)->delete();
    if (Schema::hasTable('project_task_groups')) {
        DB::table('project_task_groups')->whereIn('project_id', $ids)->delete();
    }
    DB::table('projects')->whereIn('id', $ids)->delete();
}

it('reruns and reverses group schema without rewriting legacy tasks or their history', function () {
    $owner = cpaUser();
    $first = cpaEmployee('First');
    $second = cpaEmployee('Second');
    $project = cpaProject($owner, cpaClient($owner, [cpaUser($first), cpaUser($second)]));
    $task = cpaTask($owner, $project, [$first, $second], 'INDIVIDUAL');
    $response = new ProjectTaskSubmission;
    $response->forceFill(['task_id' => $task->id, 'employee_id' => $first->id, 'status' => 'APPROVED', 'submitted_at' => now(), 'remarks' => 'Retain this response', 'review_remarks' => 'Retain this decision', 'reviewed_by' => $owner->id, 'reviewed_at' => now(), 'revision' => 1])->save();
    $file = new ProjectTaskFile;
    $file->forceFill(['task_id' => $task->id, 'kind' => 'SUBMISSION', 'file_name' => 'legacy.pdf', 'mime' => 'application/pdf', 'size' => 5, 'path' => 'task-files/legacy.pdf', 'employee_id' => $first->id, 'uploaded_by' => $owner->id])->save();
    $comment = new TaskComment;
    $comment->forceFill(['task_id' => $task->id, 'side' => 'MANAGEMENT', 'author_id' => $owner->id, 'author_name' => 'Reviewer', 'body' => 'Retain the thread'])->save();
    $tables = ['project_tasks', 'project_task_assignees', 'project_task_submissions', 'project_task_files', 'task_comments'];
    $columns = [];
    foreach ($tables as $table) {
        $columns[$table] = array_values(array_diff(Schema::getColumnListing($table), ['group_id']));
    }
    $snapshot = static fn (): array => array_map(static fn (string $table): string => DB::table($table)->select($columns[$table])->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot();
    $migration = require database_path('migrations/2026_11_01_0001_add_project_task_groups.php');
    // A rollback runs newest first: the project migrations that came after
    // the groups point at them, so they go before the groups can and return
    // after them.
    $later = array_map(
        static fn (string $file): mixed => require database_path('migrations/'.$file.'.php'),
        ['2026_11_03_0005_add_project_funding', '2026_11_03_0004_add_project_reporting', '2026_11_03_0003_add_project_results', '2026_11_03_0002_add_project_capacity', '2026_11_03_0001_add_project_finance'],
    );
    try {
        $migration->up();
        $migration->up();
        foreach ($later as $newer) {
            $newer->down();
        }
        $migration->down();
        $migration->down();
        expect(Schema::hasColumn('project_tasks', 'group_id'))->toBeFalse()->and($snapshot())->toBe($before);
        $migration->up();
        $migration->up();
        expect($task->fresh()->group_id)->toBeNull()->and($snapshot())->toBe($before);
        if (DB::connection()->getDriverName() === 'sqlite') {
            expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
        }
    } finally {
        $migration->up();
        foreach (array_reverse($later) as $newer) {
            $newer->up();
        }
        tgroupSchemaCleanup([$project->id]);
    }
});

it('enforces same-project links and keeps project deletion compatible with groups', function () {
    $owner = cpaUser();
    $employee = cpaEmployee();
    $client = cpaClient($owner, [cpaUser($employee)]);
    $first = cpaProject($owner, $client, 'First');
    $second = cpaProject($owner, $client, 'Second');
    $actor = Access::forUser($owner);
    $group = app(SaveTaskGroup::class)->handle($actor, $first->id, null, 'Build');
    $task = cpaTask($owner, $first, [$employee]);
    $other = cpaTask($owner, $second, []);
    try {
        expect(fn () => DB::table('project_tasks')->where('id', $other->id)->update(['group_id' => $group->id]))->toThrow(QueryException::class);
        app(MoveTaskToGroup::class)->handle($actor, $first->id, $task->id, $group->id, null);
        expect(fn () => DB::table('project_task_groups')->where('id', $group->id)->delete())->toThrow(QueryException::class);
        app(DeleteProject::class)->handle($actor, $first->id);
        expect(DB::table('project_tasks')->where('id', $task->id)->exists())->toBeFalse()
            ->and(DB::table('project_task_assignees')->where('task_id', $task->id)->exists())->toBeFalse()
            ->and(DB::table('project_task_groups')->where('id', $group->id)->exists())->toBeFalse()
            ->and($other->fresh()->group_id)->toBeNull();
    } finally {
        tgroupSchemaCleanup([$first->id, $second->id]);
    }
});

it('refuses schema rollback while retained group data exists', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $group = app(SaveTaskGroup::class)->handle(Access::forUser($owner), $project->id, null, 'Keep');
    $migration = require database_path('migrations/2026_11_01_0001_add_project_task_groups.php');
    try {
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Keep task groups');
        expect(ProjectTaskGroup::query()->findOrFail($group->id)->name)->toBe('Keep');
    } finally {
        tgroupSchemaCleanup([$project->id]);
    }
});

/** Separate connection uses the same guarded disposable MariaDB target as the parent. */
function tgroupSchemaConnection(): PDO
{
    $config = DB::connection()->getConfig();

    return new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

it('sees a group deletion committed after the caller opened its snapshot', function () {
    $owner = cpaUser();
    $project = cpaProject($owner, cpaClient($owner));
    $actor = Access::forUser($owner);
    $group = app(SaveTaskGroup::class)->handle($actor, $project->id, null, 'Removed while waiting');
    $task = cpaTask($owner, $project, []);
    $other = tgroupSchemaConnection();
    $removed = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$removed, $other, $project, $group): void {
        if ($removed || ! str_contains($sql, '`project_tasks`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $removed = true;
        $other->beginTransaction();
        $lock = $other->prepare('SELECT id FROM projects WHERE id = ? FOR UPDATE');
        $lock->execute([$project->id]);
        $delete = $other->prepare('DELETE FROM project_task_groups WHERE id = ?');
        $delete->execute([$group->id]);
        $other->commit();
    });
    try {
        DB::beginTransaction();
        DB::table('project_task_groups')->where('id', $group->id)->first();
        expect(fn () => app(MoveTaskToGroup::class)->handle($actor, $project->id, $task->id, $group->id, null))->toThrow(RuntimeException::class, 'Choose an activity');
        DB::rollBack();
        expect($removed)->toBeTrue()->and($task->fresh()->group_id)->toBeNull();
    } finally {
        tgroupSchemaCleanup([$project->id]);
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires real MariaDB snapshots.');

it('refuses a group move after client access is revoked behind an open snapshot', function () {
    $owner = cpaUser();
    $manager = cpaUser(allData: true);
    $client = cpaClient($owner, [$manager]);
    $project = cpaProject($owner, $client);
    $group = app(SaveTaskGroup::class)->handle(Access::forUser($owner), $project->id, null, 'Private');
    $task = cpaTask($owner, $project, []);
    $other = tgroupSchemaConnection();
    $revoked = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$revoked, $other, $client, $manager): void {
        if ($revoked || ! str_contains($sql, '`project_tasks`') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $revoked = true;
        $other->beginTransaction();
        $lock = $other->prepare('SELECT id FROM project_clients WHERE id = ? FOR UPDATE');
        $lock->execute([$client->id]);
        $delete = $other->prepare('DELETE FROM project_client_members WHERE client_id = ? AND user_id = ?');
        $delete->execute([$client->id, $manager->id]);
        $other->commit();
    });
    try {
        DB::beginTransaction();
        DB::table('project_task_groups')->where('id', $group->id)->first();
        expect(fn () => app(MoveTaskToGroup::class)->handle(Access::forUser($manager), $project->id, $task->id, $group->id, null))->toThrow(RuntimeException::class);
        DB::rollBack();
        expect($revoked)->toBeTrue()->and($task->fresh()->group_id)->toBeNull();
    } finally {
        tgroupSchemaCleanup([$project->id]);
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'mysql', 'Requires real MariaDB snapshots.');
