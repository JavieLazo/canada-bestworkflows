<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

use App\Platform\ReadModels\Record;
use Carbon\CarbonImmutable;

/**
 * Detached EmployeeProfile data, with no persistence or lazy relationships.
 *
 * `children` is the employee's `employee_children` rows in sheet order, each
 * detached to `['name' => string, 'birth_date' => ?string]` with the date as
 * its `YYYY-MM-DD` key — supplied by `EmployeeRecords`, the one projection
 * that reads them.
 *
 * @property-read string $id
 * @property-read string $employee_code
 * @property-read string|null $employee_id_no
 * @property-read string $first_name
 * @property-read string|null $middle_name
 * @property-read string $last_name
 * @property-read string|null $suffix
 * @property-read string|null $position the title as the record shows it — the linked position's name, or a title typed before the Position list
 * @property-read string|null $position_id the linked position; null when unlinked (absent before its migration)
 * @property-read string|null $department_id where the linked position sits; null when unlinked or Not placed
 * @property-read string|null $department_name
 * @property-read string|null $unit_id null when the position is in the department itself
 * @property-read string|null $unit_name
 * @property-read bool $position_unlisted a stored title that is not linked to the Position list
 * @property-read string|null $position_level
 * @property-read string $employment_status
 * @property-read bool $is_active
 * @property-read string|null $work_site_id
 * @property-read string|null $photo_path
 * @property-read CarbonImmutable|null $birth_date
 * @property-read string|null $sex
 * @property-read string|null $civil_status
 * @property-read string|null $address
 * @property-read string|null $phone
 * @property-read string|null $email
 * @property-read string|null $residence_cert
 * @property-read string|null $emergency_name
 * @property-read string|null $emergency_relation
 * @property-read string|null $emergency_phone
 * @property-read string|null $emergency_address
 * @property-read string|null $place_of_birth
 * @property-read string|null $citizenship
 * @property-read string|null $telephone
 * @property-read string|null $religion
 * @property-read float|null $height_cm
 * @property-read float|null $weight_kg
 * @property-read string|null $blood_type
 * @property-read string|null $spouse_name
 * @property-read string|null $spouse_employment
 * @property-read list<array{name: string, birth_date: ?string}> $children
 * @property-read string|null $college
 * @property-read string|null $college_course
 * @property-read string|null $college_year_graduated
 * @property-read string|null $graduate_school
 * @property-read string|null $graduate_course
 * @property-read string|null $graduate_year_graduated
 * @property-read CarbonImmutable $date_hired
 * @property-read CarbonImmutable|null $date_regular
 * @property-read CarbonImmutable|null $date_resigned
 * @property-read float $monthly_rate
 * @property-read float|null $daily_rate
 * @property-read float $hourly_rate
 * @property-read float $allowances
 * @property-read string|null $sss_no
 * @property-read string|null $philhealth_no
 * @property-read string|null $pagibig_no
 * @property-read string|null $tin
 * @property-read string|null $bank_account_no
 * @property-read string|null $bank_code
 * @property-read string|null $bank_type
 * @property-read string|null $bank_name
 * @property-read string|null $pay_code
 * @property-read string|null $agency_code
 * @property-read string|null $section_code
 * @property-read string|null $tax_code
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
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable|null $updated_at
 *
 * `address` and `citizenship` above are the LEGACY values, kept as stored and
 * written by no form. Read the current ones through
 * `EmployeeFields::residentialText()` / `citizenshipText()`, which prefer the
 * structured data below and fall back to these.
 *
 * `pds_loaded` says whether the view was projected with the details row and
 * the address rows (`EmployeeRecords::find/list/own(…, details: true)`). Only
 * then does it carry the keys after it — NULL when nothing is stored:
 * @property-read bool $pds_loaded
 * @property-read string|null $civil_status_other
 * @property-read string|null $citizenship_kind
 * @property-read string|null $dual_citizenship_by
 * @property-read string|null $dual_citizenship_country
 * @property-read string|null $umid_no
 * @property-read string|null $philsys_pcn
 * @property-read bool|null $permanent_address_same NULL while nobody has answered
 * @property-read string|null $res_house_no
 * @property-read string|null $res_street
 * @property-read string|null $res_subdivision
 * @property-read string|null $res_barangay
 * @property-read string|null $res_city
 * @property-read string|null $res_province
 * @property-read string|null $res_zip_code
 * @property-read string|null $perm_house_no
 * @property-read string|null $perm_street
 * @property-read string|null $perm_subdivision
 * @property-read string|null $perm_barangay
 * @property-read string|null $perm_city
 * @property-read string|null $perm_province
 * @property-read string|null $perm_zip_code
 * @property-read string|null $spouse_surname
 * @property-read string|null $spouse_first_name
 * @property-read string|null $spouse_middle_name
 * @property-read string|null $spouse_name_extension
 * @property-read string|null $spouse_occupation
 * @property-read string|null $spouse_employer
 * @property-read string|null $spouse_business_address
 * @property-read string|null $spouse_telephone
 * @property-read string|null $father_surname
 * @property-read string|null $father_first_name
 * @property-read string|null $father_middle_name
 * @property-read string|null $father_name_extension
 * @property-read string|null $mother_surname
 * @property-read string|null $mother_first_name
 * @property-read string|null $mother_middle_name
 * @property-read bool|null $decl_34a NULL while not answered
 * @property-read string|null $decl_34a_details
 * @property-read bool|null $decl_34b NULL while not answered
 * @property-read string|null $decl_34b_details
 * @property-read bool|null $decl_35a NULL while not answered
 * @property-read string|null $decl_35a_details
 * @property-read bool|null $decl_35b NULL while not answered
 * @property-read string|null $decl_35b_details
 * @property-read CarbonImmutable|null $decl_35b_date_filed
 * @property-read string|null $decl_35b_case_status
 * @property-read bool|null $decl_36 NULL while not answered
 * @property-read string|null $decl_36_details
 * @property-read bool|null $decl_37 NULL while not answered
 * @property-read string|null $decl_37_details
 * @property-read bool|null $decl_38a NULL while not answered
 * @property-read string|null $decl_38a_details
 * @property-read bool|null $decl_38b NULL while not answered
 * @property-read string|null $decl_38b_details
 * @property-read bool|null $decl_39 NULL while not answered
 * @property-read string|null $decl_39_country
 * @property-read bool|null $decl_40a NULL while not answered
 * @property-read string|null $decl_40a_specify
 * @property-read bool|null $decl_40b NULL while not answered
 * @property-read string|null $decl_40b_id_no
 * @property-read bool|null $decl_40c NULL while not answered
 * @property-read string|null $decl_40c_id_no
 * @property-read list<array<string, mixed>> $education the stored schools, with the details
 * @property-read list<string> $skills
 * @property-read list<string> $distinctions
 * @property-read list<string> $memberships
 */
final readonly class EmployeeProfileView extends Record {}
