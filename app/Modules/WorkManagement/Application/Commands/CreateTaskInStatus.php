<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateTaskInStatus
{
    public function handle(Access $actor, string $projectId, TaskDraft $input, string $status): ?ProjectTaskView
    {
        if (! in_array($status, ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true)) {
            throw new RuntimeException('Submission and return need a recorded response and review.');
        }

        return DB::transaction(static function () use ($actor, $projectId, $input, $status): ?ProjectTaskView {
            $task = app(CreateTask::class)->handle($actor, $projectId, $input);
            if ($task === null || $status === 'PENDING') {
                return $task;
            }
            app(ChangeTaskStatus::class)->handle($actor, $projectId, $task->id, $status, '', 'PENDING');

            return app(WorkRecords::class)->task($actor, $task->id, $projectId);
        });
    }
}
