<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PayRulesStore;
use App\Modules\Payroll\Application\PayRulesVersions;
use App\Modules\Payroll\Application\PolicyVersionDates;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prepares a Pay Basis & Rates version for ONE employee as a DRAFT: figures
 * of their own from a date, ahead of their Employment status's override and
 * the company rule — or, with no figures, the date from which they follow
 * those again (a "follows" version; a version is never deleted once
 * approved). It is approved (ApproveEmployeePayRules) before it prices
 * them; the employee's existing draft for that date is edited in
 * place. The same dates, reason and figures as every version
 * (PayRulesVersions); the date must follow every APPROVED version of
 * theirs and no finalized payroll of theirs may already reach it.
 *
 * An employee's rates are pay data: an all-data PAYROLL write, for any
 * employee including the preparer's own. Audited with RequiredAudit on
 * the same transaction.
 */
final class SaveEmployeePayRules
{
    /**
     * @param  array<string, mixed>|null  $figures  the version's figures in PayRules shape, or null to follow the general rules again
     */
    public function handle(Access $access, string $employeeId, string $effectiveFrom, string $reason, ?array $figures): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! PayRulesStore::approvalReady()) {
            throw new RuntimeException('Rates for one employee can be saved once this installation\'s upgrade finishes.');
        }
        $employee = app(EmployeePayContexts::class)->get($access, [$employeeId])[0] ?? null;
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $error = PayRulesVersions::dateError($effectiveFrom) ?? PayRulesVersions::reasonError($reason);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        if ($figures !== null) {
            $figures = PayRulesVersions::figures($figures);
        }
        $name = EmployeeName::display($employee);
        $day = PayrollPeriodText::day($effectiveFrom);

        DB::transaction(static function () use ($access, $employee, $name, $day, $effectiveFrom, $reason, $figures): void {
            $error = PolicyVersionDates::postedError($employee->id, $effectiveFrom, $name);
            if ($error !== null) {
                throw new RuntimeException($error);
            }
            $latest = PayRulesVersions::latestLocked($employee->id);
            if ($figures === null && ($latest === null || $latest->params === null)) {
                throw new RuntimeException("{$name} has no rates of their own to end.");
            }
            $row = PayRulesVersions::insertDraft($access, $employee->id, $effectiveFrom, $reason, $figures, $latest);

            RequiredAudit::record($access, AuditEvent::PAY_RULES_SAVED, 'payroll_pay_rules', $row->id, $figures === null
                ? "{$name} follows the employment status or company Pay Basis & Rates again from {$day}: draft (".trim($reason).')'
                : "Pay Basis & Rates draft from {$day} prepared for employee {$name} (".trim($reason).')');
        });

        return $figures === null
            ? "From {$day}, {$name} follows the general Pay Basis & Rates again — once approved."
            : "{$name}'s Pay Basis & Rates from {$day} saved as a draft. Approve it to take effect.";
    }
}
