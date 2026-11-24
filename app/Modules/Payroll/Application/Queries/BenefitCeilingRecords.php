<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollBenefitCeiling;
use App\Modules\Payroll\Application\BenefitCeilings;
use App\Modules\Payroll\Contracts\Views\BenefitCeilingView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\LocalClock;
use Illuminate\Support\Collection;

/**
 * The yearly tax-free ceiling as the Incentives & Bonuses page shows it:
 * the one in use today (`current`). Any RECORDS or PAYROLL holder reads it
 * (the page's own gate); ReplaceBenefitCeiling writes it. `versions` lists
 * every stored row, newest first, for callers that still read them.
 */
final class BenefitCeilingRecords
{
    /** The ceiling in use today, or null when none is recorded yet. */
    public function current(Access $actor): ?BenefitCeilingView
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL, ModuleCode::RECORDS), 403);
        $row = BenefitCeilings::inForce(LocalClock::todayKey());

        return $row === null ? null : Snapshot::of($row, BenefitCeilingView::class);
    }

    /** @return Collection<int, BenefitCeilingView> newest first */
    public function versions(Access $actor): Collection
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL, ModuleCode::RECORDS), 403);
        if (! BenefitCeilings::ready()) {
            return new Collection;
        }

        return PayrollBenefitCeiling::query()->orderByDesc('effective_from')->orderByDesc('id')->get()
            ->map(static fn (PayrollBenefitCeiling $row): BenefitCeilingView => Snapshot::of($row, BenefitCeilingView::class));
    }
}
