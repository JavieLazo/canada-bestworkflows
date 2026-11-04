<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\PayrollEmployeeBenefit;
use App\Models\PayrollIncentiveType;
use App\Modules\Payroll\Application\BenefitAssignments;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Assigns a scheduled Incentives & Bonuses type to one employee for a
 * span of dates (`payroll_employee_benefits`), as a DRAFT to approve
 * (ApproveBenefitAssignment): all-data PAYROLL, for any employee including
 * one's own record; a type with a schedule (the catalog's rule proposes it);
 * a start date, an optional end on or after it, an amount of their own
 * above zero or blank for the type's rule, a reason and an optional
 * reference. A draft prices nobody. RequiredAudit on the same
 * transaction.
 */
final class SaveBenefitAssignment
{
    public function handle(Access $access, string $employeeId, string $typeId, string $startsOn, ?string $endsOn, mixed $amount, string $reason, ?string $reference = null): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! BenefitAssignments::ready()) {
            throw new RuntimeException('Benefits can be assigned once this installation\'s upgrade finishes.');
        }
        if (! PayrollPeriod::isPayrollDate($startsOn)) {
            throw new RuntimeException('Enter the date the benefit starts.');
        }
        $endsOn = $endsOn === null || trim($endsOn) === '' ? null : trim($endsOn);
        if ($endsOn !== null && (! PayrollPeriod::isPayrollDate($endsOn) || $endsOn < $startsOn)) {
            throw new RuntimeException('The end date must be on or after the start date, or blank.');
        }
        if (is_string($amount)) {
            $amount = trim(str_replace(',', '', $amount));
        }
        $pesos = null;
        if ($amount !== null && $amount !== '') {
            if (! is_numeric($amount) || (float) $amount <= 0.0 || (float) $amount > 99_999_999.99) {
                throw new RuntimeException('Enter the amount above zero, or leave it blank for the type\'s own rule.');
            }
            $pesos = Money::round($amount);
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 160) {
            throw new RuntimeException('Enter the reason for the assignment, at most 160 characters.');
        }
        $reference = trim((string) $reference);
        if (mb_strlen($reference) > 120) {
            throw new RuntimeException('Keep the reference to 120 characters.');
        }
        $employee = app(EmployeePayContexts::class)->get($access, [$employeeId])[0] ?? null;
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $type = PayrollIncentiveType::query()->whereKey($typeId)->first();
        if ($type === null) {
            throw new RuntimeException('Choose a benefit from Incentives & Bonuses.');
        }
        try {
            if (! PayrollData::benefitRuleOf($type)->isScheduled()) {
                throw new RuntimeException("{$type->name} is typed per payroll; only a benefit with a schedule can be assigned.");
            }
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException("{$type->name} has a rule payroll cannot read: ".$e->getMessage());
        }
        $name = EmployeeName::display($employee);
        $span = PayrollPeriodText::day($startsOn).($endsOn === null ? ' onward' : ' to '.PayrollPeriodText::day($endsOn));

        return DB::transaction(static function () use ($access, $employeeId, $type, $startsOn, $endsOn, $pesos, $reason, $reference, $name, $span): string {
            $row = new PayrollEmployeeBenefit([
                'employee_id' => $employeeId, 'type_id' => $type->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
                'amount' => $pesos, 'reason' => $reason, 'reference' => $reference === '' ? null : $reference, 'status' => PayrollEmployeeBenefit::DRAFT,
            ]);
            $row->prepared_by = $access->userId === null ? null : (int) $access->userId;
            $row->prepared_by_name = mb_substr($access->fullName, 0, 200);
            if (! $row->save()) {
                throw new RuntimeException('The assignment could not be saved.');
            }
            RequiredAudit::record($access, AuditEvent::BENEFIT_ASSIGNED, 'payroll_employee_benefits', $row->id,
                "{$name} · {$type->name} assigned {$span} as a draft".($pesos === null ? '' : ' at an amount of their own')." ({$reason})");

            return "{$type->name} assigned to {$name} {$span}. It is paid once approved.";
        });
    }
}
