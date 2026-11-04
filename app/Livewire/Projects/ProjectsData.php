<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Modules\IdentityAccess\Contracts\AccountLabel as User;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\Views\WorkSiteView as WorkSite;
use App\Modules\Workforce\Contracts\DirectoryEmployee as EmployeeProfile;
use App\Modules\WorkManagement\Application\Queries\WorkRecords;
use App\Modules\WorkManagement\Application\TaskFilePolicy;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectTaskView as ProjectTask;
use App\Modules\WorkManagement\Contracts\Views\ProjectView as Project;
use App\Modules\WorkManagement\Domain\Tasks\ManualTaskStatus;
use App\Modules\WorkManagement\Domain\Tasks\TaskSubmissionPolicy;
use App\Support\Access;
use App\Support\CodeSequence;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use App\Support\TextSort;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Collection as EloquentCollection;

/**
 * Shared reads, rollups and formatting for the PROJECTS module — the pieces of
 * `src/lib/hris.ts` and `src/lib/attachments.ts` these screens consume, plus
 * the exact filter / sort pipeline of `projects-page.tsx` and
 * `project-detail.tsx` so the two Livewire screens and the two CSV export
 * controllers cannot drift apart. The `export.ts` CSV builder and the
 * `notifications.ts` fan-out live in `App\Support\Csv` and
 * `App\Domain\Notifications\Notifier` — one copy each, shared by every
 * module.
 *
 * Everything status- or health-shaped delegates to {@see ProjectPolicy} and
 * {@see TaskPolicy}; nothing here re-derives a domain rule. The one place
 * the pipelines leave the reference is "waiting on review" — the REVIEW
 * queue and the stack order — which follows the mode-aware rule
 * ({@see ProjectTask::awaitsReview()}, i.e. TaskSubmissionPolicy): an
 * INDIVIDUAL task in progress with a response handed in waits too. That is
 * what keeps this collection path equal to WorkLists' SQL, which the
 * pagination tests pin it against.
 */
final class ProjectsData
{
    /**
     * attachments.ts — API mode: 2 MB per file, no client-side pool. Only the
     * FALLBACK when `copems.uploads.max_file_bytes` is unset; read the cap
     * through {@see self::maxFileBytes()}, never this constant.
     */
    public const int MAX_FILE_BYTES = 2_000_000;

    /**
     * storage.ts — 1 GB sanity ceiling across every attachment. Only the
     * FALLBACK when `copems.uploads.storage_ceiling_bytes` is unset; read it
     * through {@see self::storageCeilingBytes()}, never this constant.
     */
    public const int STORAGE_CEILING_BYTES = 1_000_000_000;

    /** Where task attachments live on the local disk. */
    public const string FILE_DIR = 'task-files';

    /**
     * What each submission mode means, printed under its label
     * (TaskPolicy::MODES) wherever a reviewer chooses one — the add dialog and
     * the review dialog's edit panel read this one copy, so they cannot drift.
     *
     * @var array<string, string>
     */
    public const array SUBMISSION_MODE_HELP = [
        TaskPolicy::MODE_GROUP => 'Any assignee submits one response for everyone; you accept or return it once.',
        TaskPolicy::MODE_INDIVIDUAL => 'Each assignee submits their own response, seen only by them and reviewers. You approve each one, then complete the task.',
    ];

    /* ------------------------------ scoping ------------------------------- */

    /**
     * GET /api/projects: a manager (`all_data` + PROJECTS) reads every project;
     * anyone else reads the projects holding a task they are ONE OF the
     * assignees of, plus the projects they are the Task Leader of
     * (WorkScope::projects()); an employee-less account that leads nothing
     * reads none.
     *
     * Membership lives in the `project_task_assignees` pivot, so the scope
     * walks two relations: projects → tasks → assignments.employee_id.
     *
     * @return Collection<int, Project>
     */
    public static function scopedProjects(Access $actor): EloquentCollection
    {
        return app(WorkRecords::class)->projects($actor);
    }

