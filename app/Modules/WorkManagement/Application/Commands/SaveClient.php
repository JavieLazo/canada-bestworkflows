<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectClient;
use App\Modules\WorkManagement\Contracts\Views\ClientView;
use App\Modules\WorkManagement\Infrastructure\ClientScope;
use App\Modules\WorkManagement\Infrastructure\PortfolioScope;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveClient
{
    public function handle(Access $actor, ?string $clientId, string $name, ?string $portfolioId = null, ?string $description = null): ClientView
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::PROJECTS), 403);
        try {
            return DB::transaction(static function () use ($actor, $clientId, $name, $portfolioId, $description): ClientView {
                $client = $clientId === null ? new ProjectClient : ClientScope::lockedOwner($actor, $clientId);
                $name = trim($name);
                Validator::make(['name' => $name, 'description' => $description], ['name' => ['required', 'string', 'max:200'], 'description' => 'nullable|string|max:4000'])->validate();
                if ($clientId === null) {
                    if ($portfolioId !== null) {
                        PortfolioScope::contribution($actor, $portfolioId);
                    }
                    $client->portfolio_id = $portfolioId;
                    $client->owner_id = (int) $actor->userId;
                }
                $client->name = $name;
                if ($description !== null) {
                    $client->description = trim($description) ?: null;
                }
                $changed = ! $client->exists || $client->isDirty(['name', 'description']);
                if ($changed) {
                    $event = match (true) {
                        $clientId === null => AuditEvent::CLIENT_CREATED,
                        $client->isDirty('description') => AuditEvent::CLIENT_UPDATED,
                        default => AuditEvent::CLIENT_RENAMED,
                    };
                    $client->save();
                    AuditService::record($actor, $event,
                        'project_clients', $client->id, 'Client '.$name.($clientId === null ? ' created' : ' updated'));
                }

                return Snapshot::of($client, ClientView::class, ['can_manage' => true, 'people_count' => $client->members()->count() + 1]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'You already have a client with this name.']);
        }
    }
}
