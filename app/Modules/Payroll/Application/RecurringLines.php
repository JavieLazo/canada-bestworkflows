<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollRecurringLine;
use App\Models\PayslipDeduction;
use App\Models\PayslipIncentive;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Recurring lines as the application reads them: whether they exist yet
 * (the window before the cron migrates 2026_10_30_0002 to _0004) and a
 * stored row as the pure RecurringLine reads it.
 */
final class RecurringLines
{
    private function __construct() {}

    /** Whether the recurring lines, their ledger column and the members' skips exist (the last migration of the three ran). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('payroll_run_members', 'skipped_recurring_lines'));
    }

    /**
     * A stored row's figures in RecurringLine's shape.
     *
     * @return array{kind: string, amount: float, total: ?float, frequency: string, starts_on: string, ends_on: ?string}
     */
    public static function figures(PayrollRecurringLine $row): array
    {
        return [
            'kind' => $row->kind,
            'amount' => (float) $row->amount,
            'total' => $row->total === null ? null : (float) $row->total,
            'frequency' => $row->frequency,
            'starts_on' => $row->starts_on->format('Y-m-d'),
            'ends_on' => $row->ends_on?->format('Y-m-d'),
        ];
    }

    /** Whether anything was posted against the line: it is history then, ended and never deleted. */
    public static function posted(string $lineId): bool
    {
        return PayslipDeduction::query()->where('recurring_line_id', $lineId)->exists()
            || PayslipIncentive::query()->where('recurring_line_id', $lineId)->exists();
    }

    /** A stored row's rule, strictly. */
    public static function rule(PayrollRecurringLine $row): RecurringLine
    {
        try {
            return RecurringLine::fromArray(self::figures($row));
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('A loan or allowance holds figures payroll cannot read: '.$e->getMessage());
        }
    }
}