    /**
     * GET /api/tasks: the widening module is PROJECTS ALONE — a TASKS holder
     * with `all_data` still sees only their own queue (guard transcription in
     * ScopesToActor's docblock).
     *
     * Narrowed through the pivot (`assignments.employee_id`); there is no
     * employee column on project_tasks. A PROJECTS holder also reads every
     * task of a project they are the Task Leader of (WorkScope::tasks()),
     * whether or not they are on its crew. `assignees` is eager-loaded
     * here so every consumer — the show page and the CSV export — gets names
     * without an N+1, and {@see ProjectTask::assigneeIds()} reads the loaded
     * relation. Each row also carries its content-free response counts, which
     * {@see ProjectTask::awaitsReview()} needs for the REVIEW queue and the
     * stack. (My Tasks never comes through here: it scopes with its own
     * ownTasks() query and eager-loads the relation itself.)
     *
     * @return Collection<int, ProjectTask>
     */
    public static function scopedTasks(Access $actor): EloquentCollection
    {
        return app(WorkRecords::class)->tasks($actor);
    }

    /**
     * The organisation-wide gate, requireAllDataModule("PROJECTS"): creating,
     * deleting and fully editing projects and tasks. Statuses, start dates and
     * reviews are also the project's Task Leader's ({@see self::runsProject()}).
     */
    public static function managesProjects(Access $actor): bool
    {
        return WorkRules::managesProjects($actor);
    }

    /** respond.ts `forbidden()` — the sentence every denied action toasts. */
    public static function forbiddenMessage(): string
    {
        return WorkRules::forbiddenMessage();
    }

    /**
     * The task's reviewer ({@see WorkRules::reviewsTask()}; ReviewTask's
     * first DELIBERATE DIVERGENCE paragraph): a PROJECTS manager, or the
     * project's Task Leader, who is not one of its assignees. They alone may
     * accept, return or complete the
     * task and approve or return a member's response, and on an INDIVIDUAL
     * task they alone see every member's content (WorkRecords::responses()
     * and files() apply the same rule). Display only here — the commands
     * re-check it under the task lock.
     */
    public static function reviewsTask(Access $actor, ProjectTask $task): bool
    {
        return WorkRules::reviewsTask($actor, $task);
    }

    /**
     * The statuses an assignee can see their own task in — the My Tasks
     * filter's options. TaskPolicy::STATUSES plus APPROVED, because
     * WorkRecords::ownPage() filters on the member's own status
     * (TaskSubmissionPolicy::myStatus()), which can be an approved response.
     *
     * @return array<string, string>
     */
    public static function myStatuses(): array
    {
        return TaskSubmissionPolicy::MY_STATUSES;
    }

    /* ------------------------- individual responses ------------------------ */

    /*
     * Forwarders to the WorkManagement rules for INDIVIDUAL tasks, so the two
     * task screens and their Blade ask the same rule the commands re-check —
     * none of these decides anything itself.
     */

    /**
     * Every CURRENT assignee whose response is not approved yet, mapped to
     * their state (NONE for no row) — what "Complete task" waits on.
     *
     * @param  list<string>  $assigneeIds
     * @param  array<string, string>  $statusByEmployee  employee id => member status
     * @return array<string, string>
     */
    public static function completionGaps(array $assigneeIds, array $statusByEmployee): array
    {
        return TaskSubmissionPolicy::completionGaps($assigneeIds, $statusByEmployee);
    }

    /**
     * Why an INDIVIDUAL task cannot be completed yet, as the sentence beside
     * its disabled "Complete task" — "Waiting on: Ben Reyes (Not submitted),
     * Ana Cruz (Submitted)." in the dialog's assignee order — or '' when it
     * can be. A task nobody is on gets the completion gate's own refusal.
     * States only: this names who is outstanding, never what they wrote.
     *
     * @param  array<string, string>  $gaps  from completionGaps()
     * @param  list<EmployeeProfile>  $assignees  from sortedAssignees()
     */
    public static function completionWaitingOn(array $gaps, array $assignees): string
    {
        if ($assignees === []) {
            return TaskSubmissionPolicy::completeError([], []) ?? '';
        }
        $waiting = [];
        foreach ($assignees as $employee) {
            $id = (string) $employee->getKey();
            if (array_key_exists($id, $gaps)) {
                $waiting[] = self::employeeName($employee).' ('.TaskSubmissionPolicy::memberStatusLabel($gaps[$id]).')';
            }
        }

        return $waiting === [] ? '' : 'Waiting on: '.implode(', ', $waiting).'.';
    }

    /** Whether a GROUP response was ever handed in on the task row — one of the mode lock's triggers. */
    public static function groupHandedIn(?string $submittedBy, mixed $submittedAt, ?string $remarks): bool
    {
        return TaskSubmissionPolicy::groupHandedIn($submittedBy, $submittedAt, $remarks);
    }

