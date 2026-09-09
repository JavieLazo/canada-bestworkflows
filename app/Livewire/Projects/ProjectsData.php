<?php

declare(strict_types=1);

namespace App\Livewire\Projects;

use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Domain\Projects\ProjectPolicy;
use App\Domain\Projects\TaskPolicy;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared reads, rollups and formatting for the PROJECTS module — the pieces of
 * `src/lib/hris.ts`, `src/lib/export.ts`, `src/lib/attachments.ts` and
 * `src/lib/notifications.ts` these screens consume, plus the exact filter /
 * sort pipeline of `projects-page.tsx` and `project-detail.tsx` so the two
 * Livewire screens and the two CSV export controllers cannot drift apart.
 *
 * Everything status- or health-shaped delegates to {@see ProjectPolicy} and
 * {@see TaskPolicy}; nothing here re-derives a domain rule.
 */
final class ProjectsData
{
    /** attachments.ts — API mode: 2 MB per file, no client-side pool. */
    public const int MAX_FILE_BYTES = 2_000_000;

    /** storage.ts — 1 GB sanity ceiling across every attachment. */
    public const int STORAGE_CEILING_BYTES = 1_000_000_000;

    /** Where task attachments live on the local disk. */
    public const string FILE_DIR = 'task-files';

    /* ------------------------------ scoping ------------------------------- */

    /**
     * GET /api/projects: a manager (`all_data` + PROJECTS) reads every project;
     * anyone else reads only the projects holding a task assigned to them —
     * and an employee-less account reads none.
     *
     * @return Builder<Project>
     */
    public static function scopedProjects(Access $actor): Builder
    {
        return Project::query()->toEmployeeVia($actor, 'tasks', 'assignee_id', ModuleCode::PROJECTS);
    }

    /**
     * GET /api/tasks: the widening module is PROJECTS ALONE — a TASKS holder
     * with `all_data` still sees only their own queue (guard transcription in
     * ScopesToActor's docblock).
     *
     * @return Builder<ProjectTask>
     */
    public static function scopedTasks(Access $actor): Builder
    {
        return ProjectTask::query()->toEmployee($actor, 'assignee_id', ModuleCode::PROJECTS);
    }

    /** The write gate of every project/task mutation: requireAllDataModule("PROJECTS"). */
    public static function managesProjects(Access $actor): bool
    {
        return $actor->mayReadAllData(ModuleCode::PROJECTS);
    }

    /** respond.ts `forbidden()` — the sentence every denied action toasts. */
    public static function forbiddenMessage(): string
    {
        return 'You do not have access to that.';
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
        $projects = self::scopedProjects($actor)->orderBy('code')->get();
        $tasks = self::scopedTasks($actor)->orderBy('code')->get();

        $tasksByProject = [];
        foreach ($tasks as $task) {
            $tasksByProject[$task->project_id][] = $task;
        }

        return [
            'projects' => $projects,
            'tasks' => $tasks,
            'tasksByProject' => $tasksByProject,
            'sites' => WorkSite::query()->orderBy('name')->get(),
            'accounts' => User::query()->orderBy('name')->get(),
        ];
    }

