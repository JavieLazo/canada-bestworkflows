<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollBenefitCeiling;
use App\Modules\Payroll\Application\BenefitCeilings;
use App\Modules\Payroll\Contracts\Views\BenefitCeilingView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * The yearly tax-free ceiling's versions as the Incentives & Bonuses page
 * lists them, newest first: any RECORDS or PAYROLL holder reads them (the
 * page's own gate); writes go through SaveBenefitCeiling.
 */
final class BenefitCeilingRecords
{
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
