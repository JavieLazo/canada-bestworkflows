<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectFinanceMember;
use App\Models\ProjectMember;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\WorkManagement\Application\FinanceRules;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Infrastructure\FinanceScope;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SetProjectFinanceMember
{
    public function handle(Access $actor, string $projectId, int $userId, ?string $role): bool
    {
        return DB::transaction(static function () use ($actor, $projectId, $userId, $role): bool {
            $project = FinanceRules::locked($actor, $projectId, admin: true);
            $grant = ProjectFinanceMember::query()->where('project_id', $projectId)->where('user_id', $userId)->lockForUpdate()->first();
            if ($role === null) {
                if ($grant === null) {
                    return false;
                }
                $grant->delete();
            } else {
                Validator::make(['financeRole' => $role], ['financeRole' => ['required', Rule::in(array_keys(FinanceScope::ROLES))]])->validate();
                $member = ProjectMember::query()->where('project_id', $projectId)->where('user_id', $userId)->lockForUpdate()->first();
                if (($member === null && $project->manager_id !== $userId)
                    || app(ModuleAccounts::class)->filter($actor, ModuleCode::PROJECTS, [$userId]) === []
                    || ! app(ProjectPeople::class)->accounts($actor, $projectId)->contains('id', $userId)) {
                    throw ValidationException::withMessages(['financeMember' => 'Choose an active project member or project lead with Project Management and client access.']);
                }
                $grant ??= new ProjectFinanceMember;
                $grant->forceFill(['project_id' => $projectId, 'user_id' => $userId, 'role' => $role]);
                if ($grant->exists && ! $grant->isDirty()) {
                    return false;
                }
                $grant->save();
            }
            AuditService::record($actor, AuditEvent::PROJECT_FINANCE_ACCESS_CHANGED, 'projects', $projectId,
                'Financial access for account #'.$userId.($role === null ? ' removed' : ' set to '.$role));

            return true;
        });
    }
}
