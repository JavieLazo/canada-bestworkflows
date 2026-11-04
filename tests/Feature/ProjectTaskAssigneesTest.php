<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The multi-assignee data layer: the project_task_assignees pivot, the
 * ProjectTask helpers every screen keys on (assigneeIds / hasAssignee /
 * syncAssignees), an employee's task load read from the pivot, and the
 * migration pair that moves the single assignee_id column into the pivot.
 */
function ptaEmployee(string $last = 'Garcia'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'COP-'.Str::upper(Str::random(4)),
        'first_name' => 'Ana',
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

function ptaTask(): ProjectTask
{
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-'.Str::upper(Str::random(4)),
        'name' => 'Project '.Str::random(8),
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    $task = new ProjectTask;
    $task->forceFill([
        'code' => 'TSK-'.Str::upper(Str::random(5)),
        'project_id' => $project->getKey(),
        'title' => 'Shared work',
        'status' => 'PENDING',
        'priority' => 'NORMAL',
    ])->save();

    return $task;
}

it('reports no assignee for null, blank or a stranger, and a strict match for a member', function () {
    $task = ptaTask();
    $mine = ptaEmployee();

    expect($task->assigneeIds())->toBe([]);
    expect($task->hasAssignee(null))->toBeFalse();
    expect($task->hasAssignee(''))->toBeFalse();
    expect($task->hasAssignee($mine->getKey()))->toBeFalse();

    $task->syncAssignees([$mine->getKey()], null);

    expect($task->hasAssignee($mine->getKey()))->toBeTrue();
    expect($task->hasAssignee(null))->toBeFalse();
    expect($task->fresh()->hasAssignee($mine->getKey()))->toBeTrue();
});

it('syncs the pivot to exactly the wanted set and returns the deduped diff', function () {
    $task = ptaTask();
    $a = ptaEmployee('Alonso');
    $b = ptaEmployee('Bautista');
    $c = ptaEmployee('Cruz');
    $manager = User::factory()->create();
    $reassigner = User::factory()->create();

    $first = $task->syncAssignees([$a->getKey(), $b->getKey(), $b->getKey(), '', ' '], (int) $manager->getKey());

    expect($first)->toBe(['added' => [$a->getKey(), $b->getKey()], 'removed' => []]);
    expect($task->assigneeIds())->toBe(collect([$a->getKey(), $b->getKey()])->sort()->values()->all());

    $rows = ProjectTaskAssignee::query()->where('task_id', $task->getKey())->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->getKey())->toBeString()->toHaveLength(26);
        expect($row->assigned_by)->toBe((int) $manager->getKey());
        expect($row->created_at)->not->toBeNull();
    }
    // The key is a ULID string (asserted above), so find() resolves to one row rather than a collection.
    $keptRowId = (string) $rows->firstWhere('employee_id', $a->getKey())->getKey();

    $second = $task->syncAssignees([$c->getKey(), $a->getKey()], (int) $reassigner->getKey());

    expect($second)->toBe(['added' => [$c->getKey()], 'removed' => [$b->getKey()]]);
    expect($task->assigneeIds())->toBe(collect([$a->getKey(), $c->getKey()])->sort()->values()->all());

    // The retained membership is untouched — same row, same original assigner.
    $kept = ProjectTaskAssignee::query()->find($keptRowId);
    expect($kept)->not->toBeNull();
    expect($kept->assigned_by)->toBe((int) $manager->getKey());
    expect(ProjectTaskAssignee::query()->where('task_id', $task->getKey())->where('employee_id', $c->getKey())->value('assigned_by'))->toBe((int) $reassigner->getKey());

    $third = $task->syncAssignees([], null);

    expect($third)->toBe(['added' => [], 'removed' => collect([$a->getKey(), $c->getKey()])->sort()->values()->all()]);
    expect($task->assigneeIds())->toBe([]);
    expect(ProjectTaskAssignee::query()->where('task_id', $task->getKey())->count())->toBe(0);
});