    /**
     * Whether the task's submission mode can no longer change — the edit
     * panel hides the choice exactly where UpdateTaskDetails would refuse it.
     * Feed it the task's CONTENT-FREE facts (ProjectTask::responsesTotal(),
     * `has_submission_files`), never a file list narrowed for the viewer: a
     * manager on the crew, who cannot see a peer's evidence, must still see
     * the lock that evidence causes.
     */
    public static function modeLocked(string $taskStatus, bool $groupHandedIn, int $responseRows, bool $hasSubmissionFiles): bool
    {
        return TaskSubmissionPolicy::modeLocked($taskStatus, $groupHandedIn, $responseRows, $hasSubmissionFiles);
    }

    /**
     * Whether an assignee may still attach or withdraw their evidence — the
     * rule AttachSubmission and DeleteSubmission enforce, so Attach is never
     * offered where the server refuses it. On an INDIVIDUAL task it follows
     * the member's own response.
     */
    public static function evidenceEditable(?string $mode, string $taskStatus, ?string $memberStatus): bool
    {
        return TaskFilePolicy::submissionEditable($mode, $taskStatus, $memberStatus);
    }

    /** Whether a member of an INDIVIDUAL task may hand in, or resubmit, their own response now. */
    public static function memberCanRespond(string $taskStatus, ?string $memberStatus): bool
    {
        return TaskSubmissionPolicy::memberCanMove($taskStatus, $memberStatus, 'SUBMITTED');
    }

    /**
     * What a reviewer may move one member's response to right now — that
     * response's Approve / Return buttons, from the map ReviewTaskResponse
     * re-checks.
     *
     * @return list<string>
     */
    public static function reviewerMovesFrom(string $taskStatus, ?string $memberStatus): array
    {
        return TaskSubmissionPolicy::reviewerMovesFrom($taskStatus, $memberStatus);
    }

    /**
     * The status an assignee sees for their own task: their response's state
     * while an INDIVIDUAL task is in progress, otherwise the task's — so on a
     * GROUP task it IS the task status. Null and NONE both mean "no row".
     */
    public static function myState(?string $mode, string $taskStatus, ?string $memberStatus): string
    {
        return TaskSubmissionPolicy::myStatus($mode, $taskStatus, $memberStatus);
    }

    /* ---------------------------- projects list ---------------------------- */

    /**
     * The whole dataset behind /projects, already scoped: projects (code asc),
     * every visible task bucketed by project, sites and accounts for naming.
     *
     * @return array{
     *     projects: EloquentCollection<int, Project>,
     *     tasks: EloquentCollection<int, ProjectTask>,
     *     tasksByProject: array<string, list<ProjectTask>>,
     *     sites: EloquentCollection<int, WorkSite>,
     *     accounts: EloquentCollection<int, User>,
     * }
     */
    public static function projectsDataset(Access $actor): array
    {
        $projects = self::scopedProjects($actor);
        $tasks = self::scopedTasks($actor);

        $tasksByProject = [];
        foreach ($tasks as $task) {
            $tasksByProject[$task->project_id][] = $task;
        }

        return [
            'projects' => $projects,
            'tasks' => $tasks,
            'tasksByProject' => $tasksByProject,
            'sites' => app(OrganizationDirectory::class)->sites($actor),
            'accounts' => app(AccountNames::class)->list($actor),
        ];
    }

