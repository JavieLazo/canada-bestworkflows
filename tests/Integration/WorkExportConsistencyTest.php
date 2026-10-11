<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\WorkManagement\Application\Queries\WorkExports;
use App\Support\Access;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/** Concurrent task edits cannot move records between pages of one streamed export. */
it('holds a consistent export snapshot across independent committed edits', function () {
    $role = Role::create(['name' => 'Export reader', 'all_data' => true, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => ModuleCode::PROJECTS]);
    $actor = Access::forActor(User::factory()->create(['role_id' => $role->id]));
    $project = Project::create(['code' => 'PRJ-EXP', 'name' => 'Export snapshot', 'status' => 'PLANNING', 'start_date' => '2026-09-01']);
    for ($i = 0; $i < 105; $i++) {
        ProjectTask::create(['code' => sprintf('TSK-%03d', $i), 'project_id' => $project->id, 'title' => 'Original task', 'status' => 'PENDING', 'priority' => 'NORMAL']);
    }
    $connection = DB::connection();
    $pdo = new PDO('mysql:host=127.0.0.1;port='.$connection->getConfig('port').';dbname=ems_testing', 'ems_testing', $connection->getConfig('password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $changed = false;
    DB::listen(static function (QueryExecuted $event) use ($pdo, &$changed): void {
        if (! $changed && str_contains($event->sql, 'count(*)') && str_contains($event->sql, 'project_tasks')) {
            $changed = true;
            $pdo->exec("UPDATE project_tasks SET title = 'Concurrent edit', status = 'COMPLETED' WHERE code = 'TSK-104'");
        }
    });
    $response = app(WorkExports::class)->tasks($actor, $project->id, ['q' => '', 'status' => 'PENDING', 'assignee' => 'ALL', 'priority' => 'ALL', 'queue' => 'ALL'], 'code', 'asc', '2026-09-17');
    ob_start();
    try {
        $response->sendContent();
        $csv = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    if ($csv === false) {
        throw new RuntimeException('Export output was not captured.');
    }
    expect($changed)->toBeTrue()->and($csv)->toContain('TSK-104')->not->toContain('Concurrent edit')
        ->and(substr_count($csv, 'Original task'))->toBe(105)
        ->and(ProjectTask::query()->where('code', 'TSK-104')->value('title'))->toBe('Concurrent edit')
        ->and($connection->transactionLevel())->toBe(0);
});
