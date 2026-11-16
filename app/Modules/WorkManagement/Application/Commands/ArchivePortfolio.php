<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Modules\WorkManagement\Infrastructure\PortfolioScope;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;

final class ArchivePortfolio
{
    public function handle(Access $actor, string $portfolioId, bool $archive): bool
    {
        return DB::transaction(static function () use ($actor, $portfolioId, $archive): bool {
            $portfolio = PortfolioScope::locked($actor, $portfolioId, ownerOnly: true);
            if (($portfolio->archived_at !== null) === $archive) {
                return false;
            }
            $portfolio->archived_at = $archive ? now()->toImmutable() : null;
            $portfolio->save();
            AuditService::record($actor, $archive ? AuditEvent::PORTFOLIO_ARCHIVED : AuditEvent::PORTFOLIO_RESTORED,
                'project_portfolios', $portfolio->id, 'Portfolio '.$portfolio->name.($archive ? ' archived' : ' restored'));

            return true;
        });
    }
}
