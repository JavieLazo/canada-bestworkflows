<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollGovDeductionPolicy;
use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Discards an employee's Contributions & tax DRAFT: it is deleted, the
 * audit row keeping what went. An approved version is never discarded.
 * All-data PAYROLL — the preparer or anyone else who manages payroll.
 */
final class DiscardGovDeductionPolicy
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! GovDeductionPolicyStore::ready()) {
            throw new RuntimeException('Contributions & tax versions can be discarded once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            $row = PayrollGovDeductionPolicy::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That version no longer exists.');
            }
            if ($row->status !== PayrollGovDeductionPolicy::DRAFT) {
                throw new RuntimeException('An approved version cannot be discarded.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->employee_id])[0] ?? null;
            $name = $employee === null ? 'a removed employee' : EmployeeName::display($employee);
            $day = PayrollPeriodText::day($row->effective_from->format('Y-m-d'));
            $reason = $row->reason;
            if (! $row->delete()) {
                throw new RuntimeException('The draft could not be discarded.');
            }
            RequiredAudit::record($access, AuditEvent::GOV_POLICY_DISCARDED, 'payroll_gov_deduction_policies', $id,
                "{$name} · Contributions & tax draft from {$day} discarded ({$reason})");

            return "Draft from {$day} discarded.";
        });
    }
}
