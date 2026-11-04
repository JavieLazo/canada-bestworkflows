<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollRecurringLine;
use App\Modules\Payroll\Application\RecurringLines;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes a recurring line nothing was ever posted against — one added by
 * mistake. A line paid on any payslip is history: it is ended
 * (EndRecurringLine), and the foreign keys from the posted lines RESTRICT
 * the delete as a backstop. A draft payroll that skipped the line simply
 * no longer has it to skip.
 *
 * Pay data: an all-data PAYROLL write, audited with RequiredAudit on the
 * same transaction.
 */
final class DeleteRecurringLine
{
    public function handle(Access $access, string $lineId): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! RecurringLines::ready()) {
            throw new RuntimeException('Recurring lines can be set up once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $lineId): string {
            $row = PayrollRecurringLine::query()->whereKey($lineId)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That recurring line no longer exists.');
            }
            if (RecurringLines::posted($row->id)) {
                throw new RuntimeException('This line was already paid on a payslip, so it stays on record. End it instead.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->employee_id])[0] ?? null;
            $row->delete();
            RequiredAudit::record($access, AuditEvent::RECURRING_LINE_DELETED, 'payroll_recurring_lines', $row->id,
                EmployeeName::display($employee).' — recurring line deleted');

            return 'Recurring line deleted.';
        });
    }
}
