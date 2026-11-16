<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\ProjectMember;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\Workforce\Contracts\AssignmentEmployees;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Contracts\Views\ProjectMemberView;
use App\Modules\WorkManagement\Contracts\Views\WorkMemberView;
use App\Platform\ReadModels\QueryPage;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ProjectPeople
{
    /** @return Collection<int, WorkMemberView> */
    public function roster(Access $actor, string $projectId, string $today): Collection
    {
        if (! $actor->hasModule(ModuleCode::PROJECTS)) {
            return collect();
        }
        $project = app(WorkRecords::class)->project($actor, $projectId);
        abort_if($project === null, 403);
        $client = $project->client_id === null ? null : app(ProjectClients::class)->client($actor, $project->client_id);
        $members = ProjectMember::query()->where('project_id', $projectId)->get()->keyBy('user_id');
        $labels = app(AccountNames::class)->list($actor)->keyBy('id');
        $eligible = $project->client_id === null ? app(ProjectAccounts::class)->participants($actor) : app(ClientPeople::class)->accounts($actor, $project->client_id);
        $qualified = app(ModuleAccounts::class)->filter($actor, ModuleCode::PROJECTS, array_values(array_unique([...$members->pluck('user_id')->all(), ...array_filter([$project->manager_id, $client?->owner_id])])));
        $rows = [];
        foreach ($members as $member) {
            $label = $labels->get($member->user_id);
            $active = $label->is_active ?? false;
            $allowed = $active && $eligible->contains('id', $member->user_id);
            $access = in_array($member->role, ['EDITOR', 'VIEWER'], true) && ! in_array($member->user_id, $qualified, true) ? 'INACTIVE' : $member->role;
            $rows[$member->user_id] = ['id' => $member->user_id, 'user_id' => $member->user_id, 'employee_id' => $label?->employee_id,
                'name' => $label->name ?? AccountLabel::unlinked($member->user_id), 'access' => $allowed ? $access : 'INACTIVE',
                'position' => $member->position, 'active' => $active, 'explicit' => true, 'open_tasks' => 0];
        }
        foreach (array_filter(['LEAD' => $project->manager_id, 'OWNER' => $client?->owner_id]) as $access => $id) {
            $label = $labels->get($id);
            $rows[$id] = [...($rows[$id] ?? []), 'id' => $id, 'user_id' => $id, 'employee_id' => $label?->employee_id,
                'name' => $label->name ?? AccountLabel::unlinked($id), 'access' => $eligible->contains('id', $id) && in_array($id, $qualified, true) ? $access : 'INACTIVE', 'position' => $rows[$id]['position'] ?? null,
                'active' => $label->is_active ?? false, 'explicit' => $members->has($id), 'open_tasks' => 0];
        }
        foreach (app(WorkSummary::class)->workload($actor, $projectId, $today) as $entry) {
            $employee = $entry['employee'];
            $existing = false;
            foreach ($rows as $id => $row) {
                if ($row['employee_id'] === $employee->id) {
                    $rows[$id] = [...$row, 'open_tasks' => $entry['active'], 'access' => $row['access'] === 'INACTIVE' && $eligible->contains('id', $row['user_id']) ? 'ASSIGNED' : $row['access']];
                    $existing = true;
                }
            }
            if (! $existing) {
                $account = $eligible->first(static fn (AccountLabel $label): bool => $label->employee_id === $employee->id);
                $hasAccess = $account !== null;
                $account ??= $labels->first(static fn (AccountLabel $label): bool => $label->employee_id === $employee->id);
                $id = $account->id ?? $employee->id;
                $rows[$id] = ['id' => $id, 'user_id' => $account?->id, 'employee_id' => $employee->id, 'name' => EmployeeName::display($employee),
                    'access' => $hasAccess ? 'ASSIGNED' : 'INACTIVE', 'position' => null, 'active' => $account->is_active ?? false, 'explicit' => false, 'open_tasks' => $entry['active']];
            }
        }
        $accessLabels = ['OWNER' => 'Client owner', 'LEAD' => 'Project lead', 'EDITOR' => 'All tasks · edit', 'VIEWER' => 'All tasks · view',
            'PARTICIPANT' => 'Assigned tasks', 'ASSIGNED' => 'Assigned tasks', 'INACTIVE' => 'Access inactive'];

        return collect(array_values($rows))->map(static fn (array $row): WorkMemberView => new WorkMemberView([...$row, 'access_label' => $accessLabels[$row['access']]]));
    }

    /** @return Collection<int, AccountLabel> */
    public function accounts(Access $actor, string $projectId): Collection
    {
        $project = app(WorkRecords::class)->project($actor, $projectId);
        abort_unless(WorkRules::editsWork($actor, $project), 403);

        return $project->client_id === null
            ? app(ProjectAccounts::class)->participants($actor)
            : app(ClientPeople::class)->accounts($actor, $project->client_id);
    }

    /** @return Collection<int, AccountLabel> */
    public function candidates(Access $actor, string $projectId): Collection
    {
        $accounts = $this->accounts($actor, $projectId);
        $existing = ProjectMember::query()->where('project_id', $projectId)->pluck('user_id');

        return $accounts->whereNotIn('id', $existing->all())->values();
    }

    public function member(Access $actor, string $projectId, int $userId): ?ProjectMemberView
    {
        abort_if(app(WorkRecords::class)->project($actor, $projectId) === null, 403);
        $member = ProjectMember::query()->where('project_id', $projectId)->where('user_id', $userId)->first();

        return $member === null ? null : Snapshot::of($member, ProjectMemberView::class);
    }

    /** @return LengthAwarePaginator<int, ProjectMemberView> */
    public function members(Access $actor, string $projectId, int $page = 1): LengthAwarePaginator
    {
        abort_if(app(WorkRecords::class)->project($actor, $projectId) === null, 403);
        $members = QueryPage::get(ProjectMember::query()->where('project_id', $projectId)->orderBy('created_at')->orderBy('id'), $page, 20, ProjectMemberView::class, 'teamPage');
        $accounts = app(AccountNames::class)->list($actor, array_values($members->getCollection()->pluck('user_id')->all()))->keyBy('id');

        return $members->through(static fn (ProjectMemberView $member): ProjectMemberView => new ProjectMemberView([...$member->toArray(),
            'name' => $accounts->get($member->user_id)->name ?? AccountLabel::unlinked($member->user_id),
            'employee_id' => $accounts->get($member->user_id)?->employee_id,
        ]));
    }

    /** @param list<string>|null $ids
     * @return Collection<int, DirectoryEmployee>
     */
    public function employees(Access $actor, string $projectId, ?array $ids = null, string $search = '', ?string $taskId = null): Collection
    {
        $allowed = $this->accounts($actor, $projectId)->pluck('employee_id')->filter()->unique()->values()->all();
        if ($taskId !== null) {
            $task = app(WorkRecords::class)->task($actor, $taskId, $projectId);
            abort_if($task === null, 403);
            $allowed = array_values(array_unique([...$allowed, ...$task->assigneeIds()]));
        }
        $chosen = $ids === null ? $allowed : array_values(array_filter($allowed, static fn (string $id): bool => in_array(strtolower($id), array_map(strtolower(...), $ids), true)));
        $terms = preg_split('/\s+/', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return app(AssignmentEmployees::class)->identities(array_values($chosen))->filter(static function (DirectoryEmployee $employee) use ($terms): bool {
            $text = mb_strtolower(implode(' ', [EmployeeName::display($employee), $employee->employee_code, $employee->employee_id_no ?? '']));
            foreach ($terms as $term) {
                if (! str_contains($text, $term)) {
                    return false;
                }
            }

            return true;
        })->sortBy([['last_name', 'asc'], ['first_name', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /** @param list<string> $ids
     * @return list<string>|string
     */
    public function resolveAssignees(Access $actor, string $projectId, array $ids): array|string
    {
        $project = app(WorkRecords::class)->project($actor, $projectId);
        abort_unless(WorkRules::editsWork($actor, $project), 403);
        if (WorkRules::managesProject($actor, $project)) {
            return $project->client_id === null
                ? app(AssignmentEmployees::class)->resolveActive($actor, $ids)
                : app(ClientPeople::class)->resolveAssignees($actor, $project->client_id, $ids);
        }
        $eligible = $this->employees($actor, $projectId, $ids)->where('is_active', true)->pluck('id')->values()->all();

        return count($eligible) === count(array_unique(array_map(strtolower(...), $ids)))
            ? array_values($eligible) : 'Choose active employees with work and client access for this task.';
    }
}
