<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Projects\ProjectPolicy;
use App\Models\Project;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Domain\Projects\ManualProjectStatus;
use App\Modules\WorkManagement\Domain\Tasks\TaskSchedule;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The edit-project dialog, for a Projects manager: every field, the Task
 * Leader included. (A project's Task Leader who is not a manager plans only
 * its start date, through ScheduleProject.)
 *
 * The status field follows the same hand-set rule as the header select
 * (ManualProjectStatus, DELIBERATE DIVERGENCE), so the two can never disagree
 * about a reopen. The window check covers each task's planned start as well
 * as its due date. Both are decided on the project's tasks read right after
 * the project row is locked (P, the transaction's first statement, WorkRules'
 * lock protocol), so a task date or reopen committed concurrently is either
 * seen or waits for this save.
 *
 * The status and the start date arrive as null when the dialog left them
 * untouched (ProjectDraft): the leader may have moved either since the dialog
 * opened, and an untouched field keeps the row's value rather than reverting
 * theirs.
 */
final class UpdateProject
{
    public function handle(Access $actor, string $projectId, ProjectDraft $input): ?string
    {
        if (! WorkRules::managesProjects($actor)) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        $project = WorkRules::project($actor, $projectId);
        if ($project === null) {
            return null;
        }
        $name = trim($input->name);
        // Null = untouched (ProjectDraft): the start date the row holds when
        // the save lands. Only a posted '' is a missing start date.
        if ($name === '' || $input->startDate === '') {
            throw new \RuntimeException('A project needs a name and a start date.');
        }
        if ($input->startDate !== null && ! TaskSchedule::isDateKey($input->startDate)) {
            throw new \RuntimeException('start_date: must be a date');
        }
        if (mb_strlen($name) > 200) {
            throw new \RuntimeException('name: must be at most 200 characters');
        }
        $description = trim($input->description);
        if (mb_strlen($description) > 4000) {
            throw new \RuntimeException('description: must be at most 4000 characters');
        }
        $dueDate = $input->dueDate === '' ? null : $input->dueDate;
        $managerId = $input->managerId === 'NONE' ? null : $input->managerId;
        $siteId = $input->siteId === 'NONE' ? null : $input->siteId;
        if ($siteId !== null && ! app(OrganizationReferences::class)->siteExists($siteId)) {
            throw new \RuntimeException('Choose an existing work site for the project.');
        }
        // The site the project already names stays valid once it is retired.
        if ($siteId !== null && ! app(OrganizationReferences::class)->siteAssignable($siteId, $project->site_id)) {
            throw new \RuntimeException('That work site is inactive, so it cannot be chosen for a new assignment. Choose an active site, or make it active again in Site Settings.');
        }
        // A NEW leader must be able to open Project Management
        // (ProjectAccounts::canLead()); the leader already named only has to
        // be active, so a project led by an account without that access
        // still saves.
        if ($managerId !== null) {
            $sameLeader = $project->manager_id !== null && $managerId === (string) $project->manager_id;
            $valid = $sameLeader
                ? app(ProjectAccounts::class)->active($actor, $managerId)
                : app(ProjectAccounts::class)->canLead($actor, $managerId);
            if (! $valid) {
                throw new \RuntimeException($sameLeader ? 'Choose an active account as task leader.' : WorkRules::leaderRefusal());
            }
        }
        try {
            /** @var array{0: Project, 1: string, 2: list<string>}|null $saved */
            $saved = DB::transaction(static function () use ($project, $input, $name, $description, $siteId, $dueDate, $managerId): ?array {
                // P first; the tasks are read plainly after it (WorkRules'
                // protocol), so a task date or reopen committed by a writer
                // holding P is either in this snapshot or waits for this save.
                $locked = WorkRules::lockedProject($project->id);
                if ($locked === null) {
                    return null;
                }
                $from = $locked->status;
                $status = $input->status ?? $from;
                $startDate = $input->startDate ?? (WorkRules::dateKey($locked->start_date) ?? '');
                $dateError = ProjectPolicy::dateError($startDate, $dueDate);
                if ($dateError !== null) {
                    throw new \RuntimeException($dateError);
                }
                $tasks = $locked->tasks()->get()->all();
                // Every task, closed ones included (projects/[id]/route.ts:53-61),
                // must keep its planned start and its due date inside the window.
                foreach ($tasks as $task) {
                    if (! TaskSchedule::fitsWindow(WorkRules::dateKey($task->start_date), WorkRules::dateKey($task->due_date), $startDate, $dueDate)) {
                        throw new \RuntimeException('The project timeline would leave '.WorkRules::taskLabel($task).' outside it.');
                    }
                }
                $statusError = ManualProjectStatus::error(['status' => $from, 'manager_id' => $managerId], WorkRules::taskShapes($tasks), $status);
                if ($statusError !== null) {
                    throw new \RuntimeException($statusError);
                }
                $locked->fill(['name' => $name, 'description' => $description === '' ? null : $description, 'site_id' => $siteId, 'status' => $status, 'start_date' => $startDate, 'due_date' => $dueDate]);
                $locked->setAttribute('manager_id', $managerId === null ? null : (int) $managerId);
                $changed = array_keys($locked->getDirty());
                $locked->save();

                return [$locked, $from, $changed];
            });
        } catch (QueryException) {
            throw new \RuntimeException('A record with those details already exists.');
        }
        if ($saved === null) {
            return null;
        }
        [$saved, $from, $changed] = $saved;
        $to = $saved->status;
        AuditService::record($actor, ProjectPolicy::auditAction($from, $to), 'projects', $saved->id, $to !== $from ? sprintf('%s — %s moved from %s to %s', $saved->code, $saved->name, ProjectPolicy::statusLabel($from), ProjectPolicy::statusLabel($to)) : sprintf('%s — %s updated (%s)', $saved->code, $saved->name, implode(', ', $changed)));

        return $saved->code;
    }
}
