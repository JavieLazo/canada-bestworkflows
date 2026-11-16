<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Models\ProjectClientMember;
use App\Modules\IdentityAccess\Contracts\ProjectAccounts;
use App\Modules\WorkManagement\Infrastructure\ClientScope;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetClientMember
{
    public function handle(Access $actor, string $clientId, int $userId, bool $include): bool
    {
        return DB::transaction(static function () use ($actor, $clientId, $userId, $include): bool {
            $client = ClientScope::lockedOwner($actor, $clientId);
            if ($client->owner_id === $userId) {
                throw ValidationException::withMessages(['member' => 'The owner already has access and must keep it.']);
            }
            $member = $client->members()->where('user_id', $userId)->first();
            if ($include) {
                if (app(ProjectAccounts::class)->participants($actor, [$userId])->isEmpty()) {
                    throw ValidationException::withMessages(['member' => 'Choose an active account with Project Management or My Tasks access.']);
                }
                if ($member !== null) {
                    return false;
                }
                $member = new ProjectClientMember;
                $member->client_id = $client->id;
                $member->user_id = $userId;
                $member->save();
            } else {
                if ($member === null) {
                    return false;
                }
                $member->delete();
            }
            AuditService::record($actor, $include ? AuditEvent::CLIENT_MEMBER_ADDED : AuditEvent::CLIENT_MEMBER_REMOVED,
                'project_clients', $client->id, 'Account #'.$userId.($include ? ' added to ' : ' removed from ').$client->name);

            return true;
        });
    }
}
