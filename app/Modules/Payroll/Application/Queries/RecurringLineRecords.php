<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Models\PayrollDeduction;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRecurringLine;
use App\Models\PayslipDeduction;
use App\Models\PayslipIncentive;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\RecurringLineLedger;
use App\Modules\Payroll\Application\RecurringLines;
use App\Modules\Payroll\Contracts\Views\RecurringLineView;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\CodeSort;
use Illuminate\Support\Collection;

/**
 * Employees' recurring lines, read for Employee Pay Settings: one
 * employee's lines with what each has paid and left, a summary per
 * employee for the list, and the catalog rows a line may charge. Pay data:
 * an all-data PAYROLL read.
 */
final class RecurringLineRecords
{
    public const string UPCOMING = 'UPCOMING';

    public const string ACTIVE = 'ACTIVE';

    public const string PAID_OFF = 'PAID_OFF';

    public const string ENDED = 'ENDED';

    /** @return Collection<int, RecurringLineView> newest start first */
    public function forEmployee(Access $actor, string $employeeId, string $today): Collection
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! RecurringLines::ready()) {
            return new Collection;
        }

        return $this->views($actor, PayrollRecurringLine::query()->where('employee_id', $employeeId)->orderByDesc('starts_on')->orderBy('id')->get(), $today);
    }

    /**
     * One employee's lines a payroll period starting on this day proposes
     * or counts: started, not ended, not paid off.
     *
     * @return Collection<int, RecurringLineView>
     */
    public function liveOn(Access $actor, string $employeeId, string $periodStart): Collection
    {
        return $this->forEmployee($actor, $employeeId, $periodStart)
            ->filter(static fn (RecurringLineView $line): bool => $line->status !== self::PAID_OFF
                && $line->starts_on->format('Y-m-d') <= $periodStart
                && ($line->ends_on === null || $line->ends_on->format('Y-m-d') >= $periodStart))
            ->values();
    }

    public function find(Access $actor, string $lineId, string $today): ?RecurringLineView
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! RecurringLines::ready()) {
            return null;
        }

        return $this->views($actor, PayrollRecurringLine::query()->whereKey($lineId)->get(), $today)->first();
    }

    /**
     * Each employee's lines that are upcoming or active today: how many,
     * and what their totals have left (null when none has a total).
     *
     * @return array<string, array{count: int, left: ?float}>
     */
    public function summaries(Access $actor, string $today): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! RecurringLines::ready()) {
            return [];
        }
        $summaries = [];
        $rows = PayrollRecurringLine::query()->where(static fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->get();
        foreach ($this->views($actor, $rows, $today) as $line) {
            if (! in_array($line->status, [self::UPCOMING, self::ACTIVE], true)) {
                continue;
            }
            $summary = $summaries[$line->employee_id] ?? ['count' => 0, 'left' => null];
            $summary['count']++;
            if ($line->left !== null) {
                $summary['left'] = ($summary['left'] ?? 0.0) + $line->left;
            }
            $summaries[$line->employee_id] = $summary;
        }

        return $summaries;
    }

    /**
     * Every employee's lines that are upcoming or active today — what the
     * Payroll Settings overview warns about (a balance left on someone who
     * has left, a loan the next payroll pays off). A pay data read: an
     * all-data PAYROLL holder's only.
     *
     * @return Collection<int, RecurringLineView>
     */
    public function open(Access $actor, string $today): Collection
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        if (! RecurringLines::ready()) {
            return new Collection;
        }
        $rows = PayrollRecurringLine::query()->where(static fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderBy('starts_on')->orderBy('id')->get();

        return $this->views($actor, $rows, $today)
            ->filter(static fn (RecurringLineView $line): bool => in_array($line->status, [self::UPCOMING, self::ACTIVE], true))
            ->values();
    }

    /**
     * The Payroll Deductions rows a recurring deduction may charge: the
     * typed ones, never a computed or a percentage row (they price
     * themselves).
     *
     * @return list<array{id: string, name: string}>
     */
    public function deductionOptions(Access $actor): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);
        $query = PayrollDeduction::query()->whereNull('calculation');
        if (PayrollData::percentDeductionsReady()) {
            $query->whereNull('percent');
        }

        return array_values($query->get()
            ->sort(static fn (PayrollDeduction $a, PayrollDeduction $b): int => CodeSort::compare($a->code, $b->code) ?: strcmp($a->id, $b->id))
            ->map(static fn (PayrollDeduction $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name])
            ->all());
    }

    /**
     * The Incentives & Bonuses types a recurring incentive may charge: the
     * ones the catalog does not schedule on its own.
     *
     * @return list<array{id: string, name: string}>
     */
    public function incentiveOptions(Access $actor): array
    {
        abort_unless($actor->mayReadAllData(ModuleCode::PAYROLL), 403);

        return array_values(PayrollIncentiveType::query()->get()
            ->filter(static fn (PayrollIncentiveType $type): bool => ! PayrollData::benefitRuleOf($type)->isScheduled())
            ->sort(static fn (PayrollIncentiveType $a, PayrollIncentiveType $b): int => CodeSort::compare($a->code, $b->code) ?: strcmp($a->id, $b->id))
            ->map(static fn (PayrollIncentiveType $type): array => ['id' => (string) $type->id, 'name' => (string) $type->name])
            ->all());
    }

    /**
     * @param  Collection<int, PayrollRecurringLine>  $rows
     * @return Collection<int, RecurringLineView>
     */
    private function views(Access $actor, Collection $rows, string $today): Collection
    {
        if ($rows->isEmpty()) {
            return new Collection;
        }
        $ids = array_values($rows->map(static fn (PayrollRecurringLine $row): string => (string) $row->id)->all());
        $paid = app(RecurringLineLedger::class)->paidBefore($actor, $ids);
        $posted = array_flip([
            ...PayslipDeduction::query()->whereIn('recurring_line_id', $ids)->distinct()->pluck('recurring_line_id')->all(),
            ...PayslipIncentive::query()->whereIn('recurring_line_id', $ids)->distinct()->pluck('recurring_line_id')->all(),
        ]);
        $deductions = PayrollDeduction::query()->whereIn('id', $rows->pluck('deduction_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $types = PayrollIncentiveType::query()->whereIn('id', $rows->pluck('incentive_type_id')->filter()->unique()->values()->all())->get()->keyBy('id');

        return $rows->map(static function (PayrollRecurringLine $row) use ($paid, $posted, $deductions, $types, $today): RecurringLineView {
            $rule = RecurringLines::rule($row);
            $type = $rule->kind === RecurringLine::KIND_DEDUCTION ? $deductions->get($row->deduction_id) : $types->get($row->incentive_type_id);
            $paidSoFar = $paid[$row->id] ?? 0.0;
            $status = match (true) {
                $rule->paidOff($paidSoFar) => self::PAID_OFF,
                $rule->endsOn !== null && $rule->endsOn < $today => self::ENDED,
                $rule->startsOn > $today => self::UPCOMING,
                default => self::ACTIVE,
            };

            return Snapshot::of($row, RecurringLineView::class, [
                'type_id' => (string) ($type->id ?? ''),
                'type_code' => (string) ($type->code ?? ''),
                'type_name' => (string) ($type->name ?? '—'),
                'paid' => $paidSoFar,
                'left' => $rule->left($paidSoFar),
                'posted' => isset($posted[$row->id]),
                'status' => $status,
            ]);
        })->values();
    }
}