it('refuses a duplicate membership at the database', function () {
    $task = ptaTask();
    $mine = ptaEmployee();
    $task->syncAssignees([$mine->getKey()], null);

    $dup = new ProjectTaskAssignee;
    $dup->fill(['task_id' => $task->getKey(), 'employee_id' => $mine->getKey()]);

    expect(fn () => $dup->save())->toThrow(QueryException::class);
});

it('counts the employee task load through the pivot and cascades on delete', function () {
    $mine = ptaEmployee();
    $one = ptaTask();
    $two = ptaTask();
    $one->syncAssignees([$mine->getKey()], null);
    $two->syncAssignees([$mine->getKey()], null);

    expect(ProjectTaskAssignee::query()->where('employee_id', $mine->getKey())->count())->toBe(2);
    expect(ProjectTaskAssignee::query()->where('employee_id', $mine->getKey())->pluck('task_id')->sort()->values()->all())
        ->toBe(collect([$one->getKey(), $two->getKey()])->sort()->values()->all());

    $one->delete();

    expect(ProjectTaskAssignee::query()->where('employee_id', $mine->getKey())->count())->toBe(1);
    expect(ProjectTaskAssignee::query()->where('task_id', $one->getKey())->count())->toBe(0);
});

it('keeps the actor column out of the pivot fillable and records the submitter on the task', function () {
    expect((new ProjectTaskAssignee)->getFillable())->toBe(['task_id', 'employee_id']);

    $task = ptaTask();
    $mine = ptaEmployee();
    $task->fill(['submitted_by' => $mine->getKey()])->save();

    expect($task->fresh()->submitted_by)->toBe($mine->getKey());
});

it('backfilled the old single assignee_id into one pivot row, retry-safe', function () {
    // Wind the two multi-assignee migrations back, which restores the
    // assignee_id column, plant a legacy row, then run 0001 forward again.
    // Their down()/up() are called directly, newest first as migrate:rollback
    // would, rather than through `migrate:rollback --step 2`: a step count
    // would silently target whichever migrations are newest, so the first
    // unrelated migration added after these would break this test.
    $first = require base_path('database/migrations/2026_09_10_0001_create_project_task_assignees_table.php');
    $second = require base_path('database/migrations/2026_09_10_0002_drop_assignee_id_from_project_tasks_table.php');

    $second->down();
    $first->down();

    expect(Schema::hasColumn('project_tasks', 'assignee_id'))->toBeTrue();
    expect(Schema::hasTable('project_task_assignees'))->toBeFalse();
    expect(Schema::hasColumn('project_tasks', 'submitted_by'))->toBeFalse();

    $mine = ptaEmployee();
    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-LEGACY',
        'name' => 'Legacy project',
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    $legacyRow = static fn (string $code, ?string $assigneeId): array => [
        'id' => (string) Str::ulid(),
        'code' => $code,
        'project_id' => $project->getKey(),
        'title' => 'Legacy '.$code,
        'assignee_id' => $assigneeId,
        'status' => 'PENDING',
        'priority' => 'NORMAL',
        'assigned_by' => null,
        'assigned_at' => '2026-09-01 08:00:00.000',
        'created_at' => '2026-09-01 08:00:00.000',
    ];

    DB::table('project_tasks')->insert([
        $legacyRow('TSK-LEGACY-1', $mine->getKey()),
        $legacyRow('TSK-LEGACY-2', null),
    ]);

    $first->up();

    expect(Schema::hasTable('project_task_assignees'))->toBeTrue();
    expect(Schema::hasColumn('project_tasks', 'submitted_by'))->toBeTrue();

    $rows = ProjectTaskAssignee::query()->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->employee_id)->toBe($mine->getKey());
    expect($rows[0]->getKey())->toHaveLength(26);
    expect($rows[0]->created_at?->format('Y-m-d H:i'))->toBe('2026-09-01 08:00');

    $task = ProjectTask::query()->where('code', 'TSK-LEGACY-1')->firstOrFail();
    expect($task->assigneeIds())->toBe([$mine->getKey()]);
    expect(ProjectTask::query()->where('code', 'TSK-LEGACY-2')->firstOrFail()->assigneeIds())->toBe([]);

    // A retry (the cPanel cron re-running after a half-applied deploy) must
    // neither fail on the existing table/column nor duplicate the row.
    $first->up();

    expect(ProjectTaskAssignee::query()->count())->toBe(1);

    // The second migration then removes the column — called directly, since
    // the migrations table still records it as applied and `migrate` would
    // skip it. Its effect on the pivot rows is NOT asserted here: on SQLite
    // the drop is a table rebuild, and `PRAGMA foreign_keys = OFF` is a no-op
    // inside the transaction RefreshDatabase wraps this test in, so the DROP
    // TABLE cascades in the test only. A real SQLite migrate runs outside a
    // transaction and MySQL alters in place — neither touches the pivot.
    $second->up();

    expect(Schema::hasColumn('project_tasks', 'assignee_id'))->toBeFalse();
    expect(Schema::hasColumn('project_tasks', 'submitted_by'))->toBeTrue();
    expect(Schema::hasTable('project_task_assignees'))->toBeTrue();
});