    /**
     * The filter + sort pipeline of projects-page.tsx:83-126, verbatim but
     * for the mode-aware REVIEW queue (see the class docblock).
     *
     * @param  EloquentCollection<int, Project>  $projects
     * @param  array<string, list<ProjectTask>>  $tasksByProject
     * @param  EloquentCollection<int, User>  $accounts
     * @param  array{q: string, status: string, site: string, manager: string, queue: string}  $filters
     * @return list<Project>
     */
    public static function visibleProjects(
        EloquentCollection $projects,
        array $tasksByProject,
        EloquentCollection $accounts,
        array $filters,
        string $sortKey,
        string $sortDir,
        string $today,
    ): array {
        $needle = mb_strtolower(trim($filters['q']));

        $filtered = [];
        foreach ($projects as $project) {
            if ($filters['status'] !== 'ALL' && $project->status !== $filters['status']) {
                continue;
            }
            if ($filters['site'] !== 'ALL' && $project->site_id !== $filters['site']) {
                continue;
            }
            if ($filters['manager'] !== 'ALL' && (string) $project->manager_id !== $filters['manager']) {
                continue;
            }

            $projectTasks = $tasksByProject[$project->id] ?? [];

            if ($filters['queue'] === 'REVIEW' && ! self::anyTask($projectTasks, static fn (ProjectTask $t): bool => $t->awaitsReview())) {
                continue;
            }
            if ($filters['queue'] === 'OVERDUE' && ! self::anyTask($projectTasks, static fn (ProjectTask $t): bool => TaskPolicy::isOverdue(self::taskShape($t), $today))) {
                continue;
            }
            if ($filters['queue'] === 'DUE_SOON'
                && ProjectPolicy::health(self::projectShape($project), self::taskShapes($projectTasks), $today) !== 'DUE_SOON') {
                continue;
            }
            if ($filters['queue'] === 'UNASSIGNED' && ! self::anyTask($projectTasks, static fn (ProjectTask $t): bool => $t->assigneeIds() === [])) {
                continue;
            }

            if ($needle !== '' && ! str_contains(mb_strtolower($project->code.' '.$project->name), $needle)) {
                continue;
            }

            $filtered[] = $project;
        }

        $managerName = static function (?int $id) use ($accounts): ?string {
            if ($id === null) {
                return null;
            }
            $account = $accounts->firstWhere('id', $id);

            return $account?->name;
        };

        usort($filtered, static fn (Project $a, Project $b): int => match ($sortKey) {
            'name' => self::compareText($a->name, $b->name),
            'manager' => self::compareText($managerName($a->manager_id), $managerName($b->manager_id)),
            'status' => self::compareText(ProjectPolicy::statusLabel($a->status), ProjectPolicy::statusLabel($b->status)),
            default => self::compareText($a->code, $b->code),
        });

        // The source sorts ascending and then reverses the whole array for
        // desc (projects-page.tsx:113) — blanks-last flips too, on purpose.
        return $sortDir === 'desc' ? array_reverse($filtered) : $filtered;
    }

    /* ----------------------------- tasks table ----------------------------- */

