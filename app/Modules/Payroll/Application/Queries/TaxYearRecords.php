<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollTaxBalance;
use App\Modules\Payroll\Application\TaxYearToDate;
use App\Modules\Payroll\Contracts\Views\TaxBalanceView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * One employee's tax year as the pay page shows it (divergence 20): the
 * taxable pay and withholding the year's posted payslips and approved
 * balances already carry, and every balance recorded for the employee,
 * newest year first. All-data PAYROLL only.
 */
final class TaxYearRecords
{
    /** The two kinds of balance, with their names on screen (PayrollTaxBalance::KINDS). */
    public const array KINDS = PayrollTaxBalance::KINDS;

    /** @return array{year: int, taxable: float, withheld: float} */
    public function summary(Access $actor, string $employeeId, int $year): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        $posted = TaxYearToDate::posted([$employeeId], $year)[$employeeId] ?? ['taxable' => 0.0, 'withheld' => 0.0];

        return ['year' => $year, 'taxable' => $posted['taxable'], 'withheld' => $posted['withheld']];
    }

    /** @return Collection<int, TaxBalanceView> newest year first, drafts first within a year */
    public function balances(Access $actor, string $employeeId): Collection
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! TaxYearToDate::balancesReady()) {
            return new Collection;
        }

        return PayrollTaxBalance::query()->where('employee_id', $employeeId)
            ->orderByDesc('tax_year')->orderBy('status')->orderByDesc('id')->get()
            ->map(static fn (PayrollTaxBalance $row): TaxBalanceView => Snapshot::of($row, TaxBalanceView::class));
    }
}
