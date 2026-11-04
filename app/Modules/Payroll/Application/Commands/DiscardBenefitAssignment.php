<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollEmployeeBenefit;
use App\Models\PayrollIncentiveType;
use App\Modules\Payroll\Application\BenefitAssignments;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Discards a benefit assignment still in DRAFT: it is deleted, the audit
 * row keeping what went. An approved assignment is never discarded — end
 * it with a date instead. All-data PAYROLL; RequiredAudit on the same
 * transaction.
 */
final class DiscardBenefitAssignment
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! BenefitAssignments::ready()) {
            throw new RuntimeException('Benefits can be discarded once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = PayrollEmployeeBenefit::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That assignment no longer exists.');
            }
            if ($row->status !== PayrollEmployeeBenefit::DRAFT) {
                throw new RuntimeException('An approved assignment is not discarded; end it with a date instead.');
            }
            $typeName = (string) (PayrollIncentiveType::query()->whereKey($row->type_id)->value('name') ?? 'A benefit');
            $span = PayrollPeriodText::day($row->starts_on->format('Y-m-d')).($row->ends_on === null ? ' onward' : ' to '.PayrollPeriodText::day($row->ends_on->format('Y-m-d')));
            if (! $row->delete()) {
                throw new RuntimeException('The draft could not be discarded.');
            }
            RequiredAudit::record($access, AuditEvent::BENEFIT_ASSIGNMENT_DISCARDED, 'payroll_employee_benefits', $id,
                "{$typeName} {$span} draft discarded for employee {$row->employee_id}");

            return "Draft discarded: {$typeName} {$span}.";
        });
    }
}
