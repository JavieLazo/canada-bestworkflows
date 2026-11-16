<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Modules\WorkManagement\Infrastructure\ClientScope;
use App\Modules\WorkManagement\Infrastructure\PortfolioScope;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrganizeClient
{
    public function handle(Access $actor, string $clientId, ?string $portfolioId, ?string $expectedPortfolioId): bool
    {
        return DB::transaction(static function () use ($actor, $clientId, $portfolioId, $expectedPortfolioId): bool {
            $client = ClientScope::lockedOwner($actor, $clientId);
            if ($client->portfolio_id !== $expectedPortfolioId) {
                throw ValidationException::withMessages(['portfolio' => 'This client was organized elsewhere. Reopen it before making another change.']);
            }
            if ($portfolioId === $client->portfolio_id) {
                return false;
            }
            if ($portfolioId !== null) {
                PortfolioScope::contribution($actor, $portfolioId);
            }
            $client->portfolio_id = $portfolioId;
            $client->save();
            AuditService::record($actor, AuditEvent::CLIENT_ORGANIZED, 'project_clients', $client->id,
                'Client '.$client->name.($portfolioId === null ? ' removed from its portfolio' : ' organized in portfolio '.$portfolioId));

            return true;
        });
    }
}
