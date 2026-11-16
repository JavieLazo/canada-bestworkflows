<?php

declare(strict_types=1);

use App\Models\Project;
use App\Modules\WorkManagement\Application\Commands\ChangeTaskStatus;
use App\Modules\WorkManagement\Application\Commands\CreateProject;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Support\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support/ClientProjectFixtures.php';

it('keeps names unique inside each client and internal projects on MariaDB', function () {
    $owner = cpaUser();
    $a = cpaClient($owner);
    $b = cpaClient($owner);
    $first = cpaProject($owner, $a);
    $second = cpaProject($owner, $b);
    try {
        expect(fn () => cpaProject($owner, $a))->toThrow(RuntimeException::class);
        $admin = cpaUser(allData: true);
        $draft = new ProjectDraft('Website', '', 'NONE', 'PLANNING', '2026-01-01', '', 'NONE');
        app(CreateProject::class)->handle(Access::forUser($admin), $draft);
        expect(fn () => app(CreateProject::class)->handle(Access::forUser($admin), $draft))->toThrow(RuntimeException::class);
        expect(Schema::hasIndex('projects', 'projects_internal_name_unique'))->toBeTrue();
    } finally {
        $first->delete();
        $second->delete();
    }
});

it('reruns and reverses the association migration without rewriting legacy work', function () {
    $legacy = Project::create(['code' => 'PRJ-LEGACY', 'name' => 'Legacy', 'status' => 'PLANNING', 'start_date' => '2026-01-01']);
    $admin = cpaUser(allData: true);
    $employee = cpaEmployee();
    cpaTask($admin, $legacy, [$employee]);
    $tables = ['project_tasks', 'project_task_assignees'];
    $workBefore = array_map(static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $before = $legacy->fresh()->toArray();
    unset($before['client_id']);
    $migration = require database_path('migrations/2026_10_31_0003_add_client_to_projects_table.php');
    $migration->up();
    $migration->up();
    $migration->down();
    $migration->down();
    expect($legacy->fresh()->toArray())->toBe($before)->and(Schema::hasIndex('projects', 'projects_name_unique'))->toBeTrue();
    $migration->up();
    expect($legacy->fresh()->client_id)->toBeNull();
    expect(array_map(static fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(), $tables))->toBe($workBefore);
    expect(fn () => DB::table('projects')->where('id', $legacy->id)->update(['client_id' => 'unknown-client']))->toThrow(QueryException::class);
});

it('refuses a rollback that would remove a live client boundary', function () {
    $owner = cpaUser();
    $client = cpaClient($owner);
    $project = cpaProject($owner, $client);
    try {
        $migration = require database_path('migrations/2026_10_31_0003_add_client_to_projects_table.php');
        expect(fn () => $migration->down())->toThrow(RuntimeException::class);
        expect($project->fresh()->client_id)->toBe($client->id);
    } finally {
        $project->delete();
    }
});

it('sees a membership removal committed after a task action opened its snapshot', function () {
    $owner = cpaUser();
    $reviewer = cpaUser(allData: true);
    $client = cpaClient($owner, [$reviewer]);
    $project = cpaProject($owner, $client);
    $task = cpaTask($owner, $project, []);
    $config = DB::connection()->getConfig();
    $other = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname=ems_testing', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $revoked = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$revoked, $other, $client, $reviewer): void {
        if ($revoked || ! str_contains($sql, 'project_clients') || ! str_contains(strtolower($sql), 'for update')) {
            return;
        }
        $revoked = true;
        $other->beginTransaction();
        $lock = $other->prepare('SELECT id FROM project_clients WHERE id = ? FOR UPDATE');
        $lock->execute([$client->id]);
        $remove = $other->prepare('DELETE FROM project_client_members WHERE client_id = ? AND user_id = ?');
        $remove->execute([$client->id, $reviewer->id]);
        $other->commit();
    });
    try {
        expect(fn () => app(ChangeTaskStatus::class)->handle(Access::forUser($reviewer), $project->id, $task->id, 'IN_PROGRESS', '', 'PENDING'))->toThrow(RuntimeException::class);
        expect($revoked)->toBeTrue()->and($task->fresh()->status)->toBe('PENDING');
    } finally {
        $project->delete();
    }
});
