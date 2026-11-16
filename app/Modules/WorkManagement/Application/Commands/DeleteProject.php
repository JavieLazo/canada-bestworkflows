<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectBudget;
use App\Models\ProjectCashEntry;
use App\Models\ProjectContribution;
use App\Models\ProjectFunding;
use App\Models\ProjectRecordFile;
use App\Models\ProjectReport;
use App\Models\ProjectResultEntry;
use App\Models\ProjectTaskFile;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Extracted workflow; authorization, validation and persistence stay with WorkManagement. */
final class DeleteProject
{
    public function handle(Access $actor, string $projectId): ?string
    {
        $project = WorkRules::project($actor, $projectId);
        if (! WorkRules::managesProject($actor, $project) && ! ($project === null && WorkRules::managesProjects($actor))) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        if ($project === null) {
            return null;
        }
        /** @var list<string> $paths */
        $paths = DB::transaction(function () use ($actor, $project): array {
            // Every task row first, then the project row (P) — the order every
            // writer in WorkRules' lock protocol takes them, so the cascade
            // below deletes only rows this transaction already holds instead
            // of reaching for a task a task writer has locked while that
            // writer waits on P. THE STATEMENT MUST STAY CreateTask's OWN:
            // `pluck('code')` walks `project_tasks_code_unique`, an index no
            // task writer ever updates, in the same order CreateTask's scan
            // does, so the two serialise instead of deadlocking. A
            // `pluck('id')` here went through `tasks_due_idx` on MariaDB 11.4
            // and deadlocked (1213) with a due-date edit and with CreateTask
            // (ProjectLockOrderTest).
            DB::table('project_tasks')->lockForUpdate()->pluck('code');
            $locked = WorkRules::lockedProject($actor, $project->id);
            if (! WorkRules::managesProject($actor, $locked)) {
                throw new \RuntimeException(WorkRules::forbiddenMessage());
            }
            if (ProjectReport::query()->where('project_id', $project->id)->exists() || ProjectFunding::query()->where('project_id', $project->id)->exists() || ProjectContribution::query()->where('project_id', $project->id)->exists()) {
                throw new \RuntimeException('This project has reporting or financial history. Keep it and mark it Cancelled instead.');
            }
            if (ProjectResultEntry::query()->where('project_id', $project->id)->exists() || ProjectRecordFile::query()->where('project_id', $project->id)->exists()) {
                throw new \RuntimeException('This project has accomplishments or evidence. Keep it and mark it Cancelled instead.');
            }
            if (ProjectBudget::query()->where('project_id', $project->id)->exists() || ProjectCashEntry::query()->where('project_id', $project->id)->exists()) {
                throw new \RuntimeException('This project has financial records. Keep it and mark it Cancelled instead.');
            }
            // Read under those locks, so evidence attached before the delete
            // is counted and its bytes collected — no later upload can land
            // on a task this transaction holds.
            $taskIds = $project->tasks()->pluck('id')->all();
            $taskCount = count($taskIds);
            $paths = ProjectTaskFile::query()->whereIn('task_id', $taskIds)->pluck('path')->filter(static fn (mixed $path): bool => is_string($path) && $path !== '')->values()->all();
            // Clear dependent work before the project can cascade into its task groups.
            $project->tasks()->delete();
            $project->delete();
            AuditService::record($actor, AuditEvent::PROJECT_DELETED, 'projects', $project->id, sprintf('Project %s — %s deleted with %d %s', $project->code, $project->name, $taskCount, $taskCount === 1 ? 'task' : 'tasks'));

            return $paths;
        });
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }

        return $project->code;
    }
}
