<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\ProjectPortfolioMember;
use App\Modules\IdentityAccess\Contracts\ModuleAccounts;
use App\Modules\WorkManagement\Infrastructure\PortfolioScope;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetPortfolioMember
{
    public function handle(Access $actor, string $portfolioId, int $userId, bool $include): bool
    {
        return DB::transaction(static function () use ($actor, $portfolioId, $userId, $include): bool {
            $portfolio = PortfolioScope::locked($actor, $portfolioId, ownerOnly: true);
            if ($portfolio->owner_id === $userId) {
                throw ValidationException::withMessages(['member' => 'The owner already has access and must keep it.']);
            }
            $member = $portfolio->members()->where('user_id', $userId)->first();
            if ($include) {
                if (app(ModuleAccounts::class)->filter($actor, ModuleCode::PROJECTS, [$userId]) === []) {
                    throw ValidationException::withMessages(['member' => 'Choose an active account with Project Management access.']);
                }
                if ($member !== null) {
                    return false;
                }
                $member = new ProjectPortfolioMember;
                $member->portfolio_id = $portfolio->id;
                $member->user_id = $userId;
                $member->save();
            } else {
                if ($member === null) {
                    return false;
                }
                $member->delete();
            }
            AuditService::record($actor, $include ? AuditEvent::PORTFOLIO_MEMBER_ADDED : AuditEvent::PORTFOLIO_MEMBER_REMOVED,
                'project_portfolios', $portfolio->id, 'Account #'.$userId.($include ? ' added to ' : ' removed from ').$portfolio->name);

            return true;
        });
    }
}
