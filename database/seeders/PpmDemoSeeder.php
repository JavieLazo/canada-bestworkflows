<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\Project;
use App\Models\ProjectAvailability;
use App\Models\ProjectClient;
use App\Models\ProjectClientMember;
use App\Models\ProjectMember;
use App\Models\ProjectPortfolio;
use App\Models\ProjectPortfolioMember;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAssignee;
use App\Models\ProjectTaskEffort;
use App\Models\ProjectTaskGroup;
use App\Models\ProjectTaskSubmission;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\TaskComment;
use App\Models\User;
use App\Modules\WorkManagement\Domain\Projects\CapacityWindow;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Optional local review data; never called by DatabaseSeeder or ProductionSeeder. */
final class PpmDemoSeeder extends PpmDemoRecords
{
    private const PEOPLE = ['Aina Mercado', 'Bea Navarro', 'Carlos Ramos', 'Diana Flores', 'Eli Reyes', 'Faye Torres', 'Gio Castillo', 'Hana Perez', 'Ian Gomez', 'Jana Cruz', 'Kai Rivera', 'Lia Mendoza', 'Mika Bautista', 'Noah Garcia', 'Owen Santos', 'Pia Lim', 'Quinn Tan', 'Ria Dizon', 'Sam Aquino', 'Tala Villanueva', 'Uma Ramos', 'Vince Lee', 'Wren Lopez', 'Xavi Diaz', 'Yara Sy', 'Zia David', 'Andre Co', 'Bella Ong'];

    private const PROJECTS = ['Workspace launch', 'Access system upgrade', 'Service rollout', 'Records digitization', 'Field delivery', 'Learning programme', 'Website refresh', 'Quality review', 'Operations handover', 'Research delivery', 'Partner onboarding', 'Facilities renewal'];

