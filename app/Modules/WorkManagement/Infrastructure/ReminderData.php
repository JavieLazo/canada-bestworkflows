<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Domain\Projects\TaskPolicy;
use App\Domain\Reminders\ReminderSweep;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Platform\Notifications\ReminderSource;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bounded work facts preserve rule priority and legacy timestamp keys on the actor's own read scope.
 *
 * The 'projects' group reads through WorkScope::projects(), so a project's
 * Task Leader is reminded of its due date even from outside every crew; the
 * 'reviews' chase stays with an all-data assigner.
 *
 * ReminderSweep is pure and sits outside this module, so the INDIVIDUAL-mode
 * facts it needs arrive precomputed from TaskSubmissionPolicy: `member_done`
 * on each 'assigned' task (the actor's OWN response is submitted or approved,
 * so there is nothing to chase them about) and `pending_responses` on each
 * 'reviews' task (who handed in a response still awaiting review, and when).
 * Only statuses and stamps are loaded for them, never a member's note, and
 * only the actor's own row for the first.
 */
final class ReminderData implements ReminderSource
{
    public function __construct(private readonly OperationalDirectory $employees) {}

    public function groups(): array
    {
        return ['assigned', 'reviews', 'projects'];
    }

    public function candidates(Access $actor, string $today, string $group): iterable
    {
        $query = $this->query($actor, $group);
        if ($group === 'projects') {
            $query->select(['id', 'code', 'name', 'status', 'manager_id', 'due_date'])
                ->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->whereNotNull('due_date');
        } elseif ($group === 'reviews') {
            // A GROUP hand-in, or an INDIVIDUAL task in progress with a response
            // awaiting review — TaskSubmissionPolicy::awaitsReview(). Only the
            // waiting responses are loaded: a decided one chases nobody.
            $query->where(static function (Builder $waiting): void {
                $waiting->where(static function (Builder $handedIn): void {
                    $handedIn->where('status', 'SUBMITTED')->whereNotNull('submitted_at');
                })->orWhere(static function (Builder $responses): void {
                    $responses->where('submission_mode', TaskPolicy::MODE_INDIVIDUAL)->where('status', 'IN_PROGRESS')
                        ->whereHas('submissions', static fn (Builder $response) => $response->where('project_task_submissions.status', 'SUBMITTED'));
                });
            })
                ->with(['assignments', 'submissions' => static fn ($response) => $response->select(['id', 'task_id', 'employee_id', 'status', 'submitted_at'])
                    ->where('status', 'SUBMITTED')]);
        } else {
            $query->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'SUBMITTED'])->whereNotNull('due_date')->with('assignments');
            // The actor's OWN response and nobody else's. Behind the link:
            // an unlinked actor has no tasks here, and a null id is never
            // matched against employee_id.
            if ($actor->hasEmployeeLink()) {
                $query->with(['submissions' => static fn ($own) => $own->select(['id', 'task_id', 'employee_id', 'status'])
                    ->where('employee_id', '=', $actor->ownEmployeeId())]);
            }
        }
        if ($group !== 'projects') {
            $query->select(['id', 'code', 'title', 'status', 'submission_mode', 'project_id', 'submitted_by', 'assigned_by', 'due_date', 'submitted_at']);
        }
        // Explicit primary-key order: the scan order is never left to the database.
        foreach ($query->lazyById(self::BATCH_SIZE)->chunk(self::BATCH_SIZE) as $chunk) {
            $tasks = [];
            $projects = [];
            $employeeIds = [];
            foreach ($chunk as $row) {
                if ($row instanceof Project) {
                    $projects[] = ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'status' => $row->status,
                        'manager_id' => $row->manager_id === null ? null : (string) $row->manager_id, 'due_date' => $row->due_date?->format('Y-m-d')];
                } else {
                    $assignees = $row->assigneeIds();
                    $task = ['id' => $row->id, 'code' => $row->code, 'title' => $row->title, 'status' => $row->status,
                        'project_id' => $row->project_id, 'assignee_ids' => $assignees, 'submitted_by' => $row->submitted_by,
                        'assigned_by' => $row->assigned_by === null ? null : (string) $row->assigned_by,
                        'due_date' => $row->due_date?->format('Y-m-d'), 'submitted_at' => $row->submitted_at];
                    if ($group === 'reviews') {
                        array_push($employeeIds, ...$assignees);
                        if ($row->submitted_by !== null) {
                            $employeeIds[] = $row->submitted_by;
                        }
                        $task['pending_responses'] = [];
                        foreach (TaskSubmissionPolicy::isIndividual($row->submission_mode) ? $row->submissions : [] as $response) {
                            if (TaskSubmissionPolicy::responseAwaitsReview($row->status, $response->status)) {
                                $task['pending_responses'][] = ['employee_id' => $response->employee_id, 'submitted_at' => $response->submitted_at];
                                $employeeIds[] = $response->employee_id;
                            }
                        }
                    } else {
                        // Only the eager-loaded OWN row — never a lazy load of the crew's.
                        $own = $row->relationLoaded('submissions') ? $row->submissions->first() : null;
                        $task['member_done'] = ! TaskSubmissionPolicy::memberOwesWork($row->submission_mode, $row->status, $own?->status);
                    }
                    $tasks[] = $task;
                }
            }
            $employees = $employeeIds === [] ? [] : $this->employees->list($actor, array_values(array_unique($employeeIds)))
                ->map(static fn (DirectoryEmployee $row): array => $row->toArray())->values()->all();
            yield ReminderSweep::build($today, (string) $actor->userId, $actor->modules, $actor->employeeId,
                $tasks, $projects, array_values($employees), group: $group);
        }
    }

    public function visibleIds(Access $actor, string $group, array $ids): array
    {
        return $ids === [] ? [] : array_values($this->query($actor, $group)->whereKey($ids)->pluck('id')->map(static fn ($id): string => (string) $id)->all());
    }

    /** @return Builder<Project>|Builder<ProjectTask> */
    private function query(Access $actor, string $group): Builder
    {
        if ($group === 'assigned') {
            $query = WorkScope::tasks($actor, true);

            return $actor->hasModule(ModuleCode::TASKS) ? $query : $query->whereRaw('1 = 0');
        }
        if ($group === 'reviews') {
            $query = WorkScope::tasks($actor)->where('assigned_by', $actor->userId);

            return $actor->hasModule(ModuleCode::PROJECTS) ? ($actor->mayReadAllData(ModuleCode::PROJECTS) ? $query : $query->whereHas('project', static fn (Builder $project) => $project->whereIn('client_id', ClientScope::manageable($actor)->select('id')))) : $query->whereRaw('1 = 0');
        }
        $query = WorkScope::projects($actor)->where('manager_id', $actor->userId);

        return $actor->hasModule(ModuleCode::PROJECTS) ? $query : $query->whereRaw('1 = 0');
    }
}
