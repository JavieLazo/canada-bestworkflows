<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRunMemberIncentive;
use App\Modules\Payroll\Contracts\Views\IncentiveTypeView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\CodeSort;
use Illuminate\Support\Collection;

/**
 * The incentive and bonus catalog, read. Anyone who holds RECORDS or
 * PAYROLL may read it — the Records page lists it and the payroll workspace
 * offers it — while every write needs all-data in either (SaveIncentiveType,
 * DeleteIncentiveType). The catalog has no employee column, so there is no
 * row scope.
 */
final class IncentiveTypeRecords
{
    /** @return Collection<int, IncentiveTypeView> the whole catalog, by id (CodeSort) */
    public function list(Access $actor): Collection
    {
        abort_unless($actor->hasModule(ModuleCode::RECORDS, ModuleCode::PAYROLL), 403);

        return PayrollIncentiveType::query()->get()
            ->sort(static fn (PayrollIncentiveType $a, PayrollIncentiveType $b): int => CodeSort::compare($a->code, $b->code) ?: strcmp($a->id, $b->id))
            ->values()
            ->map(static fn (PayrollIncentiveType $row): IncentiveTypeView => Snapshot::of($row, IncentiveTypeView::class));
    }

    public function find(Access $actor, string $id): ?IncentiveTypeView
    {
        abort_unless($actor->hasModule(ModuleCode::RECORDS, ModuleCode::PAYROLL), 403);
        $row = PayrollIncentiveType::query()->find($id);

        return $row === null ? null : Snapshot::of($row, IncentiveTypeView::class);
    }

    /**
     * How many payroll lines name each type — draft or finalized, since the
     * type_id RESTRICT holds for both. The Delete guard and its badge read
     * this. Unscoped counts only: no employee, amount or run leaves here.
     *
     * @return array<string, int> type id => lines
     */
    public function usage(Access $actor): array
    {
        abort_unless($actor->hasModule(ModuleCode::RECORDS, ModuleCode::PAYROLL), 403);

        return PayrollRunMemberIncentive::query()
            // `line_count`, not `lines`: LINES is reserved on MariaDB.
            ->selectRaw('type_id, COUNT(*) AS line_count')
            ->groupBy('type_id')
            ->toBase()
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->type_id => (int) $row->line_count])
            ->all();
    }
}
