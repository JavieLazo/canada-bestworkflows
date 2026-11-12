<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached employee pay settings, with no persistence or lazy relationships:
 * who the employee is, their Employment status (`employment_status_id` is
 * the Employment list entry the stored name matches, null when none does),
 * the sixteen statutory values on the record (EmployeePaySettingsRecords::
 * STATUTORY: the baseline an employee with no approved Contributions & tax
 * version is priced by) and the hourly rate override
 * (EmployeePaySettingsInput::DEFAULTS). Read only by all-data PAYROLL
 * holders (EmployeePaySettingsRecords).
 *
 * @property-read string $id
 * @property-read string $employee_code
 * @property-read string|null $employee_id_no
 * @property-read string $first_name
 * @property-read string|null $middle_name
 * @property-read string $last_name
 * @property-read string|null $suffix
 * @property-read string|null $position the position's name, shown under the name as Employee Records shows it
 * @property-read string|null $photo_path the public portrait, for the list's avatar
 * @property-read string|null $employment_status
 * @property-read string|null $employment_status_id
 * @property-read CarbonImmutable|null $date_hired
 * @property-read bool $is_active
 * @property-read bool $allow_sss
 * @property-read bool $allow_philhealth
 * @property-read bool $allow_pagibig
 * @property-read bool $allow_withholding_tax
 * @property-read bool $annualize_tax
 * @property-read float $fixed_tax
 * @property-read float $percent_tax
 * @property-read float $fixed_sss_ee
 * @property-read float $fixed_sss_er
 * @property-read float $fixed_philhealth_ee
 * @property-read float $fixed_philhealth_er
 * @property-read float $fixed_pagibig_ee
 * @property-read float $fixed_pagibig_er
 * @property-read string|null $sss_mode
 * @property-read string|null $philhealth_mode
 * @property-read string|null $pagibig_mode
 * @property-read float $hourly_rate
 * @property-read float|null $monthly_rate the Rate Per Month Employee Details sets, shown here but edited there
 * @property-read float|null $daily_rate the Rate Per Day Employee Details sets, null when blank
 */
final readonly class EmployeePaySettingsView extends Record {}
