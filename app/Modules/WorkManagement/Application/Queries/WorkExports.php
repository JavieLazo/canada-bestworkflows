<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Platform\Transfer\CsvDownload;
use App\Platform\Transfer\CsvExport;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\TextSort;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Keeps CSV reads in one repeatable-read snapshot (App\Platform\Transfer\CsvDownload)
 * while hydrating bounded batches. The two headers are also what the
 * project and task imports read back (ImportProjects, ImportTasks).
 */
final class WorkExports
{
    public const array PROJECT_COLUMNS = ['Code', 'Project', 'Site', 'Task Leader', 'Start date', 'Due date', 'Progress', 'Health', 'Status'];

    /** The project columns an import writes; Progress and Health are derived from the tasks. */
    public const array PROJECT_IMPORT_COLUMNS = ['Code', 'Project', 'Site', 'Task Leader', 'Start date', 'Due date', 'Status'];

    public const array TASK_COLUMNS = ['Code', 'Task', 'Assignees', 'Priority', 'Start date', 'Due date', 'Status'];

    /** @param array{q: string, status: string, site: string, manager: string, queue: string} $filters */
    public function projects(Access $actor, array $filters, string $sort, string $direction, string $today): StreamedResponse
    {
        abort_if($actor->userId === null, 403);
        $rows = static function () use ($actor, $filters, $sort, $direction, $today): \Generator {
            $accounts = app(AccountNames::class)->list($actor);
            $sites = app(OrganizationDirectory::class)->sites($actor);
            $page = 1;
            do {
                $batch = app(WorkLists::class)->projectPage($actor, $filters, $sort, $direction, $today, $page++, 100);
                $ids = array_values($batch->getCollection()->map(static fn (ProjectView $project): string => $project->id)->all());
                $totals = app(WorkSummary::class)->projects($actor, $ids, $today);
                foreach ($batch as $project) {
                    $progress = $totals[$project->id]->progress();
                    $health = $totals[$project->id]->health($project, $today);
                    yield [
                        $project->code, $project->name,
                        (string) ($sites->firstWhere('id', $project->site_id)->name ?? ''),
                        $project->manager_id === null ? '' : (string) ($accounts->firstWhere('id', $project->manager_id)->name ?? ''),
                        WorkRules::dateKey($project->start_date) ?? '', WorkRules::dateKey($project->due_date) ?? '',
                        $progress['percent'].'%', ProjectPolicy::healthLabel($health), ProjectPolicy::statusLabel($project->status),
                    ];
                }
            } while ($batch->hasMorePages());
        };

        return $this->stream(self::PROJECT_COLUMNS, $rows, 'projects-'.$today.'.csv');
    }

    /** @param array{q: string, status: string, assignee: string, priority: string, queue: string} $filters */
    public function tasks(Access $actor, string $projectId, array $filters, string $sort, string $direction, string $today): StreamedResponse
    {
        $project = app(WorkRecords::class)->project($actor, $projectId);
        abort_if($project === null, 404, 'That project no longer exists.');
        $rows = static function () use ($actor, $project, $filters, $sort, $direction, $today): \Generator {
            $page = 1;
            do {
                $batch = app(WorkLists::class)->taskPage($actor, $project->id, $filters, $sort, $direction, $today, $page++, 100);
                foreach ($batch as $task) {
                    yield [$task->code, $task->title, self::assigneeCell($task), TaskPolicy::priorityLabel($task->priority), WorkRules::dateKey($task->start_date) ?? '', WorkRules::dateKey($task->due_date) ?? '', TaskPolicy::statusLabel($task->status)];
                }
            } while ($batch->hasMorePages());
        };

        return $this->stream(self::TASK_COLUMNS, $rows, mb_strtolower($project->code).'-tasks-'.$today.'.csv');
    }

    /** The projects header alone — the import template (ImportProjects). */
    public function projectsTemplate(Access $actor): StreamedResponse
    {
        abort_if($actor->userId === null, 403);

        return $this->stream(self::PROJECT_COLUMNS, static fn (): \Generator => yield from [], 'projects-template.csv');
    }

    /** One project's task header alone — the import template (ImportTasks). */
    public function tasksTemplate(Access $actor, string $projectId): StreamedResponse
    {
        $project = app(WorkRecords::class)->project($actor, $projectId);
        abort_if($project === null, 404, 'That project no longer exists.');

        return $this->stream(self::TASK_COLUMNS, static fn (): \Generator => yield from [], mb_strtolower($project->code).'-tasks-template.csv');
    }

    /** @param list<string> $headers
     * @param  \Closure(): \Generator<int, list<string>>  $rows
     */
    private function stream(array $headers, \Closure $rows, string $filename): StreamedResponse
    {
        // Every batch and its owner-provided labels stay on one snapshot:
        // CsvDownload reads inside one repeatable-read transaction.
        return CsvDownload::response(new CsvExport($filename, $headers, $rows));
    }

    /** Shared assignees in surname order, separated by semicolons. */
    private static function assigneeCell(ProjectTaskView $task): string
    {
        $employees = $task->assignees->all();
        if ($employees === []) {
            return EmployeeName::display(null);
        }
        usort($employees, static fn (DirectoryEmployee $a, DirectoryEmployee $b): int => TextSort::compare($a->last_name, $b->last_name) ?: TextSort::compare($a->first_name, $b->first_name));

        return implode('; ', array_map(static fn (DirectoryEmployee $employee): string => EmployeeName::display($employee), $employees));
    }
}
