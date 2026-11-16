<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Domain\Enums\ModuleCode;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskSubmission;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Modules\WorkManagement\Infrastructure\ClientScope;
use App\Modules\WorkManagement\Infrastructure\ProjectMembership;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Support\Access;
use App\Support\CodeSequence;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Owned task/project shapes and shared operation lookups, independent of presentation.
 *
 * THE TASK LOCK PROTOCOL. Every writer of a task's status or mode, its crew
 * (project_task_assignees), its members' responses (project_task_submissions)
 * or its SUBMISSION evidence queues on the task row:
 *
 *  - L1. The task row FOR UPDATE, through {@see self::lockedTask()}, before
 *    the writer decides anything about the task; the plain reads before it
 *    may only refuse early. ReviewTask, ReviewTaskResponse, ChangeTaskStatus,
 *    UpdateTaskDetails, SubmitTask's individual path and DeleteSubmission
 *    make it the FIRST statement of their transaction. AttachSubmission
 *    cannot: it runs inside AttachmentStore's transaction, which takes the
 *    budget lock (L5) and then reads the usage PLAINLY, so its snapshot is
 *    already open when its persist callback takes L1. The GROUP SubmitTask
 *    and StartTask write only the task row, with one autocommit guarded
 *    UPDATE, which takes the same row lock. CreateTask's crew belongs to a
 *    task nobody else can reach yet.
 *  - L2. Locking reads after L1: the project row (P, below), the actor's
 *    place on the crew ({@see self::stillAssigned()}), the crew and the
 *    responses ReviewTask, ReviewTaskResponse and ChangeTaskStatus decide on,
 *    the file DeleteSubmission removes, and AttachSubmission's own
 *    response. A locking read always sees the
 *    latest committed row, even where a snapshot was opened before L1, so
 *    AttachSubmission decides on nothing else. Of these, only the response
 *    reads lock anything in project_task_submissions, and their writers
 *    never insert into it: a gap such a read locks there only makes a
 *    neighbouring task's first submission wait.
 *  - L3. Every other read of these rows after L1 is PLAIN, and fresh ONLY
 *    because nothing was read plainly before L1 in that transaction.
 *    REPEATABLE READ takes its snapshot at the first plain read — not at
 *    BEGIN, and not at a locking read — and every writer of these rows holds
 *    L1 while it writes. These reads are SubmitTask's own response row,
 *    DeleteSubmission's own response state, UpdateTaskDetails' discard
 *    reads, and syncAssignees()' crew and response reads. They stay
 *    plain ON PURPOSE: a locking read of a missing key, or of all of a task's
 *    rows, also locks the gaps next to them, which reach into NEIGHBOURING
 *    tasks' keys. In `pts_task_employee_unq`, SubmitTask inserts right after
 *    its own read, so two first submissions on different tasks sharing such
 *    a gap would deadlock (1213); the unique index backs its plain read up.
 *    NEVER add a plain read before L1 in these writers: their plain reads
 *    could then miss a commit that landed before L1.
 *  - L4. Updates are guarded — `whereKey()->where('status', $seen)`, plus
 *    `->where('revision', $seen)` where the row carries one — and zero rows
 *    means somebody else moved first. Deletes go by primary key, except
 *    syncAssignees()' delete of the crew rows it has just read, by
 *    (task_id, employee_id).
 *  - L5. `copems:attachment-budget` (AttachmentStore) is always taken BEFORE
 *    L1, never after, so an upload and a task writer cannot wait on each
 *    other in a cycle.
 *  - P. The PROJECT row FOR UPDATE ({@see self::lockedProject()}),
 *    for a decision on a project fact: its Task Leader, its status, its
 *    dates. A TASK writer takes it only AFTER its task lock — L1 in
 *    ChangeTaskStatus and UpdateTaskDetails, and in ReviewTask and
 *    ReviewTaskResponse for an actor who is not a manager; the whole-table
 *    code scan in CreateTask. A PROJECT writer — ChangeProjectStatus,
 *    UpdateProject, ScheduleProject — takes it as the FIRST statement of its
 *    transaction and then reads the project's tasks PLAINLY, never FOR
 *    UPDATE: those reads are fresh because nothing was read before P, and
 *    every writer of a task fact such a writer decides on (a reopen, a new
 *    task, a start or due date) holds P while it writes. So locks always go
 *    task(s) → project, never the other way round, and a project writer
 *    takes no task lock at all: locking a project's tasks by project_id
 *    would lock index gaps CreateTask's insert needs, a deadlock. DeleteProject,
 *    whose FK cascade reaches the tasks, first takes CreateTask's own
 *    whole-table scan — the SAME statement, `pluck('code')` through the unique
 *    code index, which no task writer updates; a scan through another index
 *    walks the rows in another order and deadlocks with it — then P. Writers that close or advance a task without
 *    P (StartTask, SubmitTask, a manager's ReviewTask) can at worst make a
 *    project writer refuse "complete every task" once too often — never let
 *    it accept a state that is not so.
 *
 * The self-review refusal ({@see self::reviewsTask()}) and the INDIVIDUAL
 * completion gate rely on it: a crew or response change is either visible to
 * the decision's locked reads, or waits for the decision to commit and then
 * finds the task moved. So does "a completed project holds no open task":
 * a reopen or a new task either committed before a completion took P, or
 * waits for it and then finds the project completed.
 */
final class WorkRules
{
    /**
     * The broad EMS grant for internal work. Client work uses
     * managesProject() so that the same grant cannot bypass membership.
     */
    public static function managesProjects(Access $actor): bool
    {
        return $actor->mayReadAllData(ModuleCode::PROJECTS);
    }

    public static function managesProject(Access $actor, Project|ProjectView|null $project): bool
    {
        if ($project === null || ! $actor->hasModule(ModuleCode::PROJECTS) || ! ClientScope::allowsWork($actor, $project->client_id)) {
            return false;
        }

        return self::managesProjects($actor) || ($project->client_id !== null
            && ClientScope::manageable($actor)->whereKey($project->client_id)->exists());
    }

    public static function editsWork(Access $actor, Project|ProjectView|null $project, bool $lock = false): bool
    {
        return self::managesProject($actor, $project) || ($project !== null
            && ClientScope::allowsWork($actor, $project->client_id, $lock)
            && ProjectMembership::edits($actor, $project->id, $lock));
    }

    /**
     * Whether the actor is the project's named Task Leader: `manager_id` is
     * their ACCOUNT. Keyed on the account, not the employee, exactly like the
     * leader's reminders — another account of the same person leads nothing.
     * Fails closed for a guest (a null id matches no one) and for an account
     * without PROJECTS. Compares the owned column only: the IdentityAccess
     * account behind `manager_id` is never loaded here.
     *
     * Read it from a row fetched in THIS request — inside a command, from the
     * project row locked after the task (P in the lock protocol above).
     */
    public static function leadsProject(Access $actor, Project|ProjectView|null $project): bool
    {
        return $project !== null && $actor->isSignedIn() && $actor->hasModule(ModuleCode::PROJECTS)
            && ClientScope::allowsWork($actor, $project->client_id)
            && $project->manager_id !== null && (string) $project->manager_id === $actor->userId;
    }

    /**
     * Who runs a project: a Projects manager, or its own Task Leader.
     *
     * DELIBERATE DIVERGENCE: the reference gives the Task Leader no
     * authority at all — `manager_id` only names who is accountable. In ems
     * the leader of a project may, on that project alone: change its
     * status and its tasks' statuses by hand, plan the project's and its
     * tasks' start dates, and review its tasks ({@see self::reviewsTask()}).
     * Project administration stays with managesProject(). Task planning can
     * also be delegated through editsWork(), without review authority.
     */
    public static function runsProject(Access $actor, Project|ProjectView|null $project): bool
    {
        return self::managesProject($actor, $project) || self::leadsProject($actor, $project);
    }

    /** respond.ts `forbidden()` — the sentence every denied action toasts. */
    public static function forbiddenMessage(): string
    {
        return 'You do not have access to that.';
    }

    /**
     * Project managers and client owners may run tasks they share, but a
     * leader alone acts only off the crew. Review decisions check the crew
     * separately; client projects always require current membership.
     */
    public static function runsTask(Access $actor, ProjectTask|ProjectTaskView $task): bool
    {
        return self::taskAuthority(self::managesProject($actor, $task->project), self::leadsProject($actor, $task->project), $task->hasAssignee($actor->employeeId));
    }

    /** @param iterable<ProjectTaskView> $tasks
     * @return array<string, bool>
     */
    public static function taskPermissions(Access $actor, Project|ProjectView $project, iterable $tasks): array
    {
        $manages = self::managesProject($actor, $project);
        $leads = self::leadsProject($actor, $project);
        $permissions = [];
        foreach ($tasks as $task) {
            $permissions[$task->id] = $task->project_id === $project->id
                && self::taskAuthority($manages, $leads, $task->hasAssignee($actor->employeeId));
        }

        return $permissions;
    }

    private static function taskAuthority(bool $manages, bool $leads, bool $onCrew): bool
    {
        return $manages || ($leads && ! $onCrew);
    }

    /**
     * Whether the actor may accept or return this task: someone who runs its
     * project — a PROJECTS manager, or the project's Task Leader
     * ({@see self::runsProject()}) — and is NOT one of its assignees: nobody
     * decides on work they share. Null-safe: an employee-less actor is never
     * on a crew. Broad managers also need client access. The screens use
     * this to hide the decision; ReviewTask and
     * ReviewTaskResponse re-check it under the task lock.
     */
    public static function reviewsTask(Access $actor, ProjectTask|ProjectTaskView $task): bool
    {
        // The crew comes first because nobody reviews work they share.
        if ($task->hasAssignee($actor->employeeId)) {
            return false;
        }

        return self::managesProject($actor, $task->project) || self::leadsProject($actor, $task->project);
    }

    /** The refusal for naming a Task Leader who could never open Project Management. */
    public static function leaderRefusal(): string
    {
        return 'Choose an active account that can open Project Management as project lead.';
    }

    /** The refusal a manager on the crew gets for Accept, Return, Complete or a response decision. */
    public static function selfReviewMessage(): string
    {
        return 'You are assigned to this task, so another reviewer has to decide it.';
    }

    /**
     * L1 — the task row FOR UPDATE, optionally pinned to its project. Call it
     * before deciding anything about the task, as the first statement of the
     * transaction wherever the writer opens that transaction itself (see the
     * class docblock for AttachSubmission, which cannot).
     */
    public static function lockedTask(Access $actor, string $taskId, ?string $projectId = null): ?ProjectTask
    {
        $task = ProjectTask::query()
            ->when($projectId !== null, static fn (Builder $query) => $query->where('project_id', $projectId))
            ->whereKey($taskId)->lockForUpdate()->first();
        if ($task !== null && $task->project?->client_id !== null) {
            // Keep the lock order task, project, client before deciding on access.
            $project = self::lockedProject($actor, $task->project_id);
            if ($project === null) {
                return null;
            }
            $task->setRelation('project', $project);
        }

        return $task;
    }

    /**
     * L2 — whether the actor is still on the locked task's crew, read FOR
     * UPDATE so a removal committed while this request waited on L1 is seen.
     * An actor with no employee link is never on a crew.
     */
    public static function stillAssigned(ProjectTask $locked, Access $actor): bool
    {
        if (! $actor->hasEmployeeLink()) {
            return false;
        }

        return ProjectTaskAssignee::query()->where('task_id', $locked->id)
            ->where('employee_id', '=', $actor->ownEmployeeId())->lockForUpdate()->first() !== null;
    }

    /**
     * The actor's own response state on a task — SUBMITTED, RETURNED or
     * APPROVED, or null when they have no row. A PLAIN read, for an early
     * refusal message only: a command re-reads it under L1 before it decides.
     */
    public static function ownResponseStatus(Access $actor, ProjectTask $task): ?string
    {
        if (! $actor->hasEmployeeLink()) {
            return null;
        }
        $status = ProjectTaskSubmission::query()->where('task_id', $task->id)
            ->where('employee_id', '=', $actor->ownEmployeeId())->value('status');

        return is_string($status) ? $status : null;
    }

    /** @return array{status: string, due_date: string|null, has_subtasks?: bool} */
    public static function taskShape(ProjectTask|ProjectTaskView $task): array
    {
        $shape = ['status' => $task->status, 'due_date' => self::dateKey($task->due_date)];
        if (isset($task->has_subtasks) && $task->has_subtasks) {
            $shape['has_subtasks'] = true;
        }

        return $shape;
    }

    /**
     * @param  iterable<array-key, ProjectTask|ProjectTaskView>  $tasks
     * @return list<array{status: string, due_date: string|null, has_subtasks?: bool}>
     */
    public static function taskShapes(iterable $tasks): array
    {
        $shapes = [];
        foreach ($tasks as $task) {
            $shapes[] = self::taskShape($task);
        }

        return $shapes;
    }

    /** @return array{status: string, due_date: string|null, manager_id: string|null, start_date: string} */
    public static function projectShape(Project|ProjectView $project): array
    {
        return [
            'status' => $project->status,
            'due_date' => self::dateKey($project->due_date),
            'manager_id' => $project->manager_id === null ? null : (string) $project->manager_id,
            'start_date' => self::dateKey($project->start_date) ?? '',
        ];
    }

    /** A date column (or 'Y-m-d' string) as the key the policies compare. */
    public static function dateKey(CarbonImmutable|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonImmutable
            ? $value->format('Y-m-d')
            : substr($value, 0, 10);
    }

    /** hris.ts `fmtDate` — "Aug 05, 2026", shared through HrisFormat. Date columns carry no zone to shift. */
    public static function fmtDate(CarbonImmutable|string|null $value): string
    {
        return HrisFormat::date($value);
    }

    /**
     * hris.ts `nextCode()` — the next `PRJ-001`-style code past the highest in
     * use, through the one mint in CodeSequence. Read the codes under lock and
     * insert in the same transaction. Deletes are hard, so the highest code
     * CAN be handed out again; the unique index is the backstop.
     *
     * PROJECT MANAGEMENT KEEPS ITS PREFIX. Eleven other coded columns carry
     * no `PREFIX-NNN`, but `projects.code` and `project_tasks.code` do, so
     * this wrapper mints through `nextPrefixed()` and not `next()`. The
     * zero-pad is why the project and task lists sort as plain text.
     *
     * @param  iterable<array-key, string|null>  $codes
     */
    public static function nextCode(string $prefix, iterable $codes, int $width = 3): string
    {
        return CodeSequence::nextPrefixed($prefix, $codes, $width);
    }

    /**
     * `task.code || task.title` — how a task is named in a bell or an audit
     * sentence; a task without a code is announced by its title.
     */
    public static function taskLabel(ProjectTask|ProjectTaskView $task): string
    {
        // "Task TSK-014". Task codes keep their prefix while the other eleven
        // coded columns carry none, so the noun is not load-bearing here the
        // way it is for a bare id — it is there because every other notice in
        // the inbox names its noun, and one that did not would read as the
        // odd one out.
        return trim($task->code) !== '' ? 'Task '.trim($task->code) : $task->title;
    }

    /**
     * The crew a notice goes to: every assignee EXCEPT the actor — nobody is
     * told about their own action — and except `$alsoExcept`, an employee
     * whose account the caller has already told directly, so one action never
     * lands twice in one bell.
     *
     * @return list<string>
     */
    public static function crewExcept(ProjectTask|ProjectTaskView $task, Access $actor, ?string $alsoExcept = null): array
    {
        $skip = array_values(array_filter(
            [$actor->employeeId, $alsoExcept],
            static fn (?string $id): bool => $id !== null && $id !== '',
        ));

        return array_values(array_diff($task->assigneeIds(), $skip));
    }

    /**
     * Which side of a task thread the actor speaks from — a fact about the
     * actor, not a choice (api/tasks/[id]/comments/route.ts:39-47): a
     * Projects manager, or the project's Task Leader off the task's crew
     * ({@see self::runsTask()}), is MANAGEMENT; everyone else the ASSIGNEE.
     * Without a task only a manager speaks for management.
     *
     * @return 'MANAGEMENT'|'ASSIGNEE'
     */
    public static function commentSide(Access $actor, ProjectTask|ProjectTaskView|null $task = null): string
    {
        if ($task === null) {
            return self::managesProjects($actor) ? 'MANAGEMENT' : 'ASSIGNEE';
        }

        return self::runsTask($actor, $task) || self::editsWork($actor, $task->project) ? 'MANAGEMENT' : 'ASSIGNEE';
    }

    /**
     * The TASK_ASSIGNED bell's body: "Survey — starts Sep 28, 2026, due
     * Oct 05, 2026." Each date appears only when the task has one; with
     * neither, the body is the bare title.
     */
    public static function assignedBody(string $title, ?string $startDate, ?string $dueDate): string
    {
        $parts = [];
        if ($startDate !== null) {
            $parts[] = 'starts '.self::fmtDate($startDate);
        }
        if ($dueDate !== null) {
            $parts[] = 'due '.self::fmtDate($dueDate);
        }

        return $parts === [] ? $title : $title.' — '.implode(', ', $parts).'.';
    }

    /** @param array<array-key, mixed> $ids
     * @return list<string> */
    public static function cleanIds(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if (is_string($id)) {
                $id = trim($id);
                if ($id !== '' && ! in_array($id, $clean, true)) {
                    $clean[] = $id;
                }
            }
        }

        return $clean;
    }

    /** The project through the actor's read scope (WorkScope) — a led project included. */
    public static function project(Access $actor, string $id): ?Project
    {
        return WorkScope::projects($actor)->whereKey($id)->first();
    }

    /** A task of that project through the actor's read scope, with its crew and project loaded. */
    public static function task(Access $actor, string $projectId, ?string $id): ?ProjectTask
    {
        if ($id === null) {
            return null;
        }

        return WorkScope::tasks($actor)->with(['assignments', 'project'])
            ->where('project_id', $projectId)->whereKey($id)->first();
    }

    /**
     * P — the project row FOR UPDATE (see the protocol above). A task writer
     * takes it only AFTER its task lock (L1, or CreateTask's code scan); a
     * project writer takes it as the FIRST statement of its transaction and
     * then reads the project's tasks plainly — never FOR UPDATE.
     */
    public static function lockedProject(Access $actor, string $projectId): ?Project
    {
        $project = Project::query()->whereKey($projectId)->lockForUpdate()->first();

        return $project !== null && ClientScope::allowsWork($actor, $project->client_id, lock: true) ? $project : null;
    }

    public static function ownTask(Access $actor, ?string $id): ?ProjectTask
    {
        abort_unless($actor->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS), 403);
        if ($id === null || ! $actor->hasEmployeeLink()) {
            return null;
        }
        $employeeId = $actor->ownEmployeeId();

        return WorkScope::tasks($actor, true)->with(['assignments', 'project'])->whereKey($id)
            ->whereHas('assignments', static fn (Builder $query) => $query->where('employee_id', '=', $employeeId))->first();
    }

    public static function assigneeNames(ProjectTask $task): string
    {
        $names = app(AssignmentEmployees::class)->names($task->assigneeIds());

        return $names === [] ? EmployeeName::NONE : implode(', ', $names);
    }

    public static function actorName(ProjectTask $task, Access $actor): string
    {
        if (! $actor->hasEmployeeLink() || ! $task->hasAssignee($actor->employeeId)) {
            return $actor->fullName;
        }

        return app(AssignmentEmployees::class)->names([$actor->ownEmployeeId()])[$actor->ownEmployeeId()] ?? $actor->fullName;
    }
}
