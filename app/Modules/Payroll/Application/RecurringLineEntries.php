<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollRecurringLineEntry;
use App\Support\Money;
use Illuminate\Support\Facades\Schema;

/**
 * A loan's ledger entries beside the payslips (PayrollRecurringLineEntry):
 * what the APPROVED entries add to what a recurring deduction line has
 * paid — an outside payment counts as paid, an adjustment of the balance
 * counts the other way (more owed = less paid). Drafts never count.
 */
final class RecurringLineEntries
{
    private function __construct() {}

    /** Whether the table exists yet (the window before the cron migrates 2026_11_02_0013). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_recurring_line_entries'));
    }

    /**
     * @param  list<string>  $lineIds
     * @return array<string, float> line id => pesos the approved entries count as paid (negative when adjustments raised the balance); lines with none absent
     */
    public static function paid(array $lineIds): array
    {
        if ($lineIds === [] || ! self::ready()) {
            return [];
        }
        $out = [];
        $rows = PayrollRecurringLineEntry::query()->whereIn('line_id', $lineIds)->where('status', PayrollRecurringLineEntry::APPROVED)->get(['line_id', 'kind', 'amount']);
        foreach ($rows as $row) {
            $signed = $row->kind === PayrollRecurringLineEntry::KIND_OUTSIDE_PAYMENT ? $row->amount : -$row->amount;
            $out[$row->line_id] = Money::round(($out[$row->line_id] ?? 0.0) + $signed);
        }

        return $out;
    }
}
