<?php

declare(strict_types=1);

use App\Domain\Attendance\CalendarDays;
use App\Domain\Projects\TaskPolicy;
use App\Models\EmployeeDocument;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\ProjectTaskGroup;
use App\Models\ProjectTaskSubmission;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ManageRoles;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Timekeeping\Application\Commands\CorrectPunches;
use App\Modules\Timekeeping\Application\Commands\DecideOvertime;
use App\Modules\Timekeeping\Application\Commands\DecidePendingOvertime;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Modules\WorkManagement\Application\Commands\AttachSubmission;
use App\Modules\WorkManagement\Application\Commands\ChangeProjectStatus;
use App\Modules\WorkManagement\Application\Commands\ChangeTaskStatus;
use App\Modules\WorkManagement\Application\Commands\CreateTask;
use App\Modules\WorkManagement\Application\Commands\DeleteProject;
use App\Modules\WorkManagement\Application\Commands\DeleteSubmission;
use App\Modules\WorkManagement\Application\Commands\MoveTaskToGroup;
use App\Modules\WorkManagement\Application\Commands\ReviewTask;
use App\Modules\WorkManagement\Application\Commands\ReviewTaskResponse;
use App\Modules\WorkManagement\Application\Commands\SubmitTask;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskContent;
use App\Modules\WorkManagement\Application\Commands\UpdateTaskDetails;
use App\Modules\WorkManagement\Application\TaskDetails;
use App\Modules\WorkManagement\Application\TaskDraft;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Platform\Files\AttachmentStore;
use App\Platform\Notifications\Notifier;
use App\Support\Access;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\PayrollReviewFixture;

/** Independent connection used only by the guarded disposable MariaDB integration suite. */
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'ems_testing') {
    throw new RuntimeException('Disposable test database required.');
}
/*
 * $argv is defined only under the CLI SAPI with register_argc_argv enabled, so
 * it is read out of $_SERVER and CHECKED rather than trusted. This worker is
 * spawned by the disposable MariaDB integration suite; a missing argument must
 * fail loudly right here, not as a confusing error further down.
 */
