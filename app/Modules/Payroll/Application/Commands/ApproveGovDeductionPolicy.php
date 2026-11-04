<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollGovDeductionPolicy;
use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PolicyVersionDates;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approves an employee's Contributions & tax draft: from its effective
 * date every draft payroll computes with it (and asks for a fresh review)
 * and it never changes. Any all-data PAYROLL holder, the preparer and the
 * employee themselves included — one administrator must be able to run
 * the whole flow alone. Lock order: payroll's membership lock — so a
 * finalize cannot slip between the posted-through check and the approval
 * — then the version. RequiredAudit on the same transaction.
 */
final class ApproveGovDeductionPolicy
{
    public function handle(Access $access, string $id): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! GovDeductionPolicyStore::ready()) {
            throw new RuntimeException('Contributions & tax versions can be approved once this installation\'s upgrade finishes.');
        }

        return DB::transaction(static function () use ($access, $id): string {
            if (! PayrollGovDeductionPolicy::query()->whereKey($id)->exists()) {
                throw new RuntimeException('That version no longer exists.');
            }
            PayrollData::lockMembership();
            $row = PayrollGovDeductionPolicy::query()->whereKey($id)->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('That version no longer exists.');
            }
            if ($row->status !== PayrollGovDeductionPolicy::DRAFT) {
                throw new RuntimeException('That version is already approved.');
            }
            $employee = app(EmployeePayContexts::class)->get($access, [$row->employee_id])[0] ?? null;
            if ($employee === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $name = EmployeeName::display($employee);
            $from = $row->effective_from->format('Y-m-d');
            $error = PolicyVersionDates::postedError($row->employee_id, $from, $name);
            if ($error !== null) {
                throw new RuntimeException($error);
            }
            $latest = PayrollGovDeductionPolicy::query()->where('employee_id', $row->employee_id)->where('status', PayrollGovDeductionPolicy::APPROVED)
                ->lockForUpdate()->orderByDesc('effective_from')->first();
            if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $from) {
                throw new RuntimeException('An approved version already takes effect on '.PayrollPeriodText::day($latest->effective_from->format('Y-m-d')).'. Discard this draft and prepare one from a later date.');
            }
            $settings = GovDeductionPolicyStore::settings($row);
            $row->status = PayrollGovDeductionPolicy::APPROVED;
            $row->approved_by = $access->userId === null ? null : (int) $access->userId;
            $row->approved_by_name = mb_substr($access->fullName, 0, 200);
            $row->approved_at = CarbonImmutable::now('UTC');
            if (! $row->save()) {
                throw new RuntimeException('The version could not be approved.');
            }
            $differs = array_map(static fn (string $key): string => GovDeductionSettings::LABELS[$key], $settings->customized());
            $day = PayrollPeriodText::day($from);
            RequiredAudit::record($access, AuditEvent::GOV_POLICY_APPROVED, 'payroll_gov_deduction_policies', $row->id,
                "{$name} · Contributions & tax version from {$day} approved (prepared by ".($row->prepared_by_name ?? 'an unnamed account').'): '.($differs === [] ? 'the standard computation' : implode(', ', $differs)));

            return "Contributions & tax version from {$day} approved for {$name}.";
        });
    }
}
