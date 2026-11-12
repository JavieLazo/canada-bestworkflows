<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;

/**
 * The employee record as CSV columns, for Employee Records' Export CSV. There
 * is no employee import, so nothing reads a file back.
 *
 * After the minted ID come the forty-nine columns of LEGACY, a FROZEN list:
 * spreadsheets that read the file find each column by its position and its
 * header, so NEVER reorder or rename one, even when the screen relabels its
 * field ("Sex at birth" stays "Gender", "Mobile no." stays "Contact
 * Number"). Every other single-value field of the record
 * ({@see EmployeeFields::allFields()}, in sections() order) is appended
 * after them: the civil status specification, the structured citizenship,
 * the address parts, the UMID and PhilSys numbers, the spouse's and parents'
 * parts and the declarations (Yes, No, or blank while unanswered) with their
 * details. The Other info lists and the Personal Data Sheet's list rows are
 * lists, not values, and are not exported.
 *
 * Legacy columns whose values no form writes read structured-else-legacy:
 * "Address" carries the residential address typed in parts, else the stored
 * one-line address; "Citizenship" the chosen citizenship, else the stored
 * text; "Spouse's Name" and "Spouse's Employment" the spouse typed in parts,
 * else the stored lines; the College and Graduate Studies columns the latest
 * school of that level, else the stored lines.
 * "Height" stays in centimetres, as the record stores it, though the screen
 * shows metres.
 *
 * Cells are what a person reads in a sheet: Yes/No for a switch (blank for
 * "Same as residential" while nobody has answered it), `YYYY-MM-DD` for a
 * date, a plain number for an amount (a measure without its unit), the
 * option's label for a select, the worksite by NAME (the record stores its
 * id), and the children as one cell of `Name (YYYY-MM-DD)` entries separated
 * by "; ". The view must carry the details ({@see Queries\EmployeeRecords::arranged()}
 * with `details`, as the Employee Records CSV reads it).
 */
final class EmployeeColumns
{
    public const string ID = 'ID';

    /** The forty-nine columns after ID, in their order and spelling — NEVER reorder or rename. */
    private const array LEGACY = [
        'employee_id_no' => 'Employee ID',
        'first_name' => 'First Name',
        'middle_name' => 'Middle Name',
        'last_name' => 'Last Name',
        'suffix' => 'Suffix',
        'phone' => 'Contact Number',
        'telephone' => 'Telephone Number',
        'email' => 'Email',
        'birth_date' => 'Birthday',
        'place_of_birth' => 'Place of Birth',
        'address' => 'Address',
        'sex' => 'Gender',
        'civil_status' => 'Civil Status',
        'citizenship' => 'Citizenship',
        'religion' => 'Religion',
        'height_cm' => 'Height',
        'weight_kg' => 'Weight',
        'blood_type' => 'Blood Type',
        'emergency_name' => 'Emergency Contact Name',
        'emergency_relation' => 'Emergency Contact Relationship',
        'emergency_phone' => 'Emergency Contact Number',
        'emergency_address' => 'Emergency Contact Address',
        'position' => 'Position',
        'position_level' => 'Position Level',
        'employment_status' => 'Employment Status',
        'work_site_id' => 'Assigned Worksite',
        'date_hired' => 'Date Joined',
        'date_regular' => 'Date Regularized',
        'date_resigned' => 'Date Resigned',
        'is_active' => 'Active employee',
        'monthly_rate' => 'Rate Per Month',
        'daily_rate' => 'Rate Per Day',
        'spouse_name' => "Spouse's Name",
        'spouse_employment' => "Spouse's Employment",
        'children' => 'Children',
        'college' => 'College',
        'college_course' => 'College Course',
        'college_year_graduated' => 'College Year Graduated',
        'graduate_school' => 'Graduate Studies',
        'graduate_course' => 'Graduate Course/Masteral',
        'graduate_year_graduated' => 'Graduate Year Graduated',
        'sss_no' => 'SSS Number',
        'philhealth_no' => 'PhilHealth Number',
        'pagibig_no' => 'Pag-IBIG Number',
        'tin' => 'TIN',
        'bank_name' => 'Bank and Branch',
        'bank_account_no' => 'Account Number',
        'bank_code' => 'Bank Code (SWIFT)',
        'bank_type' => 'Bank Type',
    ];

