<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectFinanceMember;
use App\Models\ProjectMember;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\WorkManagement\Application\Queries\ProjectPeople;
use App\Modules\WorkManagement\Application\WorkRules;
use App\Modules\WorkManagement\Infrastructure\ProjectMembership;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SetProjectMember
{
    public function handle(Access $actor, string $projectId, int $userId, ?string $role, string $position = '', bool $mustBeNew = false): bool
    {
        return DB::transaction(static function () use ($actor, $projectId, $userId, $role, $position, $mustBeNew): bool {
            $project = WorkRules::lockedProject($actor, $projectId);
            if (! WorkRules::managesProject($actor, $project)) {
                throw new RuntimeException(WorkRules::forbiddenMessage());
            }
            $member = ProjectMember::query()->where('project_id', $projectId)->where('user_id', $userId)->lockForUpdate()->first();
            if ($mustBeNew && $member !== null) {
                throw ValidationException::withMessages(['memberUserId' => 'This person is already on the team. Close this dialog and edit their access from the team list.']);
            }
            if ($role === null) {
                if ($member === null) {
                    return false;
                }
                $member->delete();
                ProjectFinanceMember::query()->where('project_id', $projectId)->where('user_id', $userId)->delete();
            } else {
                $position = trim($position);
                Validator::make(['memberRole' => $role, 'memberPosition' => $position], [
                    'memberRole' => ['required', Rule::in(array_keys(ProjectMembership::ROLES))],
                    'memberPosition' => 'nullable|string|max:80',
                ])->validate();
                if (app(ProjectPeople::class)->accounts($actor, $projectId)->where('id', $userId)->isEmpty()
                    || ($role !== 'PARTICIPANT' && app(ModuleAccounts::class)->filter($actor, ModuleCode::PROJECTS, [$userId]) === [])) {
                    throw ValidationException::withMessages(['memberUserId' => 'Choose an active account with the required module and client access.']);
                }
                $member ??= new ProjectMember;
                $member->project_id = $projectId;
                $member->user_id = $userId;
                $member->fill(['role' => $role, 'position' => $position === '' ? null : $position]);
                if ($member->exists && ! $member->isDirty()) {
                    return false;
                }
                $member->save();
            }
            AuditService::record($actor, $role === null ? AuditEvent::PROJECT_MEMBER_REMOVED : AuditEvent::PROJECT_MEMBER_SAVED,
                'projects', $projectId, 'Account #'.$userId.($role === null ? ' removed from ' : ' set to '.ProjectMembership::ROLES[$role].' in ').$project->code);

            return true;
        });
    }
}
