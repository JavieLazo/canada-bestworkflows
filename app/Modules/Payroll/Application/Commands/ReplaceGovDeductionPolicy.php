<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\Statutory;
use App\Models\PayrollDeduction;
use App\Models\PayrollGovDeductionPolicy;
use App\Modules\Payroll\Application\GovDeductionPolicyStore;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sets how SSS, PhilHealth, Pag-IBIG and withholding tax are deducted for
 * one employee. AN EMPLOYEE HAS ONE SET OF THESE SETTINGS, NOT A LIST OF
 * DATED VERSIONS: the settings replace the ones in use and take effect at
 * once for every draft payroll (which recomputes on its next preview and
 * asks for a fresh review); no effective date, reason or approval is asked.
 * A finalized payroll keeps the settings its evidence captured, so nothing
 * already paid moves. What the settings were is kept in the audit trail
 * alone: the row the change is written on stays, every other row of the
 * employee is removed, and the kept row covers every date they covered.
 *
 * Pay data: an all-data PAYROLL write, for any employee including the
 * actor's own. RequiredAudit on the same transaction, after payroll's
 * membership lock, naming the settings that differ from the standard
 * computation, never amounts.
 */
final class ReplaceGovDeductionPolicy
{
    /**
     * @param  array<string, mixed>  $figures  the settings in GovDeductionSettings shape
     */
    public function handle(Access $access, string $employeeId, array $figures): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! GovDeductionPolicyStore::ready()) {
            throw new RuntimeException('Contributions & tax can be changed once this installation\'s upgrade finishes.');
        }
        $employee = app(EmployeePayContexts::class)->get($access, [$employeeId])[0] ?? null;
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        try {
            $settings = GovDeductionSettings::fromArray($figures);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage());
        }
        // A rate of their own names a percentage row of the Payroll Deductions list as it stands.
        $rowIds = array_keys($settings->percentRates);
        if ($rowIds !== []) {
            $known = PayrollDeduction::query()->whereIn('id', $rowIds)->whereNull('calculation')->whereNotNull('percent')->pluck('id')->all();
            if (array_diff($rowIds, array_map('strval', $known)) !== []) {
                throw new RuntimeException('A rate names a percentage deduction that does not exist.');
            }
        }
        $name = EmployeeName::display($employee);
        $today = ManilaClock::todayKey();

        return DB::transaction(static function () use ($access, $employee, $name, $settings, $today): string {
            PayrollData::lockMembership();
            $rows = PayrollGovDeductionPolicy::query()->where('employee_id', $employee->id)->lockForUpdate()->orderBy('effective_from')->orderBy('id')->get();
            // The row in use today, else the earliest: the one the change is written on.
            $kept = $rows->last(static fn (PayrollGovDeductionPolicy $row): bool => $row->status === PayrollGovDeductionPolicy::APPROVED
                && $row->effective_from->format('Y-m-d') <= $today) ?? $rows->first();

            if ($kept === null) {
                // A first set covers every date payroll can run for, so no draft period falls before it.
                $kept = new PayrollGovDeductionPolicy(['employee_id' => $employee->id, 'effective_from' => Statutory::EFFECTIVE_FROM]);
            } else {
                // One row covers every date the list covered.
                $kept->effective_from = $rows->first()->effective_from;
                PayrollGovDeductionPolicy::query()->where('employee_id', $employee->id)->whereKeyNot($kept->id)->delete();
            }
            $kept->fill(['params' => $settings->toArray(), 'reason' => 'Set in Payroll Settings', 'status' => PayrollGovDeductionPolicy::APPROVED]);
            $kept->prepared_by = $access->userId === null ? null : (int) $access->userId;
            $kept->prepared_by_name = mb_substr($access->fullName, 0, 200);
            $kept->approved_by = $kept->prepared_by;
            $kept->approved_by_name = $kept->prepared_by_name;
            $kept->approved_at = CarbonImmutable::now('UTC');
            if (! $kept->save()) {
                throw new RuntimeException('The settings could not be saved.');
            }
            $differs = array_map(static fn (string $key): string => GovDeductionSettings::LABELS[$key], $settings->customized());
            RequiredAudit::record($access, AuditEvent::GOV_POLICY_SAVED, 'payroll_gov_deduction_policies', $kept->id,
                "{$name} · Contributions & tax replaced: ".($differs === [] ? 'the standard computation' : implode(', ', $differs)));

            return "Contributions & tax saved for {$name}. Draft payrolls now use them.";
        });
    }
}
