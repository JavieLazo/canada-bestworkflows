<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectPortfolio;
use App\Modules\WorkManagement\Contracts\Views\PortfolioView;
use App\Modules\WorkManagement\Infrastructure\PortfolioScope;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SavePortfolio
{
    public function handle(Access $actor, ?string $portfolioId, string $name, string $description = ''): PortfolioView
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::PROJECTS), 403);
        try {
            return DB::transaction(static function () use ($actor, $portfolioId, $name, $description): PortfolioView {
                $portfolio = $portfolioId === null ? new ProjectPortfolio : PortfolioScope::locked($actor, $portfolioId, ownerOnly: true);
                $name = trim($name);
                $description = trim($description);
                Validator::make(compact('name', 'description'), [
                    'name' => ['required', 'string', 'max:200'], 'description' => ['string', 'max:4000'],
                ])->validate();
                if ($portfolioId === null) {
                    $portfolio->owner_id = (int) $actor->userId;
                }
                $portfolio->fill(['name' => $name, 'description' => $description === '' ? null : $description]);
                if (! $portfolio->exists || $portfolio->isDirty()) {
                    $portfolio->save();
                    AuditService::record($actor, $portfolioId === null ? AuditEvent::PORTFOLIO_CREATED : AuditEvent::PORTFOLIO_UPDATED,
                        'project_portfolios', $portfolio->id, 'Portfolio '.$name.($portfolioId === null ? ' created' : ' updated'));
                }

                return Snapshot::of($portfolio, PortfolioView::class, ['can_manage' => true, 'people_count' => $portfolio->members()->count() + 1]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'You already have a portfolio with this name.']);
        }
    }
}
