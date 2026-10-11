<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Projects\ProjectPolicy;
use App\Models\Project;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\WorkManagement\Application\ProjectDraft;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Extracted workflow; authorization, validation and persistence stay with WorkManagement. */
final class CreateProject
{
    public function handle(Access $actor, ProjectDraft $input): ProjectView
    {
        if (! WorkRules::managesProjects($actor)) {
            throw new \RuntimeException(WorkRules::forbiddenMessage());
        }
        $name = trim($input->name);
        // A new project has no stored value to keep: a missing status is the
        // default one, a missing start date is refused below.
        $status = $input->status ?? 'PLANNING';
        $startDate = $input->startDate ?? '';
        if ($name === '' || $startDate === '') {
            throw new \RuntimeException('A project needs a name and a start date.');
        }
        if (mb_strlen($name) > 200) {
            throw new \RuntimeException('name: must be at most 200 characters');
        }
        $description = trim($input->description);
        if (mb_strlen($description) > 4000) {
            throw new \RuntimeException('description: must be at most 4000 characters');
        }
        $dueDate = $input->dueDate === '' ? null : $input->dueDate;
        $dateError = ProjectPolicy::dateError($startDate, $dueDate);
        if ($dateError !== null) {
            throw new \RuntimeException($dateError);
        }
        $managerId = $input->managerId === 'NONE' ? null : $input->managerId;
        $statusError = ProjectPolicy::statusError(['status' => 'PLANNING', 'manager_id' => $managerId], [], $status);
        if ($statusError !== null) {
            throw new \RuntimeException($statusError);
        }
        $siteId = $input->siteId === 'NONE' ? null : $input->siteId;
        if ($siteId !== null && ! app(OrganizationReferences::class)->siteExists($siteId)) {
            throw new \RuntimeException('Choose an existing work site for the project.');
        }
        if ($siteId !== null && ! app(OrganizationReferences::class)->siteAssignable($siteId)) {
            throw new \RuntimeException('That work site is inactive, so it cannot be chosen for a new assignment. Choose an active site, or make it active again in Site Settings.');
        }
        // A leader acts from Project Management, so only an account whose
        // role can open it may be named (ProjectAccounts::canLead()).
        if ($managerId !== null) {
            if (! app(ProjectAccounts::class)->canLead($actor, $managerId)) {
                throw new \RuntimeException(WorkRules::leaderRefusal());
            }
        }
        try {
            /** @var Project $created */
            $created = DB::transaction(function () use ($name, $description, $siteId, $managerId, $dueDate, $status, $startDate): Project {
                // The port of the reference's advisory lock around the code
                // mint (POST /api/projects) — FOR UPDATE serialises two
                // concurrent creators on the rows being counted past.
                $codes = DB::table('projects')->lockForUpdate()->pluck('code')->all();
                $project = new Project;
                $project->fill(['code' => WorkRules::nextCode('PRJ', $codes), 'name' => $name, 'description' => $description === '' ? null : $description, 'site_id' => $siteId, 'status' => $status, 'start_date' => $startDate, 'due_date' => $dueDate]);
                // Not an actor column, but not mass-assignable either — set the
                // validated value directly, as the compact() insert did.
                $project->setAttribute('manager_id', $managerId === null ? null : (int) $managerId);
                $project->save();

                return $project;
            });
        } catch (QueryException) {
            throw new \RuntimeException('A record with those details already exists.');
        }
        AuditService::record($actor, AuditEvent::PROJECT_CREATED, 'projects', $created->id, "Project {$created->code} {$name} opened");

        return Snapshot::of($created, ProjectView::class);
    }
}
