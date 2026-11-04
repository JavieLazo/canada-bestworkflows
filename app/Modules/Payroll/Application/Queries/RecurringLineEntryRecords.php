<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollDeduction;
use App\Models\PayrollRecurringLine;
use App\Models\PayrollRecurringLineEntry;
use App\Modules\Payroll\Application\RecurringLineEntries;
use App\Modules\Payroll\Contracts\Views\RecurringLineEntryView;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * One employee's loan ledger entries as the pay page lists them, newest
 * first, each with its line's catalog name. All-data PAYROLL only.
 */
final class RecurringLineEntryRecords
{
    /** The two kinds of entry, with their names on screen (PayrollRecurringLineEntry::KINDS). */
    public const array KINDS = PayrollRecurringLineEntry::KINDS;

    /** @return Collection<int, RecurringLineEntryView> */
    public function forEmployee(Access $actor, string $employeeId): Collection
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! RecurringLineEntries::ready()) {
            return new Collection;
        }
        $rows = PayrollRecurringLineEntry::query()->where('employee_id', $employeeId)->orderByDesc('entry_date')->orderByDesc('id')->get();
        if ($rows->isEmpty()) {
            return new Collection;
        }
        $lines = PayrollRecurringLine::query()->whereIn('id', $rows->pluck('line_id')->unique()->all())->get()->keyBy('id');
        $names = PayrollDeduction::query()->whereIn('id', $lines->pluck('deduction_id')->filter()->unique()->all())->pluck('name', 'id');

        return $rows->map(static function (PayrollRecurringLineEntry $row) use ($lines, $names): RecurringLineEntryView {
            $line = $lines->get($row->line_id);
            $name = $line === null ? 'A line no longer listed' : (string) ($names[$line->deduction_id] ?? 'A deduction no longer listed');

            return Snapshot::of($row, RecurringLineEntryView::class, ['line_name' => $name.($line?->note ? ' · '.$line->note : '')]);
        });
    }
}
