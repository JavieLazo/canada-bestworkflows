<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollDeduction;
use App\Models\PayrollRecurringLine;
use App\Models\PayrollRecurringLineEntry;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\RecurringLineEntries;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approves a drafted loan entry: from then on it counts toward what the
 * line has paid, every draft payroll re-reads what is left, and finalized
 * payslips keep what they collected. Any all-data PAYROLL holder, the
 * preparer and the employee's own account included — one administrator
 * must be able to run the whole flow alone; the row is taken under
 * payroll's membership lock so no payroll prices the line while it flips;
 * RequiredAudit on the same transaction.
 */
final class ApproveRecurringLineEntry
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! RecurringLineEntries::ready()) {
            throw new RuntimeException('Loan entries can be approved once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            if (! PayrollRecurringLineEntry::query()->whereKey($id)->exists()) {
                throw new RuntimeException('That entry no longer exists.');
            }
            PayrollData::lockMembership();
            $row = PayrollRecurringLineEntry::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That entry no longer exists.');
            }
            if ($row->status !== PayrollRecurringLineEntry::DRAFT) {
                throw new RuntimeException('That entry is already approved.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->employee_id])[0] ?? null;
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $row->status = PayrollRecurringLineEntry::APPROVED;
            $row->approved_by = $access->userId === null ? null : (int) $access->userId;
            $row->approved_by_name = mb_substr($access->fullName, 0, 200);
            $row->approved_at = CarbonImmutable::now('UTC');
            if (! $row->save()) {
                throw new RuntimeException('The entry could not be approved.');
            }
            $name = EmployeeName::display($employee);
            $deductionId = PayrollRecurringLine::query()->whereKey($row->line_id)->value('deduction_id');
            $lineName = (string) (PayrollDeduction::query()->whereKey($deductionId)->value('name') ?? 'A deduction');
            $label = PayrollRecurringLineEntry::KINDS[$row->kind] ?? $row->kind;
            $day = PayrollPeriodText::day($row->entry_date->format('Y-m-d'));
            RequiredAudit::record($access, AuditEvent::LOAN_ENTRY_APPROVED, 'payroll_recurring_line_entries', $row->id,
                "{$name} · {$lineName}: {$label} of {$day} approved (prepared by ".($row->prepared_by_name ?? 'an unnamed account').')');

            return "{$label} on {$lineName} approved for {$name}.";
        });
    }
}
