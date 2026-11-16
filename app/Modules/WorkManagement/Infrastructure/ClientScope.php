<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\ProjectClient;
use App\Modules\WorkManagement\Contracts\Views\ClientView;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;

final class ClientScope
{
    /** @return Builder<ProjectClient> */
    public static function clients(Access $actor): Builder
    {
        return self::workClients($actor)->when(! $actor->hasModule(ModuleCode::PROJECTS), static fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    /** @return Builder<ProjectClient> */
    public static function workClients(Access $actor): Builder
    {
        $query = ProjectClient::query();
        if (! $actor->isSignedIn() || ! $actor->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS)) {
            return $query->whereRaw('1 = 0');
        }

        // Broad EMS data access never replaces explicit client access.
        return $query->where(static function (Builder $visible) use ($actor): void {
            $visible->where('owner_id', '=', $actor->userId)
                ->orWhereHas('members', static fn (Builder $members) => $members->where('user_id', '=', $actor->userId));
        });
    }

    /** @return Builder<ProjectClient> */
    public static function manageable(Access $actor): Builder
    {
        $query = self::clients($actor);

        return $actor->mayReadAllData(ModuleCode::PROJECTS) ? $query : $query->where('owner_id', '=', $actor->userId);
    }

    public static function allowsWork(Access $actor, ?string $clientId, bool $lock = false): bool
    {
        if ($clientId === null) {
            return true;
        }
        if (! $actor->isSignedIn() || ! $actor->hasModule(ModuleCode::PROJECTS, ModuleCode::TASKS)) {
            return false;
        }
        $query = ProjectClient::query()->whereKey($clientId);
        $client = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($client === null) {
            return false;
        }
        if ((string) $client->owner_id === $actor->userId) {
            return true;
        }
        $membership = $client->members()->where('user_id', '=', $actor->userId);

        return $lock ? $membership->lockForUpdate()->first() !== null : $membership->exists();
    }

    public static function owns(Access $actor, ProjectClient|ClientView $client): bool
    {
        return $actor->isSignedIn() && $actor->hasModule(ModuleCode::PROJECTS)
            && (string) $client->owner_id === $actor->userId;
    }

    public static function lockedOwner(Access $actor, string $id): ProjectClient
    {
        $client = self::clients($actor)->whereKey($id)->lockForUpdate()->first();
        abort_unless($client !== null && self::owns($actor, $client), 403, 'Only the client owner can change its name or access.');

        return $client;
    }
}