    public function run(?int $ownerId = null): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('PPM demo data is available only in local and testing environments.');
        }
        foreach (['project_portfolios', 'project_members', 'project_task_efforts', 'project_report_submissions', 'project_cash_allocations'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException('Apply the PPM migrations before adding the demo pack. Missing '.$table.'.');
            }
        }
        $existing = ProjectPortfolio::query()->find(self::id('portfolio:0'));
        $owner = $ownerId === null && $existing !== null ? User::query()->find($existing->owner_id) : ($ownerId === null ? null : User::query()->find($ownerId));
        if ($owner === null && $ownerId === null) {
            $owner = User::query()->where('is_active', true)->orderByDesc('last_login_at')->orderBy('id')->get()
                ->first(static fn (User $user): bool => Access::forUser($user)->mayReadAllData(ModuleCode::PROJECTS));
        }
        if ($owner === null || ! Access::forUser($owner)->mayReadAllData(ModuleCode::PROJECTS)) {
            throw new \RuntimeException('Choose an existing active Project Management administrator with --owner. No account permissions were changed.');
        }
        if ($existing !== null && $existing->owner_id !== $owner->id) {
            throw new \RuntimeException('This demo pack already belongs to account '.$existing->owner_id.'. Rerun with that owner; existing ownership is preserved.');
        }
        $added = DB::transaction(function () use ($owner): int {
            User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $role = self::id('role');
            $realPeople = User::query()->where('is_active', true)->where('role_id', '!=', $role)->whereNotNull('employee_id')
                ->whereHas('role.grants', static fn ($query) => $query->where('module', 'TASKS'))->orderBy('id')->limit(4)->get();
            $people = $this->people();
            $number = 0;
            $added = 0;
            foreach (range(0, 23) as $p) {
                $portfolioId = self::id('portfolio:'.$p);
                $labels = ['Operations', 'Client delivery', 'Digital services', 'Community programmes', 'Research', 'Internal systems'];
                $label = $labels[$p] ?? ($p === 23 ? 'Empty sandbox' : 'Regional delivery '.($p + 1));
                $briefs = ['Facilities, access and service readiness.', 'Client launches and ongoing delivery.', 'Websites, tools and records improvement.', 'Field programmes and partner outcomes.', 'Studies, evidence and publication.', 'Team systems and operational improvement.'];
                if ($this->keep(ProjectPortfolio::class, 'portfolio:'.$p, ['owner_id' => $owner->id, 'name' => 'Demo: '.$label,
                    'description' => $briefs[$p] ?? ($p === 23 ? 'An empty workspace for trying client creation.' : 'Regional delivery and partner coordination.'), 'archived_at' => $p === 22 ? now() : null])) {
                    foreach ($people as $person) {
                        $this->keep(ProjectPortfolioMember::class, $portfolioId.':'.$person->id, ['portfolio_id' => $portfolioId, 'user_id' => $person->id]);
                    }
                }
                if ($p === 23) {
                    continue;
                }
                foreach (range(0, $p < 6 ? 2 : 0) as $c) {
                    $clientId = $this->client($owner, $people->concat($realPeople), $portfolioId, $p.':'.$c, 'Demo: '.$label.' '.['Partners', 'Services', 'Foundation'][$c]);
                    foreach (range(0, $p < 6 ? 2 : 0) as $j) {
                        $added += $this->project($owner, $people, $realPeople, $clientId, ++$number) ? 1 : 0;
                    }
                }
            }
            $clientId = $this->client($owner, $people->concat($realPeople), null, 'independent', 'Demo: Independent client');
            foreach (range(1, 3) as $i) {
                $added += $this->project($owner, $people, $realPeople, $clientId, ++$number) ? 1 : 0;
                $added += $this->project($owner, $people, $realPeople, null, ++$number) ? 1 : 0;
            }

            return $added;
        });
        $this->command->info('PPM demo owner: '.$owner->name.' (account '.$owner->id.'). Added '.$added.' projects; existing projects and their edits were kept.');
        $this->command->line('Initial pack: 24 portfolios, 36 clients, 77 projects and 2,330 tasks/subtasks. No known demo passwords or outbound notifications.');
    }

    /** @return Collection<int, User> */
    private function people(): Collection
    {
        $roleId = self::id('role');
        $newRole = $this->keep(Role::class, 'role', ['name' => 'PPM demo participants', 'all_data' => false, 'is_system' => false]);
        if ($newRole) {
            foreach (['PROJECTS', 'TASKS'] as $module) {
                $this->keep(RoleModuleGrant::class, 'role:'.$module, ['role_id' => $roleId, 'module' => $module]);
            }
        }
        $users = collect();
        foreach (self::PEOPLE as $index => $name) {
            [$first, $last] = explode(' ', $name, 2);
            $employeeId = self::id('person:'.$index);
            $this->keep(EmployeeProfile::class, 'person:'.$index, ['employee_code' => 'DEMO-PPM-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'first_name' => 'Demo '.$first, 'last_name' => $last, 'employment_status' => 'Contractual', 'date_hired' => '2026-01-01', 'is_active' => true]);
            $email = 'ppm-demo-'.($index + 1).'@example.test';
            $created = $this->keep(User::class, 'account:'.$index, ['employee_id' => $employeeId, 'role_id' => $roleId, 'is_active' => true,
                'password' => bin2hex(random_bytes(32)), 'email_verified_at' => now()], ['email' => $email]);
            $user = User::query()->where('email', $email)->firstOrFail();
            if ($user->employee_id !== $employeeId || $user->role_id !== $roleId) {
                throw new \RuntimeException('A reserved demo account is already in use. Existing identity was left untouched.');
            }
            $users->push($user);
            if ($created) {
                $week = CapacityWindow::from('');
                foreach (range(0, 7) as $w) {
                    if ($index % 7 === 6) {
                        continue;
                    }
                    $date = CarbonImmutable::parse($week)->addWeeks($w)->format('Y-m-d');
                    $this->keep(ProjectAvailability::class, $employeeId.':'.$date, ['employee_id' => $employeeId, 'week_start' => $date,
                        'hours_hundredths' => [2400, 2800, 3200, 4000, 0][$index % 5], 'shared' => $index % 6 !== 5, 'revision' => 1, 'updated_by' => $user->id]);
                }
            }
        }

        return $users;
    }

    /** @param Collection<int, User> $people */
    private function client(User $owner, Collection $people, ?string $portfolioId, string $key, string $name): string
    {
        $id = self::id('client:'.$key);
        if ($this->keep(ProjectClient::class, 'client:'.$key, ['owner_id' => $owner->id, 'portfolio_id' => $portfolioId, 'name' => $name])) {
            foreach ($people as $person) {
                if ($person->id !== $owner->id) {
                    $this->keep(ProjectClientMember::class, $id.':'.$person->id, ['client_id' => $id, 'user_id' => $person->id]);
                }
            }
        }

        return $id;
    }

    /** @param Collection<int, User> $people
     * @param  Collection<int, User>  $realPeople
     */
    private function project(User $owner, Collection $people, Collection $realPeople, ?string $clientId, int $number): bool
    {
        $key = 'project:'.$number;
        $id = self::id($key);
        $status = ['ACTIVE', 'PLANNING', 'ACTIVE', 'ON_HOLD', 'ACTIVE', 'COMPLETED', 'ACTIVE', 'CANCELLED'][($number - 1) % 8];
        $start = $this->dateKey($status === 'PLANNING' ? -1 : 210);
        $due = $this->dateKey(in_array($status, ['COMPLETED', 'CANCELLED'], true) ? 5 : ($number % 4 === 0 ? 7 : -($number % 45 + 5)));
        if (! $this->keep(Project::class, $key, ['client_id' => $clientId, 'code' => 'PRJ-DEMO-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'name' => 'Demo: '.self::PROJECTS[($number - 1) % count(self::PROJECTS)], 'description' => 'Deliver the agreed scope through discovery, implementation and verified handover.',
            'status' => $status, 'manager_id' => $people[0]->id, 'start_date' => $start, 'due_date' => $due])) {
            return false;
        }
        foreach ($people as $index => $person) {
            if ($index === 0) {
                continue;
            }
            $this->keep(ProjectMember::class, $id.':member:'.$person->id, ['project_id' => $id, 'user_id' => $person->id,
                'role' => $index === 1 ? 'EDITOR' : ($index < 4 ? 'VIEWER' : 'PARTICIPANT'), 'position' => ['Delivery lead', 'Planner', 'Reviewer', 'Observer'][$index] ?? 'Delivery team']);
        }
        foreach (['Discovery', 'Delivery', 'Acceptance'] as $g => $name) {
            $this->keep(ProjectTaskGroup::class, $id.':group:'.$g, ['project_id' => $id, 'name' => $name]);
        }
        foreach (range(0, $number === 1 ? 43 : 23) as $n) {
            $this->task($owner, $people, $realPeople, $id, $number, $status, $n, $start, $due);
        }
        $project = Project::query()->findOrFail($id);
        app(PpmDemoFinanceSeeder::class)->seedProject($project, $owner, $people, $number);
        app(PpmDemoReportingSeeder::class)->seedProject($project, $owner, $people, $number);

        return true;
    }

    /** @param Collection<int, User> $people
     * @param  Collection<int, User>  $realPeople
     */
    private function task(User $owner, Collection $people, Collection $realPeople, string $projectId, int $number, string $projectStatus, int $n, string $projectStart, string $projectDue, ?int $parent = null): void
    {
        $key = $projectId.':task:'.$n;
        $id = self::id($key);
        $crew = $n % 9 === 8 ? collect() : collect([$people[4 + (($n + $number) % 24)], $people[4 + (($n + $number + 7) % 24)]]);
        if ($n % 8 === 0 && $realPeople->isNotEmpty()) {
            $crew->push($realPeople[$number % $realPeople->count()]);
        }
        $mode = $n > 2 && $parent === null && $n % 5 === 3 && $crew->count() > 1 ? 'INDIVIDUAL' : 'GROUP';
        $status = $n >= 24 && $n < 44 ? 'PENDING' : ['PENDING', 'IN_PROGRESS', 'PENDING', 'SUBMITTED', 'RETURNED', 'COMPLETED', 'CANCELLED', 'IN_PROGRESS'][$n % 8];
        if ($parent !== null) {
            $status = $n % 2 === 0 ? 'COMPLETED' : 'PENDING';
        }
        if ($mode === 'INDIVIDUAL' && in_array($status, ['SUBMITTED', 'RETURNED'], true)) {
            $status = 'IN_PROGRESS';
        }
        if (in_array($projectStatus, ['COMPLETED', 'CANCELLED'], true)) {
            $status = $projectStatus;
        }
        $handed = in_array($status, ['SUBMITTED', 'RETURNED', 'COMPLETED'], true) && $crew->isNotEmpty();
        $titles = ['Confirm scope and acceptance criteria', 'Prepare the delivery plan', 'Review requirements with the client', 'Build the initial deliverable', 'Check accessibility and usability', 'Record the delivery evidence', 'Resolve implementation issues', 'Prepare the handover checklist', 'Schedule the field visit', 'Validate the reporting data', 'Review the budget assumptions', 'Confirm partner contributions'];
        // Keep new sample dates inside the project, so ordinary edits remain valid.
        $start = $n % 11 === 10 ? null : max($projectStart, min($projectDue, $this->dateKey(25 + $n % 15)));
        $due = $start === null ? null : max($start, min($projectDue, $this->dateKey(($n % 5 - 2) * 5)));
        $this->keep(ProjectTask::class, $key, ['project_id' => $projectId, 'code' => 'TSK-DEMO-'.$number.'-'.str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT),
            'title' => ($parent === null ? '' : 'Subtask: ').$titles[$n % count($titles)], 'description' => 'Synthetic work. Capture the result, supporting evidence and any follow-up needed before acceptance.',
            'group_id' => $parent !== null || $n % 7 === 6 ? null : self::id($projectId.':group:'.($n % 3)), 'parent_id' => $parent === null ? null : self::id($projectId.':task:'.$parent),
            'status' => $status, 'priority' => ['NORMAL', 'HIGH', 'URGENT', 'LOW'][$n % 4], 'submission_mode' => $mode,
            'start_date' => $start, 'due_date' => $due,
            'assigned_by' => $owner->id, 'assigned_at' => $this->at(30, 9, 0), 'submitted_at' => $handed && $mode === 'GROUP' ? $this->at(3, 14, 0) : null,
            'submitted_by' => $handed && $mode === 'GROUP' ? $crew[0]->employee_id : null, 'completed_at' => $status === 'COMPLETED' ? $this->at(1, 15, 0) : null,
            'reviewed_by' => in_array($status, ['COMPLETED', 'RETURNED', 'CANCELLED'], true) ? $people[0]->id : null,
            'remarks' => $handed && $mode === 'GROUP' ? 'Demo hand-in with supporting checks.' : null, 'review_remarks' => $status === 'RETURNED' ? 'Demo review: confirm the outstanding acceptance check.' : null]);
        foreach ($crew as $j => $person) {
            $this->keep(ProjectTaskAssignee::class, $id.':crew:'.$person->employee_id, ['task_id' => $id, 'employee_id' => $person->employee_id, 'assigned_by' => $owner->id]);
            if ($mode === 'INDIVIDUAL' && $status !== 'PENDING' && ($j !== 0 || $status === 'COMPLETED')) {
                $response = $status === 'COMPLETED' ? 'APPROVED' : ['SUBMITTED', 'RETURNED', 'APPROVED'][($n + $j) % 3];
                $this->keep(ProjectTaskSubmission::class, $id.':response:'.$person->employee_id, ['task_id' => $id, 'employee_id' => $person->employee_id,
                    'status' => $response, 'remarks' => 'Private demo response from '.$person->name.'.', 'submitted_at' => $this->at(3, 14, 0),
                    'revision' => 1, 'reviewed_by' => $response === 'SUBMITTED' ? null : $people[0]->id,
                    'reviewed_at' => $response === 'SUBMITTED' ? null : $this->at(2, 15, 0), 'review_remarks' => $response === 'RETURNED' ? 'Demo rework: add the missing verification.' : null]);
            }
            if ($number % 4 === 1 && $n % 3 !== 2 && $n >= 3 && ! in_array($status, ['COMPLETED', 'CANCELLED'], true)) {
                $week = CapacityWindow::from('');
                $this->keep(ProjectTaskEffort::class, $id.':effort:'.$person->employee_id, ['task_id' => $id, 'employee_id' => $person->employee_id,
                    'week_start' => CarbonImmutable::parse($week)->addWeeks(($n + $number) % 4)->format('Y-m-d'), 'hours_hundredths' => [200, 400, 800, 1200][$n % 4], 'revision' => 1, 'updated_by' => $owner->id]);
            }
        }
        if ($n < 3 && $parent === null) {
            if ($n === 1) {
                $this->keep(ProjectTaskEffort::class, $id.':parent-effort', ['task_id' => $id, 'employee_id' => $crew[0]->employee_id,
                    'week_start' => CapacityWindow::from(''), 'hours_hundredths' => 500, 'revision' => 1, 'updated_by' => $owner->id]);
            }
            foreach ([44 + $n * 2, 45 + $n * 2] as $child) {
                $this->task($owner, $people, $realPeople, $projectId, $number, $projectStatus, $child, $projectStart, $projectDue, $n);
            }
        }
        if ($n === 4) {
            $this->keep(ProjectTaskEffort::class, $id.':former-effort', ['task_id' => $id, 'employee_id' => $people[4 + (($n + $number + 1) % 24)]->employee_id,
                'week_start' => CapacityWindow::from(''), 'hours_hundredths' => 300, 'revision' => 1, 'updated_by' => $owner->id]);
        }
        if ($n === 3) {
            $this->attachment($projectId, $id, $owner->id);
            if ($crew->isNotEmpty()) {
                $person = $mode === 'INDIVIDUAL' ? $crew[1] : $crew[0];
                $this->attachment($projectId, $id, $person->id, null, $person->employee_id);
            }
            $this->keep(TaskComment::class, $id.':comment', ['task_id' => $id, 'author_id' => $people[1]->id, 'author_name' => $people[1]->name,
                'side' => 'MANAGEMENT', 'body' => 'Demo update: the first deliverable is ready for its acceptance checks.', 'event' => null]);
        }
    }
}
