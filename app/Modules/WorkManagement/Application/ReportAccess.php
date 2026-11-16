<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\Project;
use App\Models\ProjectClient;
use App\Models\ProjectClientMember;
use App\Models\ProjectFinanceMember;
use App\Models\ProjectMember;
use App\Models\ProjectReport;
use App\Modules\IdentityAccess\Contracts\AccountLabel;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\WorkManagement\Infrastructure\FinanceScope;
use App\Modules\WorkManagement\Infrastructure\PlanningScope;
use App\Modules\WorkManagement\Infrastructure\SharedWorkScope;
use App\Modules\WorkManagement\Infrastructure\WorkScope;
use App\Platform\Notifications\NotificationRecipients;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class ReportAccess
{
    public static function allows(Access $actor, string $projectId, bool $work, bool $financial, bool $edit = false): bool
    {
        $work = $work || ! $financial;
        if (! $actor->isSignedIn() || ! $actor->hasModule(ModuleCode::PROJECTS) || ! WorkScope::projects($actor)->whereKey($projectId)->exists()) {
            return false;
        }

        $readable = (! $work || SharedWorkScope::projects($actor)->whereKey($projectId)->exists())
            && (! $financial || FinanceScope::projects($actor)->whereKey($projectId)->exists());

        return $readable && (! $edit || ($work ? PlanningScope::projects($actor)->whereKey($projectId)->exists()
            : PlanningScope::projects($actor)->whereKey($projectId)->exists() || FinanceScope::projects($actor, edit: true)->whereKey($projectId)->exists()));
    }

    public static function locked(Access $actor, string $projectId, bool $work, bool $financial, bool $edit): Project
    {
        if ($work || ! $financial) {
            $project = ReportingRules::locked($actor, $projectId, edit: $edit);
        } else {
            $project = FinanceRules::locked($actor, $projectId);
            if ($edit && ! WorkRules::editsWork($actor, $project, lock: true)) {
                FinanceRules::locked($actor, $project->id, edit: true);
            }
        }
        if ($financial) {
            FinanceRules::locked($actor, $project->id);
        }

        return $project;
    }

    /** @return Builder<ProjectReport> */
    public static function reports(Access $actor, ?string $projectId = null, string $portfolio = 'ALL', string $client = 'ALL'): Builder
    {
        return ProjectReport::query()->whereIn('project_id', WorkScope::projects($actor, $portfolio)->when($client !== 'ALL', static fn (Builder $query) => $query->where('client_id', $client))->select('projects.id'))
            ->when(! $actor->hasModule(ModuleCode::PROJECTS), static fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($projectId !== null, static fn (Builder $query) => $query->where('project_id', $projectId))
            ->where(static fn (Builder $query) => $query->where('work', false)->orWhereIn('project_id', SharedWorkScope::projects($actor)->select('projects.id')))
            ->where(static fn (Builder $query) => $query->where('financial', false)->orWhereIn('project_id', FinanceScope::projects($actor)->select('projects.id')));
    }

    /** @return Collection<int, AccountLabel> */
    public static function reviewers(Access $actor, ProjectReport $report, bool $lock = false): Collection
    {
        abort_unless(self::allows($actor, $report->project_id, $report->work, $report->financial), 403);
        $project = Project::query()->when($lock, static fn (Builder $query) => $query->lockForUpdate())->findOrFail($report->project_id);
        $owner = $project->client_id === null ? null : ProjectClient::query()->whereKey($project->client_id)->when($lock, static fn (Builder $query) => $query->lockForUpdate())->first()?->owner_id;
        $ids = ProjectMember::query()->where('project_id', $project->id)->whereIn('role', ['VIEWER', 'EDITOR'])->when($lock, static fn (Builder $query) => $query->lockForUpdate())->pluck('user_id')->all();
        if ($project->manager_id !== null) {
            $ids[] = $project->manager_id;
        }
        if ($owner !== null) {
            $ids[] = (int) $owner;
        }
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        $ids = app(ModuleAccounts::class)->filter($actor, ModuleCode::PROJECTS, $ids, $lock);
        if ($project->client_id !== null) {
            $members = ProjectClientMember::query()->where('client_id', $project->client_id)->when($lock, static fn (Builder $query) => $query->lockForUpdate())->pluck('user_id')->map(intval(...))->all();
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id === (int) $owner || in_array($id, $members, true)));
        }
        if ($report->financial) {
            $finance = ProjectFinanceMember::query()->where('project_id', $project->id)->whereIn('role', ['VIEWER', 'EDITOR'])->when($lock, static fn (Builder $query) => $query->lockForUpdate())->pluck('user_id')->map(intval(...))->all();
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id === (int) $owner || in_array($id, $finance, true)));
        }

        return app(AccountNames::class)->list($actor, $ids)->where('is_active', true)->values();
    }

    public static function reviewer(Access $actor, ProjectReport $report, int $id, ?int $authorId = null): void
    {
        if ($id === ($authorId ?? (int) $actor->userId) || ! self::reviewers($actor, $report, true)->contains('id', $id)) {
            throw ValidationException::withMessages(['reviewerId' => 'Choose another current project viewer or editor with access to all report sections.']);
        }
    }

    public static function notify(int $userId, ProjectReport $report, string $kind, string $title): void
    {
        try {
            foreach (app(NotificationRecipients::class)->accounts([$userId]) as $recipient) {
                if (self::allows($recipient, $report->project_id, $report->work, $report->financial)) {
                    Notifier::account($userId, $kind, $title, $report->title, '/projects/'.$report->project_id.'?view=reporting&report='.$report->id);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[projects] report notification failed', ['error' => $e->getMessage()]);
        }
    }
}
