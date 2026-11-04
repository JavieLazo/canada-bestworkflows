<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollRecurringLineEntry;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\RecurringLineEntries;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Discards a loan entry still in DRAFT: it is deleted, the audit row
 * keeping what went. An approved entry is never discarded — it is pay
 * history a payroll may already have read. All-data PAYROLL;
 * RequiredAudit on the same transaction.
 */
final class DiscardRecurringLineEntry
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! RecurringLineEntries::ready()) {
            throw new RuntimeException('Loan entries can be discarded once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = PayrollRecurringLineEntry::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That entry no longer exists.');
            }
            if ($row->status !== PayrollRecurringLineEntry::DRAFT) {
                throw new RuntimeException('An approved entry is not discarded.');
            }
            $label = PayrollRecurringLineEntry::KINDS[$row->kind] ?? $row->kind;
            $day = PayrollPeriodText::day($row->entry_date->format('Y-m-d'));
            $lineId = $row->line_id;
            if (! $row->delete()) {
                throw new RuntimeException('The entry could not be discarded.');
            }
            RequiredAudit::record($access, AuditEvent::LOAN_ENTRY_DISCARDED, 'payroll_recurring_line_entries', $id,
                "{$label} of {$day} discarded as a draft (line {$lineId})");

            return "Draft discarded: {$label} of {$day}.";
        });
    }
}
