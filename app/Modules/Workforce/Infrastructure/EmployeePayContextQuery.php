<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Support\Access;

/** Keeps employee persistence and salary access inside Workforce. */
final class EmployeePayContextQuery implements EmployeePayContexts
{
    public function get(Access $actor, array $ids): array
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL), 403);
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            abort_unless($actor->hasEmployeeLink() && array_diff($ids, [$actor->ownEmployeeId()]) === [], 403);
        }

        // The Employment list, by name the way the record stores it: the
        // id lets Payroll read a per-status Pay Basis & Rates override.
        $statusIds = [];
        foreach (EmploymentStatusOption::query()->get(['id', 'name']) as $option) {
            $statusIds[mb_strtolower(trim((string) $option->name))] = (string) $option->id;
        }

        return array_values(EmployeeProfile::query()->whereIn('id', $ids)->get()->map(static fn (EmployeeProfile $e): EmployeePayData => new EmployeePayData(
            $e->id, $e->employee_code, $e->first_name, $e->last_name, $e->suffix, $e->position, $e->photo_path,
            $e->date_hired, $e->date_resigned, $e->monthly_rate, $e->daily_rate, $e->hourly_rate, $e->is_active,
            $e->employee_id_no,
            // The statutory switches and overrides the engine reads. Named, so
            // none can land in another's slot; every one is a calculation
            // input. The columns are the baseline: Payroll lays an employee's
            // approved Contributions & tax version over them before computing;
            // a new hire holds the column defaults.
            allow_sss: (bool) $e->allow_sss,
            allow_philhealth: (bool) $e->allow_philhealth,
            allow_pagibig: (bool) $e->allow_pagibig,
            allow_withholding_tax: (bool) $e->allow_withholding_tax,
            annualize_tax: (bool) $e->annualize_tax,
            fixed_tax: (float) $e->fixed_tax,
            percent_tax: (float) $e->percent_tax,
            fixed_sss_ee: (float) $e->fixed_sss_ee,
            fixed_sss_er: (float) $e->fixed_sss_er,
            fixed_philhealth_ee: (float) $e->fixed_philhealth_ee,
            fixed_philhealth_er: (float) $e->fixed_philhealth_er,
            fixed_pagibig_ee: (float) $e->fixed_pagibig_ee,
            fixed_pagibig_er: (float) $e->fixed_pagibig_er,
            sss_mode: $e->sss_mode,
            philhealth_mode: $e->philhealth_mode,
            pagibig_mode: $e->pagibig_mode,
            employment_status: trim((string) $e->employment_status) === '' ? null : (string) $e->employment_status,
            employment_status_id: $statusIds[mb_strtolower(trim((string) $e->employment_status))] ?? null,
        ))->all());
    }
}