    /** Headers of appended fields whose screen label alone would not say what the column holds. */
    private const array APPENDED = [
        'civil_status_other' => 'Civil Status Specify',
        'citizenship_kind' => 'Citizenship Type',
        'dual_citizenship_by' => 'Dual Citizenship By',
        'dual_citizenship_country' => 'Dual Citizenship Country',
        'permanent_address_same' => 'Permanent Address Same as Residential',
        'umid_no' => 'UMID ID No.',
        'philsys_pcn' => 'PhilSys Card No.',
        'spouse_surname' => 'Spouse Surname',
        'spouse_first_name' => 'Spouse First Name',
        'spouse_name_extension' => 'Spouse Name Extension',
        'spouse_middle_name' => 'Spouse Middle Name',
        'spouse_occupation' => 'Spouse Occupation',
        'spouse_employer' => 'Spouse Employer / Business Name',
        'spouse_business_address' => 'Spouse Business Address',
        'spouse_telephone' => 'Spouse Telephone No.',
        'father_surname' => 'Father Surname',
        'father_first_name' => 'Father First Name',
        'father_name_extension' => 'Father Name Extension',
        'father_middle_name' => 'Father Middle Name',
        'mother_surname' => 'Mother Maiden Surname',
        'mother_first_name' => 'Mother Maiden First Name',
        'mother_middle_name' => 'Mother Maiden Middle Name',
        'decl_34a' => 'Q34a Third Degree',
        'decl_34a_details' => 'Q34a Details',
        'decl_34b' => 'Q34b Fourth Degree',
        'decl_34b_details' => 'Q34b Details',
        'decl_35a' => 'Q35a Administrative Offense',
        'decl_35a_details' => 'Q35a Details',
        'decl_35b' => 'Q35b Criminally Charged',
        'decl_35b_details' => 'Q35b Details',
        'decl_35b_date_filed' => 'Q35b Date Filed',
        'decl_35b_case_status' => 'Q35b Status of Case',
        'decl_36' => 'Q36 Convicted',
        'decl_36_details' => 'Q36 Details',
        'decl_37' => 'Q37 Separated from Service',
        'decl_37_details' => 'Q37 Details',
        'decl_38a' => 'Q38a Election Candidate',
        'decl_38a_details' => 'Q38a Details',
        'decl_38b' => 'Q38b Resigned to Campaign',
        'decl_38b_details' => 'Q38b Details',
        'decl_39' => 'Q39 Immigrant or Permanent Resident',
        'decl_39_country' => 'Q39 Country',
        'decl_40a' => 'Q40a Indigenous Group',
        'decl_40a_specify' => 'Q40a Specify',
        'decl_40b' => 'Q40b Person with Disability',
        'decl_40b_id_no' => 'Q40b ID No.',
        'decl_40c' => 'Q40c Solo Parent',
        'decl_40c_id_no' => 'Q40c ID No.',
    ];

    /**
     * Every column after ID as field key => header: LEGACY, then every other
     * single-value field in sections() order (an address part named by its
     * group, "Residential Street"); the Other info lists are skipped.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        $headers = self::LEGACY;

        foreach (EmployeeFields::allFields() as $field) {
            $key = (string) $field['key'];

            if (isset($headers[$key]) || EmployeeFields::isLines($field)) {
                continue;
            }

            $headers[$key] = self::APPENDED[$key] ?? (isset($field['address'])
                ? ucfirst(strtolower((string) $field['address'])).' '.$field['label']
                : (string) $field['label']);
        }

        return $headers;
    }

    /** @return list<string> the whole header row */
    public static function row(): array
    {
        return [self::ID, ...array_values(self::headers())];
    }

