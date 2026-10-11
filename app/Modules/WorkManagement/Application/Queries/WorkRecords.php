<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Projects\TaskPolicy;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskSubmission;
use App\Models\TaskComment;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskFileView;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Contracts\Views\TaskCommentView;
use App\Modules\WorkManagement\Contracts\Views\TaskResponseView;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Scoped project/task snapshots share one read path across screens, dialogs and exports.
 *
 * INDIVIDUAL RESPONSES ARE PRIVATE. On an INDIVIDUAL task a member's response
 * — its note, the decision on it and the evidence they handed in — is visible
 * only to that member and to the task's reviewer: someone who runs its
 * project (a PROJECTS manager, or the project's Task Leader) and is NOT on the
 * crew ({@see WorkRules::reviewsTask()}). Every read here applies
 * that rule itself, because the response and file models carry no scope that
 * could: {@see self::responses()} strips a peer's content, {@see self::files()}
 * and {@see self::counts()} leave a peer's SUBMISSION files out, and
 * {@see self::download()} refuses them. Being on the crew is what narrows an
 * all-data PROJECTS holder, and a Task Leader, too. Statuses, the brief and the comment thread
 * stay shared, and a GROUP task keeps one shared response for everyone.
 */
final class WorkRecords
{
    /** @return Collection<int, ProjectView> */
    public function projects(Access $actor, ?string $id = null): Collection
    {
        $query = WorkScope::projects($actor)->orderBy('code');
        if ($id !== null) {
            $query->whereKey($id);
        }

        return $query->get()->map(static fn (Project $row): ProjectView => Snapshot::of($row, ProjectView::class));
    }

    public function project(Access $actor, string $id): ?ProjectView
    {
        return $this->projects($actor, $id)->first();
    }

    /** @return Collection<int, ProjectTaskView> */
    public function tasks(Access $actor, ?string $projectId = null, ?string $taskId = null, bool $own = false): Collection
    {
        $query = $this->withResponseFacts($this->taskQuery($actor, $own))->with(['assignments', 'project'])->orderBy('code');
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }
        if ($taskId !== null) {
            $query->whereKey($taskId);
        }

        return $this->taskViews($actor, $query->get());
    }

    /**
     * The signed-in assignee's own tasks, one page at a time. Each row carries
     * `my_status` ({@see WorkScope::MY_STATUS_SQL}), and the status filter and
     * sort read it rather than the task's status, so an INDIVIDUAL member
     * whose response was approved finds the task under Approved. The actor's
     * own response comes in through a LEFT JOIN, never a per-row query or a
     * withCount(): the page stays ONE select.
     *
     * @param  array{q: string, status: string, project: string}  $filters
     * @return LengthAwarePaginator<int, ProjectTaskView>
     */
    public function ownPage(Access $actor, array $filters, string $sort, string $direction, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->taskQuery($actor, true)->select('project_tasks.*')
            ->leftJoin('projects', 'projects.id', '=', 'project_tasks.project_id');
        WorkScope::joinOwnResponse($query, $actor)->selectRaw(WorkScope::MY_STATUS_SQL.' AS my_status');
        if ($filters['status'] !== 'ALL') {
            $query->whereRaw('('.WorkScope::MY_STATUS_SQL.') = ?', [$filters['status']]);
        }
        if ($filters['project'] !== 'ALL') {
            $query->where('project_tasks.project_id', $filters['project']);
        }
        SqlText::contains($query, ['project_tasks.code', 'project_tasks.title', 'projects.name'], $filters['q']);
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        if ($sort === 'priority') {
            SqlText::orderMapped($query, 'project_tasks.priority', TaskPolicy::PRIORITY_RANK, '2', $direction, false);
            SqlText::order($query, 'project_tasks.due_date', $direction);
        } elseif ($sort === 'status') {
            SqlText::orderMapped($query, '('.WorkScope::MY_STATUS_SQL.')', TaskSubmissionPolicy::MY_STATUSES, '('.WorkScope::MY_STATUS_SQL.')', $direction);
        } else {
            $column = match ($sort) {
                'task' => 'project_tasks.title',
                'start' => 'project_tasks.start_date',
                'due' => 'project_tasks.due_date',
                default => 'project_tasks.code',
            };
            SqlText::order($query, $column, $direction);
        }
        // Code breaks ties in the sort's own direction, so a descending list
        // is the ascending one turned round whole, equal keys included.
        $query->orderBy('project_tasks.code', $direction);
        $perPage = max(1, min(100, $perPage));
        $rows = $query->with(['assignments', 'project'])->paginate($perPage, ['project_tasks.*'], 'page', max(1, $page));
        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->paginate($perPage, ['project_tasks.*'], 'page', $rows->lastPage());
        }

        return new LengthAwarePaginator($this->taskViews($actor, $rows->getCollection()), $rows->total(), $perPage, $rows->currentPage(), $rows->getOptions());
    }

    /**
     * The My Tasks tiles, counted by the same `my_status` the page filters
     * on: a response approved while its task is still in progress waits with
     * the reviewers, like a submitted one.
     *
     * It groups by the ALIAS `k`, never by repeating MY_STATUS_SQL: MariaDB's
     * ONLY_FULL_GROUP_BY does not match a repeated expression against the
     * select list and refuses the query (1055), which sqlite never shows.
     *
     * @return array{total: int, counts: array{todo: int, awaiting: int, returned: int, completed: int}, projects: Collection<int, ProjectView>}
     */
    public function ownSummary(Access $actor): array
    {
        $counts = WorkScope::joinOwnResponse($this->taskQuery($actor, true), $actor)
            ->selectRaw(WorkScope::MY_STATUS_SQL.' AS k, COUNT(*) AS n')->groupBy('k')->pluck('n', 'k');
        $projects = Project::query()->whereIn('id', $this->taskQuery($actor, true)->select('project_id'))->orderBy('code')->get()
            ->map(static fn (Project $row): ProjectView => Snapshot::of($row, ProjectView::class));

        return ['total' => (int) $counts->sum(), 'counts' => [
            'todo' => (int) ($counts['PENDING'] ?? 0) + (int) ($counts['IN_PROGRESS'] ?? 0),
            'awaiting' => (int) ($counts['SUBMITTED'] ?? 0) + (int) ($counts['APPROVED'] ?? 0),
            'returned' => (int) ($counts['RETURNED'] ?? 0),
            'completed' => (int) ($counts['COMPLETED'] ?? 0),
        ], 'projects' => $projects];
    }

    /** @param Collection<int, ProjectTask> $rows
     * @return Collection<int, ProjectTaskView>
     */
    private function taskViews(Access $actor, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return new Collection;
        }
        $ids = [];
        foreach ($rows as $row) {
            array_push($ids, ...$row->assigneeIds());
            if ($row->submitted_by !== null) {
                $ids[] = $row->submitted_by;
            }
        }
        $employees = app(AssignmentEmployees::class)->identities(array_values(array_unique($ids)))->keyBy('id');
        $accountIds = [];
        foreach ($rows as $row) {
            if ($row->assigned_by !== null) {
                $accountIds[] = $row->assigned_by;
            }
            if ($row->reviewed_by !== null) {
                $accountIds[] = $row->reviewed_by;
            }
        }
        $accounts = app(AccountNames::class)->list($actor, $accountIds)->keyBy('id');

        return $rows->map(static fn (ProjectTask $row): ProjectTaskView => Snapshot::of($row, ProjectTaskView::class, [
            'assignees' => $employees->only($row->assigneeIds())->values(),
            'project' => $row->project === null ? null : Snapshot::of($row->project, ProjectView::class),
            'submitter' => $employees->get($row->submitted_by),
            'assigner' => $accounts->get($row->assigned_by), 'reviewer' => $accounts->get($row->reviewed_by),
        ]));
    }

    public function task(Access $actor, string $id, ?string $projectId = null, bool $own = false): ?ProjectTaskView
    {
        return $this->tasks($actor, $projectId, $id, $own)->first();
    }

    /** @param list<string> $ids
     * @return Collection<int, ProjectTaskView>
     */
    public function tasksByIds(Access $actor, array $ids): Collection
    {
        return $this->taskViews($actor, $this->withResponseFacts($this->taskQuery($actor, false))->whereKey($ids)->with(['assignments', 'project'])->orderBy('code')->get());
    }

    /**
     * Every CURRENT assignee's response to an INDIVIDUAL task, keyed by
     * employee id — one entry per member, NONE for a member with no row, so
     * the list also says who has not handed in. The content of an entry is
     * there only for that member and for the task's reviewer
     * ({@see TaskResponseView}); anyone else on the task gets the state
     * alone. A GROUP task, or one outside the actor's scope, gives nothing.
     * Names are not read here: every screen that shows this already holds the
     * task's assignees. Three queries whatever the crew's size.
     *
     * @return Collection<string, TaskResponseView>
     */
    public function responses(Access $actor, string $taskId, bool $own = false): Collection
    {
        /** @var Collection<string, TaskResponseView> $views */
        $views = new Collection;
        // `project` is not eager-loaded: reviewsTask() reads it only for an
        // actor who is not a manager, so a manager's read stays three queries.
        $task = $this->taskQuery($actor, $own)->with('assignments')->whereKey($taskId)->first();
        if ($task === null || ! TaskSubmissionPolicy::isIndividual($task->submission_mode)) {
            return $views;
        }
        $reviewer = WorkRules::reviewsTask($actor, $task);
        $me = $actor->hasEmployeeLink() ? $actor->ownEmployeeId() : null;
        $rows = ProjectTaskSubmission::query()->where('task_id', $task->id)->get()->keyBy('employee_id');
        foreach ($task->assigneeIds() as $employeeId) {
            $row = $rows->get($employeeId);
            $self = $employeeId === $me;
            $views->put($employeeId, TaskResponseView::make($employeeId, $row?->status, $task->status, $self, $reviewer || $self, $row === null ? null : [
                'id' => $row->id,
                'remarks' => $row->remarks,
                'review_remarks' => $row->review_remarks,
                'reviewed_by' => $row->reviewed_by,
                'submitted_at' => $row->submitted_at,
                'reviewed_at' => $row->reviewed_at,
                'revision' => $row->revision,
            ]));
        }

        return $views;
    }

    /** @param list<string> $taskIds
     * @return Collection<int, ProjectTaskFileView>
     */
    public function files(Access $actor, array $taskIds): Collection
    {
        $rows = $this->visibleFiles($actor, $this->fileTasks($actor, $taskIds))->orderBy('created_at')->get();
        $uploaderIds = [];
        foreach ($rows as $row) {
            if ($row->uploaded_by !== null) {
                $uploaderIds[] = $row->uploaded_by;
            }
        }
        $accounts = app(AccountNames::class)->list($actor, $uploaderIds)->keyBy('id');
        $employeeIds = [];
        foreach ($accounts as $account) {
            if ($account->employee_id !== null) {
                $employeeIds[] = $account->employee_id;
            }
        }
        $employees = app(AssignmentEmployees::class)->identities($employeeIds)->keyBy('id');

        return $rows->map(static function (ProjectTaskFile $row) use ($accounts, $employees): ProjectTaskFileView {
            $user = $accounts->get($row->uploaded_by);

            return Snapshot::of($row, ProjectTaskFileView::class, [
                'uploader' => $user === null ? null : new AccountLabel($user->id, $user->name, $user->employee_id, $user->is_active, $employees->get($user->employee_id)),
            ]);
        });
    }

    /** @param list<string> $taskIds
     * @return Collection<int, TaskCommentView>
     */
    public function comments(Access $actor, array $taskIds): Collection
    {
        $ids = $this->taskQuery($actor, false)->whereKey($taskIds)->pluck('id');

        return TaskComment::query()->whereIn('task_id', $ids)->orderBy('created_at')->get()
            ->map(static fn (TaskComment $row): TaskCommentView => Snapshot::of($row, TaskCommentView::class));
    }

    /** @return Collection<int, TaskCommentView> */
    public function recentComments(Access $actor, string $projectId): Collection
    {
        return TaskComment::query()->whereIn('task_id', $this->taskQuery($actor, false)->where('project_id', $projectId)->select('id'))
            ->orderByDesc('created_at')->limit(8)->get()
            ->map(static fn (TaskComment $row): TaskCommentView => Snapshot::of($row, TaskCommentView::class));
    }

    /** @param list<string> $taskIds
     * @return array{files: Collection<string, int>, comments: Collection<string, int>}
     */
    public function counts(Access $actor, array $taskIds): array
    {
        $tasks = $this->fileTasks($actor, $taskIds);

        return [
            'files' => $this->visibleFiles($actor, $tasks)->selectRaw('task_id, count(*) as n')->groupBy('task_id')->pluck('n', 'task_id'),
            'comments' => TaskComment::query()->whereIn('task_id', $tasks->modelKeys())->selectRaw('task_id, count(*) as n')->groupBy('task_id')->pluck('n', 'task_id'),
        ];
    }

    public function download(Access $actor, string $id): StreamedResponse
    {
        $row = ProjectTaskFile::query()->with(['task.assignments', 'task.project'])->find($id);
        abort_if($row === null || $row->task === null, 404, 'That file was not found.');
        // Whoever runs the task's project — an all-data PROJECTS holder or
        // its Task Leader, the same widening as WorkScope — or an assignee.
        abort_unless(WorkRules::runsProject($actor, $row->task->project)
            || ($actor->hasEmployeeLink() && $row->task->hasAssignee($actor->ownEmployeeId())), 403, 'You do not have access to that.');
        // A peer's evidence on an INDIVIDUAL task is theirs and the reviewer's
        // alone, even for an all-data PROJECTS holder or a Task Leader who is
        // on the crew.
        abort_if($row->kind === 'SUBMISSION' && TaskSubmissionPolicy::isIndividual($row->task->submission_mode)
            && ! WorkRules::reviewsTask($actor, $row->task)
            && ! ($actor->hasEmployeeLink() && $row->employee_id !== null && $row->employee_id === $actor->ownEmployeeId()), 403, 'You do not have access to that.');
        $disk = Storage::disk('local');
        abort_if($row->path === '' || ! $disk->exists($row->path), 404, 'That file was not found.');

        return $disk->download($row->path, $row->file_name, [
            'Content-Type' => $row->mime !== '' ? $row->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return Builder<ProjectTask> */
    private function taskQuery(Access $actor, bool $own): Builder
    {
        return WorkScope::tasks($actor, $own);
    }

    /**
     * The content-free response facts of each task, as correlated subqueries
     * INSIDE the task select: no extra statement per page, and an export
     * reads them in the same snapshot as the rows.
     *
     * @param  Builder<ProjectTask>  $query
     * @return Builder<ProjectTask>
     */
    private function withResponseFacts(Builder $query): Builder
    {
        return $query->withCount([
            'submissions as responses_submitted' => static fn (Builder $q) => $q->where('project_task_submissions.status', 'SUBMITTED'),
            'submissions as responses_approved' => static fn (Builder $q) => $q->where('project_task_submissions.status', 'APPROVED'),
            'submissions as responses_returned' => static fn (Builder $q) => $q->where('project_task_submissions.status', 'RETURNED'),
        ])->withExists(['files as has_submission_files' => static fn (Builder $q) => $q->where('project_task_files.kind', 'SUBMISSION')])
            ->withCasts(['responses_submitted' => 'integer', 'responses_approved' => 'integer', 'responses_returned' => 'integer']);
    }

    /**
     * The in-scope tasks among `$taskIds`, with what the file privacy rule
     * needs: each task's mode and, for a linked actor, whether they are on
     * its crew. One select.
     *
     * @param  list<string>  $taskIds
     * @return EloquentCollection<int, ProjectTask>
     */
    private function fileTasks(Access $actor, array $taskIds): EloquentCollection
    {
        return $this->taskQuery($actor, false)->whereKey($taskIds)->select(['project_tasks.id', 'project_tasks.project_id', 'project_tasks.submission_mode'])
            ->when($actor->hasEmployeeLink(), static fn (Builder $q) => $q->withExists([
                'assignments as viewer_assigned' => static fn (Builder $a) => $a->where('employee_id', '=', $actor->ownEmployeeId()),
            ]))->get();
    }

    /**
     * The files of `$tasks` the actor may see. Everything on a GROUP task,
     * and everything on an INDIVIDUAL task the actor reviews (a PROJECTS
     * manager, or the project's Task Leader, off its crew —
     * WorkRules::reviewsTask(), decided here per task from the led project
     * ids so a batch costs one lookup). On any other
     * INDIVIDUAL task: the brief, and the actor's OWN evidence — never a
     * peer's. The own-evidence arm exists only for a linked actor, because
     * `employee_id = null` would compile to IS NULL and match stray rows.
     *
     * @param  EloquentCollection<int, ProjectTask>  $tasks  from fileTasks()
     * @return Builder<ProjectTaskFile>
     */
    private function visibleFiles(Access $actor, EloquentCollection $tasks): Builder
    {
        $open = [];
        $restricted = [];
        $manages = WorkRules::managesProjects($actor);
        $led = $manages ? [] : WorkScope::ledProjectIds($actor);
        foreach ($tasks as $task) {
            $runs = $manages || in_array($task->project_id, $led, true);
            if (! TaskSubmissionPolicy::isIndividual($task->submission_mode) || ($runs && ! $task->getAttribute('viewer_assigned'))) {
                $open[] = $task->id;
            } else {
                $restricted[] = $task->id;
            }
        }

        return ProjectTaskFile::query()->where(static function (Builder $q) use ($actor, $open, $restricted): void {
            $q->whereIn('task_id', $open);
            if ($restricted !== []) {
                $q->orWhere(static function (Builder $r) use ($actor, $restricted): void {
                    $r->whereIn('task_id', $restricted)->where(static function (Builder $k) use ($actor): void {
                        $k->where('kind', '<>', 'SUBMISSION');
                        if ($actor->hasEmployeeLink()) {
                            $k->orWhere('employee_id', '=', $actor->ownEmployeeId());
                        }
                    });
                });
            }
        });
    }
}
