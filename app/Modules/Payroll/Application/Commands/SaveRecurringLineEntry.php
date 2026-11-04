<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\PayrollDeduction;
use App\Models\PayrollRecurringLine;
use App\Models\PayrollRecurringLineEntry;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\RecurringLineEntries;
use App\Modules\Payroll\Application\RecurringLines;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records an outside payment or a balance adjustment on one of an
 * employee's loans — a recurring deduction line with a total — as a DRAFT
 * to approve (ApproveRecurringLineEntry). All-data PAYROLL, for any
 * employee including the actor's own; RequiredAudit on the same
 * transaction. The line's dates and amount per payroll are untouched: an
 * approved entry only changes what is left.
 */
final class SaveRecurringLineEntry
{
    public function handle(Access $access, string $lineId, string $kind, mixed $amount, string $entryDate, string $reason, ?string $reference = null): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! RecurringLineEntries::ready()) {
            throw new RuntimeException('Loan entries can be recorded once this installation\'s upgrade finishes.');
        }
        $line = PayrollRecurringLine::query()->whereKey($lineId)->first();
        if ($line === null) {
            throw new RuntimeException('That line no longer exists.');
        }
        $rule = RecurringLines::rule($line);
        if ($rule->kind !== RecurringLine::KIND_DEDUCTION || $rule->total === null) {
            throw new RuntimeException('Entries are recorded only on a deduction with a total to pay off.');
        }
        if (! array_key_exists($kind, PayrollRecurringLineEntry::KINDS)) {
            throw new RuntimeException('Choose whether this is a payment made outside payroll or a balance adjustment.');
        }
        if (is_string($amount)) {
            $amount = trim(str_replace(',', '', $amount));
        }
        if (! is_numeric($amount) || abs((float) $amount) > 99_999_999.99) {
            throw new RuntimeException('Enter the amount.');
        }
        $pesos = Money::round($amount);
        if ($kind === PayrollRecurringLineEntry::KIND_OUTSIDE_PAYMENT && $pesos <= 0.0) {
            throw new RuntimeException('Enter the payment as an amount above zero.');
        }
        if ($kind === PayrollRecurringLineEntry::KIND_ADJUSTMENT && $pesos === 0.0) {
            throw new RuntimeException('Enter the adjustment: above zero when more is owed, below zero when less.');
        }
        if (! PayrollPeriod::isPayrollDate($entryDate)) {
            throw new RuntimeException('Enter the date of the payment or statement.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 160) {
            throw new RuntimeException('Enter the reason for this entry, at most 160 characters.');
        }
        $reference = trim((string) $reference);
        if (mb_strlen($reference) > 120) {
            throw new RuntimeException('Keep the reference to 120 characters.');
        }
        $employee = app(EmployeePayContexts::class)->get($access, [$line->employee_id])[0] ?? null;
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $name = EmployeeName::display($employee);
        $lineName = (string) (PayrollDeduction::query()->whereKey($line->deduction_id)->value('name') ?? 'A deduction');
        $label = PayrollRecurringLineEntry::KINDS[$kind];
        $day = PayrollPeriodText::day($entryDate);

        return DB::transaction(static function () use ($access, $line, $kind, $pesos, $entryDate, $reason, $reference, $name, $lineName, $label, $day): string {
            $row = new PayrollRecurringLineEntry([
                'line_id' => $line->id, 'employee_id' => $line->employee_id, 'kind' => $kind, 'amount' => $pesos, 'entry_date' => $entryDate,
                'reason' => $reason, 'reference' => $reference === '' ? null : $reference, 'status' => PayrollRecurringLineEntry::DRAFT,
            ]);
            $row->prepared_by = $access->userId === null ? null : (int) $access->userId;
            $row->prepared_by_name = mb_substr($access->fullName, 0, 200);
            if (! $row->save()) {
                throw new RuntimeException('The entry could not be saved.');
            }
            RequiredAudit::record($access, AuditEvent::LOAN_ENTRY_RECORDED, 'payroll_recurring_line_entries', $row->id,
                "{$name} · {$lineName}: {$label} of {$day} recorded as a draft ({$reason})");

            return "{$label} on {$lineName} recorded for {$name}. The balance changes once it is approved.";
        });
    }
}
