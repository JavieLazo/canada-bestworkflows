<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollEmployeeBenefit;
use App\Models\PayrollIncentiveType;
use App\Modules\Payroll\Application\BenefitAssignments;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approves a benefit assignment prepared as a draft
 * (SaveBenefitAssignment): any all-data PAYROLL holder, the preparer
 * included, for any employee including themselves — one administrator
 * must be able to run the whole flow alone. From approval every draft
 * payroll inside the span proposes the benefit; finalized payrolls keep
 * what they paid. RequiredAudit on the same transaction.
 */
final class ApproveBenefitAssignment
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! BenefitAssignments::ready()) {
            throw new RuntimeException('Benefits can be approved once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            if (! PayrollEmployeeBenefit::query()->whereKey($id)->exists()) {
                throw new RuntimeException('That assignment no longer exists.');
            }
            PayrollData::lockMembership();
            $row = PayrollEmployeeBenefit::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That assignment no longer exists.');
            }
            if ($row->status !== PayrollEmployeeBenefit::DRAFT) {
                throw new RuntimeException('That assignment is already approved.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->employee_id])[0] ?? null;
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $row->status = PayrollEmployeeBenefit::APPROVED;
            $row->approved_by = $access->userId === null ? null : (int) $access->userId;
            $row->approved_by_name = mb_substr($access->fullName, 0, 200);
            $row->approved_at = CarbonImmutable::now('UTC');
            if (! $row->save()) {
                throw new RuntimeException('The assignment could not be approved.');
            }
            $name = EmployeeName::display($employee);
            $typeName = (string) (PayrollIncentiveType::query()->whereKey($row->type_id)->value('name') ?? 'A benefit');
            $span = PayrollPeriodText::day($row->starts_on->format('Y-m-d')).($row->ends_on === null ? ' onward' : ' to '.PayrollPeriodText::day($row->ends_on->format('Y-m-d')));
            RequiredAudit::record($access, AuditEvent::BENEFIT_ASSIGNMENT_APPROVED, 'payroll_employee_benefits', $row->id,
                "{$name} · {$typeName} {$span} approved (prepared by ".($row->prepared_by_name ?? 'an unnamed account').')');

            return "{$typeName} approved for {$name} {$span}.";
        });
    }
}