    /**
     * The filter + sort pipeline of project-detail.tsx:175-223, verbatim but
     * for the mode-aware REVIEW queue and stack order (see the class docblock).
     *
     * @param  list<ProjectTask>  $projectTasks
     * @param  EloquentCollection<int, EmployeeProfile>  $employees
     * @param  array{q: string, status: string, assignee: string, priority: string, queue: string}  $filters
     * @return list<ProjectTask>
     */
    public static function visibleTasks(
        array $projectTasks,
        EloquentCollection $employees,
        array $filters,
        string $sortKey,
        string $sortDir,
        string $today,
    ): array {
        $needle = mb_strtolower(trim($filters['q']));

        $filtered = [];
        foreach ($projectTasks as $task) {
            if ($filters['status'] !== 'ALL' && $task->status !== $filters['status']) {
                continue;
            }
            // 'UNASSIGNED' is a real option in the assignee filter; it can
            // never equal an id, so it matches only tasks with NO assignee.
            // A specific id matches every task that employee is ONE OF the
            // assignees of.
            if ($filters['assignee'] === 'UNASSIGNED') {
                if ($task->assigneeIds() !== []) {
                    continue;
                }
            } elseif ($filters['assignee'] !== 'ALL' && ! $task->hasAssignee($filters['assignee'])) {
                continue;
            }
            if ($filters['priority'] !== 'ALL' && $task->priority !== $filters['priority']) {
                continue;
            }
            if ($filters['queue'] === 'REVIEW' && ! $task->awaitsReview()) {
                continue;
            }
            if ($filters['queue'] === 'OVERDUE' && ! TaskPolicy::isOverdue(self::taskShape($task), $today)) {
                continue;
            }
            if ($filters['queue'] === 'DUE_SOON' && ! self::isTaskDueSoon($task, $today)) {
                continue;
            }
            if ($filters['queue'] === 'UNASSIGNED' && $task->assigneeIds() !== []) {
                continue;
            }
            if ($needle !== '' && ! str_contains(mb_strtolower($task->code.' '.$task->title), $needle)) {
                continue;
            }

            $filtered[] = $task;
        }

        // Sort key for the assignee column: the alphabetically-first
        // assignee's last name, so a task shared by Reyes and Garcia files
        // under G. Null (blank) for an unassigned task, which compareText
        // puts last. Resolved through $employees when the caller supplied
        // the row, else from the loaded relation.
        $firstAssigneeLastName = static function (ProjectTask $task) use ($employees): ?string {
            $names = [];

            foreach ($task->assigneeIds() as $id) {
                $employee = $employees->firstWhere('id', $id) ?? $task->assignees->firstWhere('id', $id);
                $names[] = $employee?->last_name;
            }

            usort($names, [self::class, 'compareText']);

            return $names[0] ?? null;
        };

        usort($filtered, static function (ProjectTask $a, ProjectTask $b) use ($sortKey, $firstAssigneeLastName): int {
            switch ($sortKey) {
                case 'stack':
                    // Waiting on review heads the stack whatever the mode.
                    $rank = TaskPolicy::stackRank(TaskSubmissionPolicy::stackStatus($a->submission_mode, $a->status, (int) $a->responses_submitted))
                        - TaskPolicy::stackRank(TaskSubmissionPolicy::stackStatus($b->submission_mode, $b->status, (int) $b->responses_submitted));
                    if ($rank !== 0) {
                        return $rank;
                    }
                    $due = self::compareText(self::dateKey($a->due_date), self::dateKey($b->due_date));

                    return $due !== 0 ? $due : self::compareText($a->code, $b->code);
                case 'task':
                    return self::compareText($a->title, $b->title);
                case 'assignee':
                    return self::compareText($firstAssigneeLastName($a), $firstAssigneeLastName($b));
                case 'priority':
                    return TaskPolicy::priorityRank($a->priority) - TaskPolicy::priorityRank($b->priority);
                case 'start':
                    return self::compareText(self::dateKey($a->start_date), self::dateKey($b->start_date));
                case 'due':
                    return self::compareText(self::dateKey($a->due_date), self::dateKey($b->due_date));
                case 'status':
                    return self::compareText(TaskPolicy::statusLabel($a->status), TaskPolicy::statusLabel($b->status));
                default:
                    return self::compareText($a->code, $b->code);
            }
        });

        return $sortDir === 'desc' ? array_reverse($filtered) : $filtered;
    }

    /**
     * project-detail.tsx:183-187 — due within 2 days and still open. Distinct
     * from ProjectPolicy::health()'s DUE_SOON, which also watches the project's
     * own deadline.
     */
    public static function isTaskDueSoon(ProjectTask $task, string $today): bool
    {
        $due = self::dateKey($task->due_date);
        if ($due === null || TaskPolicy::isClosed($task->status)) {
            return false;
        }
        $left = ProjectPolicy::daysUntilDate($due, $today);

        return $left >= 0 && $left <= ProjectPolicy::HEALTH_TASK_DUE_SOON_DAYS;
    }

    /* ------------------------- policy input shapes ------------------------- */

    /** @return array{status: string, due_date: string|null} */
    public static function taskShape(ProjectTask $task): array
    {
        return WorkRules::taskShape($task);
    }

    /**
     * @param  iterable<array-key, ProjectTask>  $tasks
     * @return list<array{status: string, due_date: string|null}>
     */
    public static function taskShapes(iterable $tasks): array
    {
        return WorkRules::taskShapes($tasks);
    }

    /** @return array{status: string, due_date: string|null, manager_id: string|null, start_date: string} */
    public static function projectShape(Project $project): array
    {
        return WorkRules::projectShape($project);
    }

    /* ------------------------------ formatting ----------------------------- */

    /** hris.ts `todayKey()` — today's Manila calendar day, ManilaClock's one clock read. */
    public static function todayKey(): string
    {
        return ManilaClock::todayKey();
    }

    /** hris.ts `fmtDate` — "Aug 05, 2026", shared through HrisFormat. Date columns carry no zone to shift. */
    public static function fmtDate(CarbonImmutable|string|null $value): string
    {
        return WorkRules::fmtDate($value);
    }

    /** As fmtDate, but for a UTC timestamp — displayed on the Manila wall clock. */
    public static function fmtDateManila(?CarbonImmutable $value): string
    {
        return $value === null ? '—' : $value->setTimezone(ManilaClock::TZ)->format('M d, Y');
    }

