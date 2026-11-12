<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollRecurringLine;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\RecurringLines;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gives one recurring line its last date: no payroll period starting after
 * it proposes the line again. The way a line already paid on a payslip
 * stops before its total is paid off (a loan settled outside payroll, an
 * allowance withdrawn), and the line stays on record with what it paid.
 * It ONLY brings the last date earlier: a later date would bring a settled
 * line back into open payrolls without the overlap check a save runs, so
 * moving the date later is an edit of the line.
 *
 * Pay data: an all-data PAYROLL write, audited with RequiredAudit on the
 * same transaction with the reason.
 */
final class EndRecurringLine
{
    public function handle(Access $access, string $lineId, string $lastDay, string $reason): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! RecurringLines::ready()) {
            throw new RuntimeException('Loans and allowances can be set up once this installation\'s upgrade finishes.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > SaveRecurringLine::NOTE_MAX) {
            throw new RuntimeException('Enter the reason it ends, at most 160 characters.');
        }

        return DB::transaction(static function () use ($access, $lineId, $lastDay, $reason): string {
            $row = PayrollRecurringLine::query()->whereKey($lineId)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That loan or allowance no longer exists.');
            }
            $error = RecurringLine::error([...RecurringLines::figures($row), 'ends_on' => $lastDay]);
            if ($error === 'The last date cannot be before the start.') {
                throw new RuntimeException('It starts after that date. Delete it instead, if it was never paid.');
            }
            if ($error !== null) {
                throw new RuntimeException($error);
            }
            $current = $row->ends_on?->format('Y-m-d');
            if ($current !== null && $lastDay > $current) {
                throw new RuntimeException('It already ends after '.PayrollPeriodText::day($current).'. To move that date later, edit the line.');
            }
            // The date it already has: nothing changes, so nothing is audited.
            if ($current === $lastDay) {
                return 'That loan or allowance already ends after '.PayrollPeriodText::day($lastDay).'.';
            }
            $row->fill(['ends_on' => $lastDay])->save();

            $employee = app(EmployeePayContexts::class)->get($access, [$row->employee_id])[0] ?? null;
            $day = PayrollPeriodText::day($lastDay);
            RequiredAudit::record($access, AuditEvent::RECURRING_LINE_ENDED, 'payroll_recurring_lines', $row->id,
                EmployeeName::display($employee)." — recurring line ends after {$day} ({$reason})");

            return "The loan or allowance ends after {$day}.";
        });
    }
}