    /**
     * The filter + sort pipeline of projects-page.tsx:83-126, verbatim.
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

            if ($filters['queue'] === 'REVIEW' && ! self::anyTask($projectTasks, static fn (ProjectTask $t): bool => TaskPolicy::isAwaitingReview($t->status))) {
                continue;
            }
            if ($filters['queue'] === 'OVERDUE' && ! self::anyTask($projectTasks, static fn (ProjectTask $t): bool => TaskPolicy::isOverdue(self::taskShape($t), $today))) {
                continue;
            }
            if ($filters['queue'] === 'DUE_SOON'
                && ProjectPolicy::health(self::projectShape($project), self::taskShapes($projectTasks), $today) !== 'DUE_SOON') {
                continue;
            }
            if ($filters['queue'] === 'UNASSIGNED' && ! self::anyTask($projectTasks, static fn (ProjectTask $t): bool => $t->assignee_id === null)) {
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
     * The filter + sort pipeline of project-detail.tsx:175-223, verbatim.
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
            // never equal an id, so it matches only tasks with no assignee —
            // exactly how the source's `t.assignee_id !== v` behaved.
            if ($filters['assignee'] !== 'ALL') {
                $wanted = $filters['assignee'] === 'UNASSIGNED' ? null : $filters['assignee'];
                if ($task->assignee_id !== $wanted) {
                    continue;
                }
            }
            if ($filters['priority'] !== 'ALL' && $task->priority !== $filters['priority']) {
                continue;
            }
            if ($filters['queue'] === 'REVIEW' && $task->status !== 'SUBMITTED') {
                continue;
            }
            if ($filters['queue'] === 'OVERDUE' && ! TaskPolicy::isOverdue(self::taskShape($task), $today)) {
                continue;
            }
            if ($filters['queue'] === 'DUE_SOON' && ! self::isTaskDueSoon($task, $today)) {
                continue;
            }
            if ($filters['queue'] === 'UNASSIGNED' && $task->assignee_id !== null) {
                continue;
            }
            if ($needle !== '' && ! str_contains(mb_strtolower($task->code.' '.$task->title), $needle)) {
                continue;
            }

            $filtered[] = $task;
        }

        $employeeLastName = static function (?string $id) use ($employees): ?string {
            if ($id === null) {
                return null;
            }

            return $employees->firstWhere('id', $id)?->last_name;
        };

        usort($filtered, static function (ProjectTask $a, ProjectTask $b) use ($sortKey, $employeeLastName): int {
            switch ($sortKey) {
                case 'stack':
                    $rank = TaskPolicy::stackRank($a->status) - TaskPolicy::stackRank($b->status);
                    if ($rank !== 0) {
                        return $rank;
                    }
                    $due = self::compareText(self::dateKey($a->due_date), self::dateKey($b->due_date));

                    return $due !== 0 ? $due : self::compareText($a->code, $b->code);
                case 'task':
                    return self::compareText($a->title, $b->title);
                case 'assignee':
                    return self::compareText($employeeLastName($a->assignee_id), $employeeLastName($b->assignee_id));
                case 'priority':
                    return TaskPolicy::priorityRank($a->priority) - TaskPolicy::priorityRank($b->priority);
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
        return ['status' => $task->status, 'due_date' => self::dateKey($task->due_date)];
    }

    /**
     * @param  iterable<array-key, ProjectTask>  $tasks
     * @return list<array{status: string, due_date: string|null}>
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
    public static function projectShape(Project $project): array
    {
        return [
            'status' => $project->status,
            'due_date' => self::dateKey($project->due_date),
            'manager_id' => $project->manager_id === null ? null : (string) $project->manager_id,
            'start_date' => self::dateKey($project->start_date) ?? '',
        ];
    }

    /* ------------------------------ formatting ----------------------------- */

    /** hris.ts `todayKey()` — today's Manila calendar day. */
    public static function todayKey(): string
    {
        return ManilaClock::dayKey(CarbonImmutable::now());
    }

    /** hris.ts `fmtDate` — "Aug 05, 2026". Date columns carry no zone to shift. */
    public static function fmtDate(CarbonImmutable|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $day = $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse($value);

        return $day->format('M d, Y');
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
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonImmutable
            ? $value->format('Y-m-d')
            : substr($value, 0, 10);
    }

    /** hris.ts `employeeName()`. */
    public static function employeeName(?EmployeeProfile $employee): string
    {
        return EmployeeName::display($employee);
    }

    /** hris.ts `initials()` as the avatar fallbacks used it. */
    public static function initials(EmployeeProfile $employee): string
    {
        return EmployeeName::initials($employee);
    }

