<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use Carbon\CarbonImmutable;

/**
 * Current pay context; deliberately not an effective-dated historical snapshot.
 *
 * `employee_id_no` is DISPLAY-ONLY. The payroll grid names a draft row's
 * employee from this shape, and the identifier beside a name is the
 * company's own Employee ID, so it has to travel with the row or every draft
 * line would show nothing while the finalized lines (read through
 * OperationalDirectory) showed the number. It is deliberately ABSENT from the
 * payroll evidence payload (PayrollInputs::toArray()): no calculation reads
 * it, and adding a label to that payload would stale every outstanding
 * preview for nothing.
 *
 * THE SIXTEEN STATUTORY FIELDS AFTER IT ARE THE OPPOSITE: calculation inputs.
 * PayslipCalculator computes SSS, PhilHealth, Pag-IBIG and withholding
 * (payroll.ts:583-660), reading these off the employee STRUCTURALLY — which
 * is why they keep the employee_profiles column names in snake_case. They
 * MUST therefore enter the payroll evidence, and PayrollInputs captures and
 * restores every one of them; a field left out there would let a flipped
 * switch go unseen by the preview's fingerprint.
 * They are trailing and defaulted to an ordinary employee: every switch on
 * except annualize_tax, no fixed figure, and null modes — which the engine
 * treats exactly like the columns' 'SEMI_MONTHLY' default (a null or
 * SEMI_MONTHLY mode both halve the monthly figure). A reflection test
 * (PayrollStatutoryTest) fails if one goes missing, because the engine reads
 * a missing property as "switched off" and would silently post zero.
 *
 * The employee record, the hire wizard and the CSV import carry none of them
 * (the record has no Payroll Settings section): a new hire's are the column
 * defaults, i.e. the standard computation. The columns are the BASELINE:
 * Payroll lays the employee's approved Contributions & tax version in force
 * over this shape before computing (GovDeductionPolicyStore::apply), and a
 * payroll officer changes them by preparing a version on the employee's pay
 * page (SaveGovDeductionPolicy). A one-payroll exception is typed on the
 * draft.
 *
 * `daily_rate` (Rate Per Day) is a calculation input too, and deliberately
 * NOT defaulted: above zero it is the pay per day, otherwise the engine
 * derives the day from `monthly_rate` (PayslipCalculator, divergence 10).
 * NULL is "no Rate Per Day entered".
 */
final readonly class EmployeePayData
{
    public function __construct(
        public string $id,
        public string $employee_code,
        public string $first_name,
        public string $last_name,
        public ?string $suffix,
        public ?string $position,
        public ?string $photo_path,
        public CarbonImmutable $date_hired,
        public ?CarbonImmutable $date_resigned,
        public ?float $monthly_rate,
        public ?float $daily_rate,
        public ?float $hourly_rate,
        public bool $is_active,
        public ?string $employee_id_no = null,
        public bool $allow_sss = true,
        public bool $allow_philhealth = true,
        public bool $allow_pagibig = true,
        public bool $allow_withholding_tax = true,
        public bool $annualize_tax = false,
        public float $fixed_tax = 0.0,
        public float $percent_tax = 0.0,
        public float $fixed_sss_ee = 0.0,
        public float $fixed_sss_er = 0.0,
        public float $fixed_philhealth_ee = 0.0,
        public float $fixed_philhealth_er = 0.0,
        public float $fixed_pagibig_ee = 0.0,
        public float $fixed_pagibig_er = 0.0,
        public ?string $sss_mode = null,
        public ?string $philhealth_mode = null,
        public ?string $pagibig_mode = null,
        /** The employment status name the record holds, and the Employment list entry it names (null when none matches). Payroll reads the per-status Pay Basis & Rates override by the id. */
        public ?string $employment_status = null,
        public ?string $employment_status_id = null,
        /**
         * The salary versions taking over INSIDE the period being priced,
         * ascending by date (divergence 18): from that date the engine
         * prices the day from these rates instead of `monthly_rate` /
         * `daily_rate`, which are the rates in force on the period's first
         * day. Empty — the reference's shape — when one salary covers the
         * whole period.
         *
         * @var list<array{from: string, monthly_rate: ?float, daily_rate: ?float}>
         */
        public array $salary_rates = [],
        /** The whole-year withholding estimate (Payroll's divergence 20) and how its remainder is spread; off and null on the reference's methods. */
        public bool $whole_year_tax = false,
        public ?string $tax_spread = null,
    ) {}

    /**
     * The same employee with the salary in force on the period's first day
     * and the versions taking over inside it.
     *
     * @param  list<array{from: string, monthly_rate: ?float, daily_rate: ?float}>  $later
     */
    public function withSalary(?float $monthly, ?float $daily, array $later): self
    {
        return new self(
            $this->id, $this->employee_code, $this->first_name, $this->last_name, $this->suffix, $this->position, $this->photo_path,
            $this->date_hired, $this->date_resigned, $monthly, $daily, $this->hourly_rate, $this->is_active,
            $this->employee_id_no,
            allow_sss: $this->allow_sss,
            allow_philhealth: $this->allow_philhealth,
            allow_pagibig: $this->allow_pagibig,
            allow_withholding_tax: $this->allow_withholding_tax,
            annualize_tax: $this->annualize_tax,
            fixed_tax: $this->fixed_tax,
            percent_tax: $this->percent_tax,
            fixed_sss_ee: $this->fixed_sss_ee,
            fixed_sss_er: $this->fixed_sss_er,
            fixed_philhealth_ee: $this->fixed_philhealth_ee,
            fixed_philhealth_er: $this->fixed_philhealth_er,
            fixed_pagibig_ee: $this->fixed_pagibig_ee,
            fixed_pagibig_er: $this->fixed_pagibig_er,
            sss_mode: $this->sss_mode,
            philhealth_mode: $this->philhealth_mode,
            pagibig_mode: $this->pagibig_mode,
            employment_status: $this->employment_status,
            employment_status_id: $this->employment_status_id,
            salary_rates: $later,
            whole_year_tax: $this->whole_year_tax,
            tax_spread: $this->tax_spread,
        );
    }
}
