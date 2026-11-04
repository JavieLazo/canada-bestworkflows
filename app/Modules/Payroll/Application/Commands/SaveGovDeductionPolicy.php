<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollDeduction;
use App\Models\PayrollGovDeductionPolicy;
use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Application\PayrollPeriodText;
use App\Modules\Payroll\Application\PayRulesVersions;
use App\Modules\Payroll\Application\PolicyVersionDates;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Saves one employee's Contributions & tax version as a DRAFT, from a
 * date: a new one, or the employee's existing draft for that date edited
 * in place. All-data PAYROLL, for any employee including the preparer's
 * own. The figures are read strictly (GovDeductionSettings), a rate of
 * their own only on a percentage Payroll Deductions row that exists. An
 * approved version never changes: a draft on an approved version's date
 * is refused — prepare a later date — as is a date a finalized payroll of
 * theirs already reaches. RequiredAudit on the same transaction, naming
 * the settings that differ from the standard computation, never amounts.
 */
final class SaveGovDeductionPolicy
{
    /**
     * @param  array<string, mixed>  $figures  the version's figures in GovDeductionSettings shape
     * @return string the version's id
     */
    public function handle(Access $access, string $employeeId, string $effectiveFrom, string $reason, array $figures): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! GovDeductionPolicyStore::ready()) {
            throw new RuntimeException('Contributions & tax versions can be prepared once this installation\'s upgrade finishes.');
        }
        $employee = app(EmployeePayContexts::class)->get($access, [$employeeId])[0] ?? null;
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $effectiveFrom = trim($effectiveFrom);
        $error = PolicyVersionDates::dateError($effectiveFrom) ?? PayRulesVersions::reasonError($reason);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        try {
            $settings = GovDeductionSettings::fromArray($figures);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        self::refuseUnknownRows($settings);
        $name = EmployeeName::display($employee);
        $day = PayrollPeriodText::day($effectiveFrom);

        return DB::transaction(static function () use ($access, $employee, $name, $day, $effectiveFrom, $reason, $settings): string {
            $error = PolicyVersionDates::postedError($employee->id, $effectiveFrom, $name);
            if ($error !== null) {
                throw new RuntimeException($error);
            }
            $latest = PayrollGovDeductionPolicy::query()->where('employee_id', $employee->id)->where('status', PayrollGovDeductionPolicy::APPROVED)
                ->lockForUpdate()->orderByDesc('effective_from')->first();
            if ($latest !== null && $latest->effective_from->format('Y-m-d') >= $effectiveFrom) {
                throw new RuntimeException('An approved version already takes effect on '.PayrollPeriodText::day($latest->effective_from->format('Y-m-d')).' and never changes. Prepare one from a later date.');
            }
            $row = PayrollGovDeductionPolicy::query()->where('employee_id', $employee->id)->where('effective_from', $effectiveFrom)->lockForUpdate()->first();
            $row ??= new PayrollGovDeductionPolicy(['employee_id' => $employee->id, 'effective_from' => $effectiveFrom]);
            $row->fill(['params' => $settings->toArray(), 'reason' => trim($reason), 'status' => PayrollGovDeductionPolicy::DRAFT]);
            $row->prepared_by = $access->userId === null ? null : (int) $access->userId;
            $row->prepared_by_name = mb_substr($access->fullName, 0, 200);
            if (! $row->save()) {
                throw new RuntimeException('The version could not be saved.');
            }
            $differs = array_map(static fn (string $key): string => GovDeductionSettings::LABELS[$key], $settings->customized());
            RequiredAudit::record($access, AuditEvent::GOV_POLICY_SAVED, 'payroll_gov_deduction_policies', $row->id,
                "{$name} · Contributions & tax draft from {$day}: ".($differs === [] ? 'the standard computation' : implode(', ', $differs)).' ('.trim($reason).')');

            return $row->id;
        });
    }

    /** A rate of their own names a percentage row of the Payroll Deductions list as it stands. */
    private static function refuseUnknownRows(GovDeductionSettings $settings): void
    {
        $rowIds = array_keys($settings->percentRates);
        if ($rowIds === []) {
            return;
        }
        $known = PayrollDeduction::query()->whereIn('id', $rowIds)->whereNull('calculation')->whereNotNull('percent')->pluck('id')->all();
        if (array_diff($rowIds, array_map('strval', $known)) !== []) {
            throw new RuntimeException('A rate names a percentage deduction that does not exist.');
        }
    }
}