    /** hris.ts `fmtDateTime` — "Aug 31, 2026, 2:05 PM", Manila. */
    public static function fmtDateTime(?CarbonImmutable $value): string
    {
        return $value === null ? '—' : $value->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A');
    }

    /** A date column (or 'Y-m-d' string) as the key the policies compare. */
    public static function dateKey(CarbonImmutable|string|null $value): ?string
    {
        return WorkRules::dateKey($value);
    }

    /** hris.ts `employeeName()`. */
    public static function employeeName(?object $employee): string
    {
        return EmployeeName::display($employee);
    }

    /**
     * A task's assignees in display order (last name, then first) — the ONE
     * list the tasks table's Assignees cell, the review dialog header and
     * assigneeNames() all draw from, so a code sits under its own name
     * everywhere. Reads the loaded `assignees` relation (lazy-loads if a
     * caller forgot the eager load). `$only` restricts it to those ids — the
     * My Tasks dialog's "working with" line, which leaves the actor out.
     *
     * @param  list<string>|null  $only
     * @return list<EmployeeProfile>
     */
    public static function sortedAssignees(ProjectTask $task, ?array $only = null): array
    {
        $employees = $task->assignees->all();

        if ($only !== null) {
            $employees = array_values(array_filter(
                $employees,
                static fn (EmployeeProfile $e): bool => in_array((string) $e->getKey(), $only, true),
            ));
        }

        usort($employees, static function (EmployeeProfile $a, EmployeeProfile $b): int {
            $last = self::compareText($a->last_name, $b->last_name);

            return $last !== 0 ? $last : self::compareText($a->first_name, $b->first_name);
        });

        return $employees;
    }

    /**
     * Every assignee's display name in sortedAssignees() order, joined with
     * ', ' — or '—' for an unassigned task (or an empty `$only`).
     *
     * @param  list<string>|null  $only
     */
    public static function assigneeNames(ProjectTask $task, ?array $only = null): string
    {
        $employees = self::sortedAssignees($task, $only);

        if ($employees === []) {
            return EmployeeName::NONE;
        }

        return implode(', ', array_map(static fn (EmployeeProfile $e): string => self::employeeName($e), $employees));
    }

    /**
     * The crew a notice goes to: every assignee EXCEPT the actor — nobody is
     * told about their own action — and except `$alsoExcept`, an employee
     * whose account the caller has already told directly, so one action never
     * lands twice in one bell.
     *
     * @return list<string>
     */
    public static function crewExcept(ProjectTask $task, Access $actor, ?string $alsoExcept = null): array
    {
        return WorkRules::crewExcept($task, $actor, $alsoExcept);
    }

    /**
     * Which side of a task thread the actor speaks from — a fact about the
     * actor, not a choice (api/tasks/[id]/comments/route.ts:39-47): whoever
     * runs the task's project (a Projects manager, or its Task Leader) is
     * MANAGEMENT, everyone else the ASSIGNEE ({@see WorkRules::commentSide()}).
     *
     * @return 'MANAGEMENT'|'ASSIGNEE'
     */
    public static function commentSide(Access $actor, ?ProjectTask $task = null): string
    {
        return WorkRules::commentSide($actor, $task);
    }

    /**
     * The statuses the review dialog's "Set status" offers: ManualTaskStatus's
     * map from the task's CURRENT status and mode, less every target its
     * safeguards would refuse on what the dialog can see — the self-review
     * rule, a hand-in, a closed project, the INDIVIDUAL completion gate — and
     * less the moves the decision buttons already make (the reviewer map,
     * TaskPolicy::managerCanMoveTo()), so each move has one control and one
     * set of side effects on this screen. Display only: ChangeTaskStatus
     * re-decides each of them under the task lock, so a stale list can only
     * be refused, never obeyed blindly.
     *
     * @param  array<string, string>  $statusByEmployee  member responses by employee id (INDIVIDUAL)
     * @return list<string>
     */
    public static function manualMoves(Access $actor, ProjectTask $task, string $projectStatus, array $statusByEmployee): array
    {
        $onCrew = $task->hasAssignee($actor->employeeId);
        $handedIn = ManualTaskStatus::handedIn(
            $task->submission_mode,
            TaskSubmissionPolicy::groupHandedIn($task->submitted_by, $task->submitted_at, $task->remarks),
            $task->responsesTotal(),
        );
        $completion = $task->isIndividual() ? TaskSubmissionPolicy::completeError($task->assigneeIds(), $statusByEmployee) : null;

        $decisions = TaskPolicy::managerCanMoveTo($task->status, $task->submission_mode);

        return array_values(array_filter(
            ManualTaskStatus::canMoveTo($task->status, $task->submission_mode),
            static fn (string $to): bool => ! in_array($to, $decisions, true)
                && ! ($onCrew && ManualTaskStatus::isReviewMove($task->status, $to))
                && ManualTaskStatus::moveError($task->status, $to, $task->submission_mode, $handedIn, $projectStatus, $completion) === null,
        ));
    }