    /** attachments.ts `humanSize`. */
    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1_000_000
            ? number_format($bytes / 1_000_000, 1).' MB'
            : max(1, (int) round($bytes / 1000)).' KB';
    }

    /**
     * hris.ts `nextCode()` — the next `PRJ-001`-style code past the highest in
     * use. Deleting a row can never hand a code back out.
     *
     * @param  iterable<array-key, string|null>  $codes
     */
    public static function nextCode(string $prefix, iterable $codes, int $width = 3): string
    {
        $highest = 0;
        $pattern = '/^'.preg_quote($prefix, '/').'-(\d+)$/i';

        foreach ($codes as $code) {
            if ($code !== null && preg_match($pattern, trim($code), $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return $prefix.'-'.str_pad((string) ($highest + 1), $width, '0', STR_PAD_LEFT);
    }

    /**
     * useSort.ts `compareText` — locale compare, blanks last whichever way the
     * comparator itself is asked (screens then reverse the array for desc).
     */
    public static function compareText(?string $a, ?string $b): int
    {
        $left = trim($a ?? '');
        $right = trim($b ?? '');

        if ($left === '' && $right === '') {
            return 0;
        }
        if ($left === '') {
            return 1;
        }
        if ($right === '') {
            return -1;
        }

        return strcasecmp($left, $right) <=> 0;
    }

    /* ------------------------------ attachments ----------------------------- */

    /**
     * attachments.ts `attachmentRejection()`, API-mode branch: a per-file cap
     * with no client-side pool (MAX_TOTAL_BYTES is null there).
     */
    public static function attachmentRejection(string $name, int $size): ?string
    {
        if ($size > self::MAX_FILE_BYTES) {
            return sprintf(
                '%s is %s — the limit is %s per file.',
                $name,
                self::humanSize($size),
                self::humanSize(self::MAX_FILE_BYTES),
            );
        }

        return null;
    }

    /**
     * storage.ts `assertStorageCeiling()` — returns the 507 sentence instead of
     * throwing, so a Livewire action can toast it.
     */
    public static function storageCeilingError(int $incomingBytes): ?string
    {
        $used = (int) EmployeeDocument::query()->sum('size')
            + (int) ProjectTaskFile::query()->sum('size');

        if ($used + $incomingBytes > self::STORAGE_CEILING_BYTES) {
            return 'The file store is full. Delete old attachments before uploading more.';
        }

        return null;
    }

    /* ------------------------------ notifications --------------------------- */

    /**
     * notifications.ts `notify()` — one row for one account; nothing written
     * when the addressee is unknown. Fail-soft like the source's try/catch:
     * the action that raised the notice has already happened.
     */
    public static function notifyAccount(
        int|string|null $userId,
        string $kind,
        string $title,
        ?string $body = null,
        ?string $href = null,
    ): void {
        if ($userId === null || $userId === '') {
            return;
        }

        try {
            $notification = new Notification;
            $notification->fill([
                'kind' => $kind,
                'title' => mb_substr($title, 0, 200),
                'body' => $body === null ? null : mb_substr($body, 0, 2000),
                'href' => $href === null ? null : mb_substr($href, 0, 191),
                'dedupe_key' => null,
            ]);
            $notification->setAttribute('user_id', (int) $userId);
            $notification->save();
        } catch (Throwable $e) {
            Log::error('[projects] notification write failed', ['kind' => $kind, 'error' => $e->getMessage()]);
        }
    }

    /**
     * notifications.ts `notifyEmployee()` — resolve the employee's account
     * first; an employee with no account is silently not notified.
     *
     * ACTIVE accounts only, matching {@see Notifier::employee()}.
     * Without that filter this took the lowest id, so an employee whose older
     * account had been disabled — a rehire, or a login rotated after a
     * credential incident — had their task notifications delivered to the dead
     * account and never saw them. `users_active_employee_unq` now leaves at
     * most one active account per employee, so the ordering is a tiebreak that
     * no longer has a tie to break.
     */
    public static function notifyEmployee(
        ?string $employeeId,
        string $kind,
        string $title,
        ?string $body = null,
        ?string $href = null,
    ): void {
        if ($employeeId === null || $employeeId === '') {
            return;
        }

        try {
            $account = User::query()
                ->where('employee_id', $employeeId)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
            self::notifyAccount($account?->getKey(), $kind, $title, $body, $href);
        } catch (Throwable $e) {
            Log::error('[projects] notification lookup failed', ['error' => $e->getMessage()]);
        }
    }

    /* --------------------------------- CSV ---------------------------------- */

    /**
     * export.ts `toCsv()` — RFC 4180 with the formula-injection guard, CRLF
     * line endings and the UTF-8 BOM `downloadCsv` prepended.
     *
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function toCsv(array $headers, array $rows): string
    {
        $cell = static function (string|int|float|null $v): string {
            $text = (string) ($v ?? '');
            if (is_string($v) && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
                $text = "'".$text;
            }

            return '"'.str_replace('"', '""', $text).'"';
        };

        $lines = [];
        foreach ([$headers, ...$rows] as $row) {
            $lines[] = implode(',', array_map($cell, $row));
        }

        return "\u{FEFF}".implode("\r\n", $lines);
    }

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