it('restores one assignee per task from the pivot when 0002 is rolled back, earliest first', function () {
    $second = require base_path('database/migrations/2026_09_10_0002_drop_assignee_id_from_project_tasks_table.php');

    $only = ptaEmployee('Alonso');
    $earlier = ptaEmployee('Bautista');
    $later = ptaEmployee('Cruz');
    $tieLow = ptaEmployee('Dizon');
    $tieHigh = ptaEmployee('Estrada');

    $solo = ptaTask();
    $shared = ptaTask();
    $tied = ptaTask();
    $unassigned = ptaTask();

    $solo->syncAssignees([$only->getKey()], null);

    $membership = static fn (string $id, ProjectTask $task, EmployeeProfile $employee, string $at): array => [
        'id' => $id,
        'task_id' => $task->getKey(),
        'employee_id' => $employee->getKey(),
        'assigned_by' => null,
        'created_at' => $at,
    ];

    // On the shared task the row ids run AGAINST the timestamps, so only the
    // created_at order can pick Bautista; the tied task has equal timestamps
    // and must fall back to the lower row id.
    DB::table('project_task_assignees')->insert([
        $membership('PTA-SHARED-'.str_repeat('0', 14).'A', $shared, $later, '2026-09-02 09:00:00.000'),
        $membership('PTA-SHARED-'.str_repeat('0', 14).'B', $shared, $earlier, '2026-09-01 09:00:00.000'),
        $membership('PTA-TIED-'.str_repeat('0', 16).'A', $tied, $tieLow, '2026-09-03 09:00:00.000'),
        $membership('PTA-TIED-'.str_repeat('0', 16).'B', $tied, $tieHigh, '2026-09-03 09:00:00.000'),
    ]);

    $second->down();

    $assigneeOf = static fn (ProjectTask $task): ?string => DB::table('project_tasks')
        ->where('id', $task->getKey())
        ->value('assignee_id');

    expect(Schema::hasColumn('project_tasks', 'assignee_id'))->toBeTrue();
    expect($assigneeOf($solo))->toBe($only->getKey());
    expect($assigneeOf($shared))->toBe($earlier->getKey());
    expect($assigneeOf($tied))->toBe($tieLow->getKey());
    expect($assigneeOf($unassigned))->toBeNull();

    // A repeated rollback (the cron re-running after a partial one) must not
    // fail on the column, index or constraint it already restored, and fills
    // only the cells that are still empty.
    DB::table('project_task_assignees')->insert([
        $membership('PTA-RETRY-'.str_repeat('0', 15).'A', $solo, $later, '2026-09-04 09:00:00.000'),
        $membership('PTA-RETRY-'.str_repeat('0', 15).'B', $unassigned, $later, '2026-09-04 09:00:00.000'),
    ]);

    $second->down();

    expect($assigneeOf($solo))->toBe($only->getKey());
    expect($assigneeOf($unassigned))->toBe($later->getKey());

    // And forward again, so the pair round-trips.
    $second->up();

    expect(Schema::hasColumn('project_tasks', 'assignee_id'))->toBeFalse();
    expect(Schema::hasTable('project_task_assignees'))->toBeTrue();
});