    /**
     * Whether the actor runs this TASK ({@see WorkRules::runsTask()}): a
     * Projects manager, or the project's Task Leader off its crew. Display
     * only — the commands re-check it under the task lock.
     */
    public static function runsTask(Access $actor, ProjectTask $task): bool
    {
        return WorkRules::runsTask($actor, $task);
    }

    /**
     * Who runs the project — a Projects manager, or its own Task Leader
     * ({@see WorkRules::runsProject()}): who may change its status and its
     * tasks' statuses by hand, plan start dates and review its tasks.
     * Display only — every command re-checks it against the locked rows.
     */
    public static function runsProject(Access $actor, ?Project $project): bool
    {
        return WorkRules::runsProject($actor, $project);
    }

    /** hris.ts `initials()` as the avatar fallbacks used it. */
    public static function initials(object $employee): string
    {
        return EmployeeName::initials($employee);
    }

    /** attachments.ts `humanSize`, shared through HrisFormat — no thousands separator, as in the reference. */
    public static function humanSize(int $bytes): string
    {
        return TaskFilePolicy::humanSize($bytes);
    }

    /**
     * hris.ts `nextCode()` — the next `PRJ-001`-style code past the highest in
     * use, through the one mint in CodeSequence. Read the codes under lock and
     * insert in the same transaction. Deletes are hard, so the highest code
     * CAN be handed out again; the unique index is the backstop.
     *
     * Projects and tasks keep their prefix, which the other eleven coded
     * columns do not carry; see {@see WorkRules::nextCode()}, which this
     * forwards to.
     *
     * @param  iterable<array-key, string|null>  $codes
     */
    public static function nextCode(string $prefix, iterable $codes, int $width = 3): string
    {
        return WorkRules::nextCode($prefix, $codes, $width);
    }

    /**
     * useSort.ts `compareText` — case-insensitive byte compare with a byte
     * tiebreak, blanks last whichever way the comparator itself is asked
     * (screens then reverse the array for desc). Shared through
     * App\Support\TextSort; this wrapper is the name the call sites use.
     */
    public static function compareText(?string $a, ?string $b): int
    {
        // One comparator for every sorted list, so no two modules order text
        // differently. It is a wrapper so call sites and Blade imports keep
        // this name; TextSortTest pins it.
        return TextSort::compare($a, $b);
    }

    /* ------------------------------ attachments ----------------------------- */

    /**
     * The per-file cap — `copems.uploads.max_file_bytes`
     * (COPEMS_MAX_UPLOAD_BYTES), the same key Documents and My Tasks read,
     * so one env var moves every upload surface together.
     */
    public static function maxFileBytes(): int
    {
        return TaskFilePolicy::maxFileBytes();
    }

    /**
     * The ceiling documents and task files share —
     * `copems.uploads.storage_ceiling_bytes` (COPEMS_STORAGE_CEILING_BYTES),
     * with Documents and My Tasks. Community and Chat keep their own.
     */
    public static function storageCeilingBytes(): int
    {
        return TaskFilePolicy::storageCeilingBytes();
    }

    /**
     * attachments.ts `attachmentRejection()`, API-mode branch: a per-file cap
     * with no client-side pool (MAX_TOTAL_BYTES is null there).
     */
    public static function attachmentRejection(string $name, int $size): ?string
    {
        return TaskFilePolicy::attachmentRejection($name, $size);
    }

    /* ------------------------------- predicates ------------------------------ */

    /**
     * @param  array<array-key, ProjectTask>  $tasks
     * @param  callable(ProjectTask): bool  $test
     */
    private static function anyTask(array $tasks, callable $test): bool
    {
        foreach ($tasks as $task) {
            if ($test($task)) {
                return true;
            }
        }

        return false;
    }
}
