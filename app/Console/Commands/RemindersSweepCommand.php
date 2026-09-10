<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\Notifier;
use App\Domain\Reminders\ReminderSweep;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The server-side reminder sweep (GAP-07 in the port plan).
 *
 * The reference ran this in the browser (useReminders.ts) on page load, with a
 * documented bug: the hook gated the whole sweep on /api/tasks AND
 * /api/projects both succeeding, so the HR-aimed rules (LEAVE_PENDING,
 * DOCUMENT_EXPIRING, PROBATION_ENDING) silently never fired for an HR Officer
 * without PROJECTS/TASKS. Running the sweep from cron removes both the bug and
 * the browser dependency: reminders fire overnight without anyone opening the
 * app. ReminderSweep::build() itself gates each dataset on the account's own
 * modules, so per-user visibility is preserved exactly.
 *
 * Idempotent by construction: build() emits deterministic dedupe keys,
 * fresh() pre-filters against keys already recorded, and the
 * (user_id, dedupe_key) unique index makes a concurrent second sweep a no-op.
 * Safe on a host whose cron minimum is coarser or finer than the schedule.
 */
final class RemindersSweepCommand extends Command
{
    protected $signature = 'reminders:sweep';

    protected $description = 'Build and record reminder notifications for every active account';

    public function handle(): int
    {
        $today = ManilaClock::dayKey(CarbonImmutable::now());

        // The datasets are org-wide; build() narrows per user by module and
        // employee id. One load, N sweeps.
        // 'assignees' is eager-loaded so the id list per task is one query for
        // the whole set, not one per task.
        $tasks = array_values(ProjectTask::query()->with('assignees')->get()->map(fn ($t) => [
            'id' => $t->id,
            'code' => $t->code,
            'title' => $t->title,
            'status' => $t->status,
            'project_id' => $t->project_id,
            'assignee_ids' => $t->assigneeIds(),
            'submitted_by' => $t->submitted_by,
            'assigned_by' => $t->assigned_by === null ? null : (string) $t->assigned_by,
            'due_date' => $t->due_date?->format('Y-m-d'),
            // The CarbonImmutable itself, never a bare 'Y-m-d H:i:s': that
            // string is UTC with no offset and ReminderSweep::dayKeyOf()
            // would read it as Manila wall clock — eight hours out, which
            // moves a late-afternoon submission onto the previous day. The
            // TASK_REVIEW_PENDING dedupe key is unaffected: ReminderSweep::stamp()
            // still mints the stamp the bare string used to produce, so a
            // submission chased before this change is not chased again.
            'submitted_at' => $t->submitted_at,
        ])->values()->all());

        $projects = array_values(Project::query()->get()->map(fn ($p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'status' => $p->status,
            'manager_id' => $p->manager_id === null ? null : (string) $p->manager_id,
            'due_date' => $p->due_date?->format('Y-m-d'),
        ])->values()->all());

        $employees = array_values(EmployeeProfile::query()->get()->map(fn ($e) => [
            'id' => $e->id,
            'first_name' => $e->first_name,
            'last_name' => $e->last_name,
            'suffix' => $e->suffix,
            'is_active' => (bool) $e->is_active,
            'employment_status' => $e->employment_status,
            'date_hired' => $e->date_hired->format('Y-m-d'),
            'date_regular' => $e->date_regular?->format('Y-m-d'),
        ])->values()->all());

        $documents = array_values(EmployeeDocument::query()->get()->map(fn ($doc) => [
            'id' => $doc->id,
            'employee_id' => $doc->employee_id,
            'name' => $doc->name,
            'category' => $doc->category,
            'expires_on' => $doc->expires_on?->format('Y-m-d'),
        ])->values()->all());

        $leaves = array_values(LeaveTravelRequest::query()->get()->map(fn ($l) => [
            'id' => $l->id,
            'code' => $l->code,
            'status' => $l->status,
            'employee_id' => $l->employee_id,
            'created_at' => $l->created_at, // instant, not an offset-less string (see tasks)
        ])->values()->all());

        $swept = 0;
        $written = 0;

        User::query()
            ->where('is_active', true)
            ->with('role.grants')
            ->each(function (User $user) use ($today, $tasks, $projects, $employees, $documents, $leaves, &$swept, &$written) {
                $modules = array_values($user->role?->grants->pluck('module')->map(fn ($m) => (string) $m)->all() ?? []);

                if ($modules === []) {
                    return;
                }

                $reminders = ReminderSweep::build(
                    today: $today,
                    userId: (string) $user->id,
                    modules: $modules,
                    employeeId: $user->employee_id,
                    tasks: $tasks,
                    projects: $projects,
                    employees: $employees,
                    documents: $documents,
                    leaves: $leaves,
                );

                $seen = array_values(Notification::query()
                    ->where('user_id', $user->id)
                    ->whereNotNull('dedupe_key')
                    ->pluck('dedupe_key')
                    ->map(fn ($k) => (string) $k)
                    ->all());

                $rows = ReminderSweep::fresh($reminders, $seen);

                if ($rows !== []) {
                    Notifier::reminders($user->id, $rows);
                    $written += count($rows);
                }
                $swept++;
            });

        $this->info("Swept {$swept} accounts, wrote {$written} reminders.");

        return self::SUCCESS;
    }
}
