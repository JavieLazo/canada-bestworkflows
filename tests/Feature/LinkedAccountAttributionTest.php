<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Modules\WorkManagement\Application\Commands\ChangeTaskStatus;
use App\Modules\WorkManagement\Application\Commands\CommentOnProjectTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Support\Access;

/** Official employee names fit persisted account attribution without changing frozen history. */
it('keeps long linked names within task attribution capacity and freezes the recorded name', function (string $operation) {
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'LONG-NAME',
        'first_name' => str_repeat('ñ', 100),
        'last_name' => str_repeat('é', 100),
        'suffix' => str_repeat('a', 20),
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();
    $role = new Role;
    $role->forceFill(['name' => 'Project reviewer', 'all_data' => true, 'is_system' => false])->save();
    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->id, 'module' => ModuleCode::PROJECTS])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
    $actor = Access::forActor($user);
    expect(mb_strlen($actor->fullName))->toBeGreaterThan(200);

    $project = new Project;
    $project->forceFill([
        'code' => 'PRJ-NAME', 'name' => 'Linked account attribution',
        'status' => 'ACTIVE', 'start_date' => '2026-01-01',
    ])->save();
    $task = new ProjectTask;
    $task->forceFill([
        'code' => 'TSK-NAME', 'project_id' => $project->id,
        'title' => 'Retain recorded identity', 'priority' => 'NORMAL',
        'assigned_at' => now()->utc(),
        'status' => $operation === 'review' ? 'SUBMITTED' : 'PENDING',
        'submission_mode' => 'GROUP',
    ])->save();

    match ($operation) {
        'comment' => app(CommentOnProjectTask::class)->handle($actor, $project->id, $task->id, 'Review note'),
        'review' => app(ReviewTask::class)->handle($actor, $project->id, $task->id, 'COMPLETED', 'Review note'),
        'status' => app(ChangeTaskStatus::class)->handle($actor, $project->id, $task->id, 'IN_PROGRESS', 'Review note', 'PENDING'),
        default => throw new LogicException('Unknown attribution path.'),
    };

    $comment = TaskComment::query()->sole();
    $recorded = mb_substr($actor->fullName, 0, 200);
    expect($comment->author_name)->toBe($recorded)
        ->and(mb_strlen($comment->author_name))->toBe(200)
        ->and($comment->author_id)->toBe($user->id)
        ->and($task->fresh()->status)->toBe(match ($operation) {
            'review' => 'COMPLETED', 'status' => 'IN_PROGRESS', default => 'PENDING',
        });

    $employee->first_name = 'Approved';
    $employee->save();
    expect($user->name)->not->toBe($actor->fullName)
        ->and($comment->fresh()->author_name)->toBe($recorded);
})->with(['comment', 'review', 'status']);
