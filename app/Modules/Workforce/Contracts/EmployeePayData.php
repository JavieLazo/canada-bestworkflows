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
 * No screen, hire wizard or CSV import writes them (the employee record has
 * no Payroll Settings section): a row keeps whatever it holds, and a new
 * hire's are the column defaults, i.e. the standard computation. An exception
 * is typed on the payroll draft instead.
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
    ) {}
}
