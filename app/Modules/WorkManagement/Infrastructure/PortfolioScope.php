<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\ProjectClient;
use App\Models\ProjectPortfolio;
use App\Modules\WorkManagement\Contracts\Views\PortfolioView;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class PortfolioScope
{
    /** @return Builder<ProjectPortfolio> */
    public static function portfolios(Access $actor): Builder
    {
        $query = ProjectPortfolio::query();
        if (! $actor->isSignedIn() || ! $actor->hasModule(ModuleCode::PROJECTS)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(static function (Builder $visible) use ($actor): void {
            $visible->where('owner_id', $actor->userId)
                ->orWhereHas('members', static fn (Builder $members) => $members->where('user_id', $actor->userId));
        });
    }

    public static function owns(Access $actor, ProjectPortfolio|PortfolioView $portfolio): bool
    {
        return $actor->isSignedIn() && $actor->hasModule(ModuleCode::PROJECTS)
            && (string) $portfolio->owner_id === $actor->userId;
    }

    public static function locked(Access $actor, string $id, bool $ownerOnly = false): ProjectPortfolio
    {
        abort_unless($actor->isSignedIn() && $actor->hasModule(ModuleCode::PROJECTS), 403);
        $portfolio = ProjectPortfolio::query()->whereKey($id)->lockForUpdate()->first();
        abort_if($portfolio === null, 403, 'This portfolio is unavailable.');
        $owner = self::owns($actor, $portfolio);
        $member = ! $owner && ! $ownerOnly && $portfolio->members()->where('user_id', $actor->userId)->lockForUpdate()->first() !== null;
        abort_unless($owner || $member, 403, 'This portfolio is unavailable.');

        return $portfolio;
    }

    public static function contribution(Access $actor, string $id): ProjectPortfolio
    {
        $portfolio = self::locked($actor, $id);
        if ($portfolio->archived_at !== null) {
            throw ValidationException::withMessages(['portfolio' => 'Restore this portfolio before adding clients.']);
        }

        return $portfolio;
    }

    /** @param Builder<ProjectClient> $query
     * @return Builder<ProjectClient>
     */
    public static function clients(Builder $query, Access $actor, string $portfolio): Builder
    {
        return match ($portfolio) {
            'ALL' => $query,
            'NONE' => $query->whereNull('project_clients.portfolio_id'),
            default => $query->where('project_clients.portfolio_id', $portfolio)
                ->whereIn('project_clients.portfolio_id', self::portfolios($actor)->select('id')),
        };
    }
}
