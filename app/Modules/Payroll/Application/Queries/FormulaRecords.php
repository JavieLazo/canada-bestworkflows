<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Payroll\FormulaPolicy;
use App\Models\PayrollFormula;
use App\Modules\Payroll\Contracts\Views\PayrollFormulaView;
use App\Platform\ReadModels\Snapshot;
use App\Platform\ReadModels\SqlText;
use App\Support\Access;
use Illuminate\Support\Collection;

/** The parameter editor reads detached values in the existing code order. */
final class FormulaRecords
{
    /** @return Collection<int, PayrollFormulaView> */
    public function list(Access $actor): Collection
    {
        abort_if($actor->userId === null, 403);

        // The formula ids are bare numbers, so this orders numerically — and
        // SaveFormulas must order the same way, because the caller's row
        // order is the order the save walks.
        $query = PayrollFormula::query()->whereIn('key', FormulaPolicy::keys());
        SqlText::orderNumeric($query, 'code', 'asc');

        return $query->get()
            ->map(static fn (PayrollFormula $row): PayrollFormulaView => Snapshot::of($row, PayrollFormulaView::class));
    }
}