$arguments = array_map(strval(...), array_values((array) ($_SERVER['argv'] ?? [])));
if (count($arguments) !== 7) {
    throw new RuntimeException('Expected: operation, userId, employeeId, runId, number, directory.');
}
[, $operation, $userId, $employeeId, $runId, $number, $directory] = $arguments;
auth()->setUser(User::query()->findOrFail($userId));
$app->forgetInstance(Access::class);
$actor = app(Access::class);
config(['filesystems.disks.local.root' => $directory.'/files']);
$taskOperation = str_starts_with($operation, 'task-');
$projectOperation = str_starts_with($operation, 'project-');
DB::connection()->beforeExecuting(static function (string $sql) use ($operation, $taskOperation, $projectOperation): void {
    $lower = strtolower($sql);
    $lockingTable = match (true) {
        $operation === 'grant' => '`roles`',
        $operation === 'correct' => '`attendance_logs`',
        $taskOperation => '`project_tasks`',
        $projectOperation => '`projects`',
        default => '`locks`',
    };
    // A correction queues on the punches it reads FOR UPDATE — the day
    // before, the day and the day after — which the test holds; it takes
    // payroll's membership lock first (a paid day is never corrected), then
    // the employee row, so the second of two corrections queues on the
    // membership lock and is ready at that read.
    // A task writer reaches its row either through L1 (FOR UPDATE) or through
    // a guarded UPDATE: both queue on the row lock the test holds.
    // A project-lock writer is ready at its FIRST locking read of either
    // table: the project row (P), or the task row(s) it locks before P — L1,
    // or the whole-table scan of CreateTask and DeleteProject. A lock it is
    // granted there is followed at once by P, which the test holds.
    if ((str_contains($sql, $lockingTable) && (str_contains($lower, 'for update') || ($taskOperation && str_starts_with($lower, 'update'))))
        || ($operation === 'correct' && str_contains($lower, 'for update') && (str_contains($sql, '`locks`') || str_contains($sql, '`employee_profiles`')))
        || ($projectOperation && str_contains($lower, 'for update') && str_contains($sql, '`project_tasks`'))
        || ($operation === 'reminder' && str_starts_with($lower, 'insert') && str_contains($sql, '`notifications`'))) {
        echo "ready\n";
        flush();
    }
});
try {
    $outcome = null;
    if ($operation === 'reminder') {
        $outcome = Notifier::reminders($userId, [['dedupeKey' => 'TASK_OVERDUE:synthetic:2026-09-01', 'kind' => 'TASK_OVERDUE', 'title' => 'Synthetic', 'body' => null, 'href' => '/my-tasks']]);
    } elseif ($taskOperation) {
        // The task id travels in runId; the member an operation is about —
        // the one a crew edit adds or removes, whose response a reviewer
        // decides, whose file is deleted — in employeeId. Everything this
        // worker reads here, it reads BEFORE the race, the way a screen
        // rendered it: the crew, the response row id and revision, the file.
        $task = ProjectTask::query()->findOrFail($runId);
        $crewEdit = static function (array $crew, ?string $mode = null) use ($actor, $task): void {
            app(UpdateTaskDetails::class)->handle($actor, $task->project_id, $task->id,
                new TaskDetails($task->priority, WorkRules::dateKey($task->due_date) ?? '', $crew, $mode));
        };
        if ($operation === 'task-complete') {
            app(ReviewTask::class)->handle($actor, $task->project_id, $task->id, 'COMPLETED', '');
        } elseif ($operation === 'task-add-assignee') {
            $crewEdit([...$task->assigneeIds(), $employeeId]);
        } elseif ($operation === 'task-remove-assignee') {
            $crewEdit(array_values(array_diff($task->assigneeIds(), [$employeeId])));
        } elseif ($operation === 'task-switch-to-group') {
            $crewEdit($task->assigneeIds(), TaskPolicy::MODE_GROUP);
        } elseif ($operation === 'task-switch-to-individual') {
            $crewEdit($task->assigneeIds(), TaskPolicy::MODE_INDIVIDUAL);
        } elseif ($operation === 'task-approve-response' || $operation === 'task-return-response') {
            $response = ProjectTaskSubmission::query()->where('task_id', $task->id)->where('employee_id', $employeeId)->firstOrFail();
            app(ReviewTaskResponse::class)->handle($actor, $task->project_id, $task->id, $response->id,
                $operation === 'task-approve-response' ? 'APPROVED' : 'RETURNED', 'Redo the totals.', $response->revision);
        } elseif ($operation === 'task-edit-brief') {
            app(UpdateTaskContent::class)->handle($actor, $task->project_id, $task->id, 'Brief from worker '.$number, 'Updated scope', $task->title, (string) $task->description);
        } elseif ($operation === 'task-move-group') {
            $group = ProjectTaskGroup::query()->where('project_id', $task->project_id)->where('name', 'Race group '.$number)->sole();
            app(MoveTaskToGroup::class)->handle($actor, $task->project_id, $task->id, $group->id, $task->group_id);
        } elseif ($operation === 'task-submit') {
            app(SubmitTask::class)->handle($actor, $task->id, 'Handed in by worker '.$number.'.');
        } elseif ($operation === 'task-attach') {
            app(AttachSubmission::class)->handle($actor, $task->id, new UploadedFile($directory.'/upload.bin', 'evidence-'.$number.'.pdf', 'application/pdf', null, true));
        } elseif ($operation === 'task-delete-file') {
            $file = ProjectTaskFile::query()->where('task_id', $task->id)->where('employee_id', $employeeId)->where('kind', 'SUBMISSION')->firstOrFail();
            app(DeleteSubmission::class)->handle($actor, $file->id);
        } else {
            throw new RuntimeException('Unknown test operation.');
        }
    } elseif ($projectOperation) {
        // The project-lock protocol (WorkRules, P). runId carries the PROJECT
        // for a project write and the TASK for a task write. As above,
        // whatever the operation would have shown on screen — the status it
        // moves from — is read here, BEFORE the race.
        if ($operation === 'project-complete') {
            $project = Project::query()->findOrFail($runId);
            $outcome = app(ChangeProjectStatus::class)->handle($actor, $project->id, 'COMPLETED', $project->status);
        } elseif ($operation === 'project-create-task') {
            $outcome = app(CreateTask::class)->handle($actor, $runId, new TaskDraft('Raced task '.$number, '', 'NORMAL', '', $employeeId === '' ? [] : [$employeeId]))?->code;
        } elseif ($operation === 'project-delete') {
            $outcome = app(DeleteProject::class)->handle($actor, $runId);
        } else {
            $task = ProjectTask::query()->findOrFail($runId);
            if ($operation === 'project-task-reopen') {
                app(ChangeTaskStatus::class)->handle($actor, $task->project_id, $task->id, 'IN_PROGRESS', '', $task->status);
            } elseif ($operation === 'project-task-accept') {
                app(ReviewTask::class)->handle($actor, $task->project_id, $task->id, 'COMPLETED', '');
            } elseif ($operation === 'project-task-move-due' || $operation === 'project-task-plan-start') {
                // A manager moves the due date; a Task Leader plans the start
                // (the one field of theirs). The rest is posted as rendered.
                $moveDue = $operation === 'project-task-move-due';
                app(UpdateTaskDetails::class)->handle($actor, $task->project_id, $task->id, new TaskDetails($task->priority,
                    $moveDue ? '2026-11-30' : (WorkRules::dateKey($task->due_date) ?? ''), $task->assigneeIds(), null, $moveDue ? null : '2026-10-01'));
            } else {
                throw new RuntimeException('Unknown test operation.');
            }
        }
    } elseif ($operation === 'correct') {
        // Worker N corrects night N of the back-to-back pair whose first
        // date travels in runId, entered as the dialog sends it: 10:00 PM,
        // then 6:00 AM ticked Next day. Both nights' rows share the second
        // date — the first night's Time Out, the second night's Time In.
        CorrectPunches::apply($actor, $employeeId, $number === '1' ? $runId : CalendarDays::next($runId),
            ['time_in' => '22:00', 'break_out' => null, 'break_in' => null, 'time_out' => '06:00'],
            ['time_out' => true] + PunchSlots::SAME_DAY, null);
    } else {
        match ($operation) {
            'grant' => app(ManageRoles::class)->toggleGrant($actor, $runId, 'LEAVES', true),
            'membership' => app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'Concurrent '.$number, '2026-09-01', '2026-09-15', [$employeeId], [], [], [])),
            'finalize' => app(FinalizePayroll::class)->handle($actor, $runId, PayrollReviewFixture::token($actor, $runId)),
            // The same overtime decision twice: payroll's membership lock
            // (taken first, PostedPayrollDates) serialises them, so the
            // second finds the row and changes nothing.
            'overtime' => app(DecideOvertime::class)->handle($actor, $employeeId, '2026-09-08', 'APPROVED', '60', notify: false),
            // "Approve all pending" over the cut-off, queued behind a finalize.
            'overtime-bulk' => app(DecidePendingOvertime::class)->handle($actor, $employeeId, '2026-09-01', '2026-09-15', 'APPROVED'),
            'quota' => app(AttachmentStore::class)->store(new UploadedFile($directory.'/upload.bin', 'evidence.pdf', 'application/pdf', null, true), 'employee-documents', 'race-'.$number.'.pdf', static function (string $path) use ($employeeId): string {
                return EmployeeDocument::create(['employee_id' => $employeeId, 'name' => 'Synthetic evidence', 'category' => 'Evidence', 'file_name' => 'evidence.pdf', 'mime' => 'application/pdf', 'size' => 1024, 'path' => $path])->id;
            }, ceiling: 1500),
            default => throw new RuntimeException('Unknown test operation.'),
        };
    }
    echo json_encode(['ok' => true, 'outcome' => $outcome], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'type' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR)."\n";
}
