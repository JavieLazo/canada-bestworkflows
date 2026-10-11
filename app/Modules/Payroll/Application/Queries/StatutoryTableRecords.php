<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Payroll\StatutorySchedule;
use App\Models\PayrollStatutoryTable;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Contracts\Views\StatutoryTableView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * The Contribution & Tax Tables page's reads: one table's versions, newest
 * first, and the one version in force today. Readable by any signed-in
 * account (the page sits behind the RECORDS gate); writes go through the
 * commands, which check all-data.
 */
final class StatutoryTableRecords
{
    /** @return Collection<int, StatutoryTableView> */
    public function versions(Access $actor, string $kind): Collection
    {
        abort_if($actor->userId === null, 403);
        if (! StatutorySchedule::isKind($kind) || ! StatutoryTables::ready()) {
            return new Collection;
        }

        return PayrollStatutoryTable::query()->where('kind', $kind)->orderByDesc('effective_from')->get()
            ->map(static fn (PayrollStatutoryTable $row): StatutoryTableView => Snapshot::of($row, StatutoryTableView::class));
    }

    public function find(Access $actor, string $id): ?StatutoryTableView
    {
        abort_if($actor->userId === null, 403);
        if (! StatutoryTables::ready()) {
            return null;
        }
        $row = PayrollStatutoryTable::query()->find($id);

        return $row === null ? null : Snapshot::of($row, StatutoryTableView::class);
    }

    /** The kind's latest version — the one new figures build on. */
    public function latest(Access $actor, string $kind): ?StatutoryTableView
    {
        return $this->versions($actor, $kind)->first();
    }
}