    /**
     * One employee as the export row.
     *
     * @param  array<string, string>  $siteNames  work site id => name
     * @return list<string>
     */
    public static function cells(EmployeeProfileView $employee, array $siteNames): array
    {
        $form = EmployeeFields::toForm($employee);
        $cells = [$employee->employee_code];

        foreach (array_keys(self::headers()) as $key) {
            $cells[] = self::cell($employee, $key, $form, $siteNames);
        }

        return $cells;
    }

    /**
     * One column's cell. A field the form clears while it is not asked for
     * (Specify, the dual citizenship parts, the permanent parts) is stored
     * blank and exports ''.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, string>  $siteNames
     */
    private static function cell(EmployeeProfileView $employee, string $key, array $form, array $siteNames): string
    {
        $field = EmployeeFields::field($key);
        $value = $form[$key] ?? '';

        return match (true) {
            $key === 'address' => EmployeeFields::residentialText($employee),
            $key === 'citizenship' => EmployeeFields::citizenshipText($employee),
            // Centimetres, as stored; the form holds metres.
            $key === 'height_cm' => $employee->height_cm === null ? '' : EmployeeFields::measure((float) $employee->height_cm),
            $key === 'spouse_name' => EmployeeFields::spouseNameText($employee),
            $key === 'spouse_employment' => EmployeeFields::spouseEmploymentText($employee),
            $key === 'college' => EmployeeFields::educationFallback($employee, 'COLLEGE')['school'],
            $key === 'college_course' => EmployeeFields::educationFallback($employee, 'COLLEGE')['course'],
            $key === 'college_year_graduated' => EmployeeFields::educationFallback($employee, 'COLLEGE')['year'],
            $key === 'graduate_school' => EmployeeFields::educationFallback($employee, 'GRADUATE')['school'],
            $key === 'graduate_course' => EmployeeFields::educationFallback($employee, 'GRADUATE')['course'],
            $key === 'graduate_year_graduated' => EmployeeFields::educationFallback($employee, 'GRADUATE')['year'],
            $field === null => '',
            EmployeeFields::isChildren($field) => self::children(EmployeeFields::childRows($employee)),
            // After the null arm (it takes an array) and before the options
            // arm, which would export an unanswered declaration as "Not
            // answered": an answer is Yes, No or blank.
            EmployeeFields::isAnswer($field) => self::answer(EmployeeFields::storedValue($employee, $field)),
            // A switch with a form default is an answer: blank while nobody has given it.
            ($field['type'] ?? null) === 'switch' && isset($field['default']) => self::answer(EmployeeFields::storedValue($employee, $field)),
            ($field['type'] ?? null) === 'switch' => $value === true ? 'Yes' : 'No',
            $key === 'work_site_id' => $siteNames[(string) $value] ?? (string) $value,
            isset($field['options']) => self::label($field, (string) $value),
            default => is_string($value) ? $value : '',
        };
    }

    /** A stored yes/no answer: "Yes", "No", or '' while unanswered. */
    private static function answer(mixed $stored): string
    {
        return $stored === null ? '' : (EmployeeFields::asBool($stored) ? 'Yes' : 'No');
    }

    /**
     * The children's list as one cell: `Ana (2019-05-04); Ben`.
     *
     * @param  list<array{name: string, birth_date: string}>  $rows
     */
    private static function children(array $rows): string
    {
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = $row['birth_date'] === '' ? $row['name'] : "{$row['name']} ({$row['birth_date']})";
        }

        return implode('; ', $entries);
    }

    /**
     * A select's stored value → the label the file shows.
     *
     * @param  array<string, mixed>  $field
     */
    private static function label(array $field, string $value): string
    {
        foreach (self::options($field) as $stored => $label) {
            if ($stored === $value) {
                return $label;
            }
        }

        return $value;
    }

    /**
     * A select's options as stored value => label.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, string>
     */
    public static function options(array $field): array
    {
        $options = [];
        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && isset($option['value'], $option['label'])) {
                $options[(string) $option['value']] = (string) $option['label'];
            }
        }

        return $options;
    }
}
