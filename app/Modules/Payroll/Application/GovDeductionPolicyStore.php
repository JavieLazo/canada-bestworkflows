<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollGovDeductionPolicy;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Workforce\Contracts\EmployeePayData;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Each employee's Contributions & tax policy as payroll reads it: the
 * APPROVED version in force on a date — the latest whose effective date is
 * on or before it; a regular payroll asks for its period's first day, the
 * 13th month run for 31 December — laid over the employee's pay context,
 * so the engine reads the version's switches off the employee exactly as
 * it read the profile columns. An employee with no approved version keeps
 * their profile columns: the BASELINE, which no screen edits. Drafts are
 * never in force. Before the table exists (the window before the cron
 * migrates 2026_11_02_0003) nobody has a version.
 */
final class GovDeductionPolicyStore
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_gov_deduction_policies'));
    }

    /**
     * The approved version in force on `$date` for each of these
     * employees, in one query; employees with none are absent.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, PayrollGovDeductionPolicy>
     */
    public static function forEmployees(array $employeeIds, string $date): array
    {
        if ($employeeIds === [] || ! self::ready()) {
            return [];
        }
        $out = [];
        $rows = PayrollGovDeductionPolicy::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', PayrollGovDeductionPolicy::APPROVED)
            ->where('effective_from', '<=', $date)
            ->orderBy('effective_from')->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $out[$row->employee_id] = $row;
        }

        return $out;
    }

    /** The settings a version holds, as the pure rules read them, strictly. */
    public static function settings(PayrollGovDeductionPolicy $row): GovDeductionSettings
    {
        try {
            return GovDeductionSettings::fromArray($row->params);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('A Contributions & tax version holds figures payroll cannot read: '.$e->getMessage());
        }
    }

    /**
     * The version's entry as the payroll evidence carries it.
     *
     * @return array{version_id: string, effective_from: string, figures: array<string, mixed>}
     */
    public static function entry(PayrollGovDeductionPolicy $row): array
    {
        return ['version_id' => (string) $row->id, 'effective_from' => $row->effective_from->format('Y-m-d'), 'figures' => self::settings($row)->toArray()];
    }

    /**
     * The employee's pay context with the version's sixteen statutory
     * settings in place of the profile columns': what the engine computes
     * from and what the evidence records for them.
     */
    public static function apply(EmployeePayData $e, GovDeductionSettings $settings): EmployeePayData
    {
        $v = $settings->statutory();

        return new EmployeePayData(
            $e->id, $e->employee_code, $e->first_name, $e->last_name, $e->suffix, $e->position, $e->photo_path,
            $e->date_hired, $e->date_resigned, $e->monthly_rate, $e->daily_rate, $e->hourly_rate, $e->is_active,
            $e->employee_id_no,
            allow_sss: (bool) $v['allow_sss'],
            allow_philhealth: (bool) $v['allow_philhealth'],
            allow_pagibig: (bool) $v['allow_pagibig'],
            allow_withholding_tax: (bool) $v['allow_withholding_tax'],
            annualize_tax: (bool) $v['annualize_tax'],
            fixed_tax: (float) $v['fixed_tax'],
            percent_tax: (float) $v['percent_tax'],
            fixed_sss_ee: (float) $v['fixed_sss_ee'],
            fixed_sss_er: (float) $v['fixed_sss_er'],
            fixed_philhealth_ee: (float) $v['fixed_philhealth_ee'],
            fixed_philhealth_er: (float) $v['fixed_philhealth_er'],
            fixed_pagibig_ee: (float) $v['fixed_pagibig_ee'],
            fixed_pagibig_er: (float) $v['fixed_pagibig_er'],
            sss_mode: (string) $v['sss_mode'],
            philhealth_mode: (string) $v['philhealth_mode'],
            pagibig_mode: (string) $v['pagibig_mode'],
            employment_status: $e->employment_status,
            employment_status_id: $e->employment_status_id,
            salary_rates: $e->salary_rates,
            whole_year_tax: (bool) $v['whole_year_tax'],
            tax_spread: (string) $v['tax_spread'],
        );
    }

    /**
     * Each employee's pay context with their version in force laid over it,
     * and each version's evidence entry, in one query.
     *
     * @param  list<EmployeePayData>  $employees
     * @return array{0: list<EmployeePayData>, 1: array<string, array{version_id: string, effective_from: string, figures: array<string, mixed>}>}
     */
    public static function overlay(array $employees, string $date): array
    {
        $rows = self::forEmployees(array_map(static fn (EmployeePayData $e): string => $e->id, $employees), $date);
        if ($rows === []) {
            return [$employees, []];
        }
        $entries = [];
        $out = [];
        foreach ($employees as $employee) {
            $row = $rows[$employee->id] ?? null;
            if ($row === null) {
                $out[] = $employee;

                continue;
            }
            $entries[$employee->id] = self::entry($row);
            $out[] = self::apply($employee, self::settings($row));
        }

        return [$out, $entries];
    }
}
