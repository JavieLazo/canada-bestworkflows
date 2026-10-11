<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Modules\Workforce\Contracts\Views\PdsRowView;
use App\Modules\Workforce\Infrastructure\PdsWorkbookTemplate;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Maps the authorized employee snapshot onto the supplied CS Form 212 workbook.
 * Fixed paper capacities never discard a stored row: excess entries and values
 * without a corresponding form field are retained on labeled continuation sheets.
 *
 * @phpstan-type Sheet array{education: list<PdsRowView>, eligibility: list<PdsRowView>, experience: list<PdsRowView>, training: list<PdsRowView>, voluntary: list<PdsRowView>, other: array<string, list<string>>}
 * @phpstan-type Extra list<list<string|int|float>>
 */
final class PdsWorkbookValues
{
    private const string NA = 'N/A';

    /** @var array<string, array{int, int}> */
    private const array DECLARATIONS = [
        'decl_34a' => [13, 14],
        'decl_34b' => [15, 16],
        'decl_35a' => [17, 18],
        'decl_35b' => [19, 20],
        'decl_36' => [21, 22],
        'decl_37' => [23, 24],
        'decl_38a' => [34, 35],
        'decl_38b' => [36, 37],
        'decl_39' => [25, 26],
        'decl_40a' => [27, 30],
        'decl_40b' => [28, 31],
        'decl_40c' => [29, 32],
    ];

    /** @param Sheet $sheet */
    public function fill(PdsWorkbookTemplate $book, EmployeeProfileView $employee, array $sheet): void
    {
        $extra = [];
        $this->personal($book, $employee, $extra);
        $this->family($book, $employee, $extra);
        $this->education($book, $sheet['education']);
        $this->eligibility($book, $sheet['eligibility']);
        $this->experience($book, $sheet['experience']);
        $this->voluntary($book, $sheet['voluntary']);
        $this->training($book, $sheet['training']);
        $this->other($book, $sheet['other']);
        $this->declarations($book, $employee, $extra);

        if ($extra !== []) {
            $book->continuation('PDS Details', ['Section', 'Field', 'Recorded value'], $extra);
        }
    }

    /** @param Extra $extra */
    private function personal(PdsWorkbookTemplate $book, EmployeeProfileView $employee, array &$extra): void
    {
        foreach ([
            'D10' => 'last_name', 'D11' => 'first_name', 'D12' => 'middle_name',
            'D15' => 'place_of_birth', 'D25' => 'blood_type', 'D27' => 'umid_no',
            'D29' => 'pagibig_no', 'D31' => 'philhealth_no', 'D33' => 'tin',
            'D34' => 'employee_id_no', 'I32' => 'telephone', 'I33' => 'phone', 'I34' => 'email',
        ] as $cell => $field) {
            $book->cell(1, $cell, self::text(self::value($employee, $field)));
        }

        $book->cell(1, 'L11', "NAME EXTENSION (JR., SR)\n".self::text($employee->suffix));
        $this->date($book, 1, 'D13', $employee->birth_date);
        $book->cell(1, 'D22', $employee->height_cm !== null && $employee->height_cm > 0 ? (float) EmployeeFields::metresText($employee->height_cm) : self::NA, '0.####');
        $book->cell(1, 'D24', $employee->weight_kg !== null && $employee->weight_kg > 0 ? $employee->weight_kg : self::NA, '0.##');
        $book->check(4, $employee->sex === 'Male');
        $book->check(5, $employee->sex === 'Female');

        $status = trim((string) $employee->civil_status);
        $other = $status !== '' && ! in_array($status, ['Single', 'Married', 'Widowed', 'Separated'], true);

        foreach (['Single' => 6, 'Married' => 7, 'Widowed' => 8, 'Separated' => 10] as $label => $control) {
            $book->check($control, $status === $label);
        }

        $book->check(9, $other);
        $book->cell(1, 'E20', $other ? self::text($status === 'Other' ? self::value($employee, 'civil_status_other') : $status) : '');

        $kind = self::value($employee, 'citizenship_kind');
        $by = $kind === 'DUAL' ? self::value($employee, 'dual_citizenship_by') : null;
        $book->check(2, $kind === 'FILIPINO');
        $book->check(3, $kind === 'DUAL');
        $book->check(11, $by === 'BIRTH');
        $book->check(12, $by === 'NATURALIZATION');
        $book->country($kind === 'DUAL' ? self::text(self::value($employee, 'dual_citizenship_country')) : null);
        $legacy = EmployeeFields::legacyCitizenship($employee);

        if ($legacy !== '') {
            $book->cell(1, 'J15', 'See PDS Details');
            $extra[] = ['I. Personal information', 'Citizenship (legacy text)', $legacy];
        }

        // A PhilSys card number (PCN) is NOT the PhilSys Number (PSN) requested here.
        $book->cell(1, 'D32', self::NA);
        $pcn = trim((string) self::value($employee, 'philsys_pcn'));

        if ($pcn !== '') {
            $extra[] = ['I. Personal information', 'PhilSys card number (PCN; not PSN)', $pcn];
        }

        $this->address($book, $employee, 'RESIDENTIAL', ['I17', 'L17', 'I19', 'L19', 'I22', 'L22', 'I24'], $extra);
        $this->address($book, $employee, 'PERMANENT', ['I25', 'L25', 'I27', 'L27', 'I29', 'L29', 'I31'], $extra);
    }

    /**
     * @param  list<string>  $cells
     * @param  Extra  $extra
     */
    private function address(PdsWorkbookTemplate $book, EmployeeProfileView $employee, string $type, array $cells, array &$extra): void
    {
        $source = $type === 'PERMANENT' && self::value($employee, 'permanent_address_same') === true ? 'RESIDENTIAL' : $type;
        $parts = EmployeeFields::addressParts($employee, $source);
        $legacy = $source === 'RESIDENTIAL' ? EmployeeFields::legacyAddress($employee) : '';

        foreach (array_keys(EmployeeFields::ADDRESS_PARTS) as $index => $part) {
            $book->cell(1, $cells[$index], $legacy === '' ? self::text($parts[$part] ?? '') : '');
        }

        if ($legacy !== '') {
            $book->cell(1, $cells[0], 'See PDS Details');
            $extra[] = ['I. Personal information', ucfirst(strtolower($type)).' address (legacy text)', $legacy];
        }
    }

    /** @param Extra $extra */
    private function family(PdsWorkbookTemplate $book, EmployeeProfileView $employee, array &$extra): void
    {
        foreach ([
            'D36' => 'spouse_surname', 'D37' => 'spouse_first_name', 'D38' => 'spouse_middle_name',
            'D39' => 'spouse_occupation', 'D40' => 'spouse_employer', 'D41' => 'spouse_business_address',
            'D42' => 'spouse_telephone', 'D43' => 'father_surname', 'D44' => 'father_first_name',
            'D45' => 'father_middle_name', 'D47' => 'mother_surname', 'D48' => 'mother_first_name', 'D49' => 'mother_middle_name',
        ] as $cell => $field) {
            $book->cell(1, $cell, self::text(self::value($employee, $field)));
        }

        foreach (['G37' => 'spouse_name_extension', 'G44' => 'father_name_extension'] as $cell => $field) {
            $book->cell(1, $cell, "NAME EXTENSION (JR., SR)\n".self::text(self::value($employee, $field)));
        }

        if (! EmployeeFields::spouseTyped($employee)) {
            if (trim((string) $employee->spouse_name) !== '') {
                $book->cell(1, 'D36', 'See PDS Details');
                $extra[] = ['II. Family background', "Spouse's name (legacy text)", trim((string) $employee->spouse_name)];
            }

            if (trim((string) $employee->spouse_employment) !== '') {
                $book->cell(1, 'D39', 'See PDS Details');
                $extra[] = ['II. Family background', "Spouse's employment (legacy text)", trim((string) $employee->spouse_employment)];
            }
        }

        $children = EmployeeFields::childRows($employee);
        $overflow = [];

        if ($children === []) {
            $book->cell(1, 'I37', self::NA);
            $book->cell(1, 'M37', self::NA);
        }

        foreach ($children as $index => $child) {
            if ($index >= 12) {
                $overflow[] = [self::text($child['name']), self::dateText($child['birth_date'])];

                continue;
            }

            $row = 37 + $index;
            $book->cell(1, 'I'.$row, self::text($child['name']));
            $this->date($book, 1, 'M'.$row, $child['birth_date']);
        }

        if ($overflow !== []) {
            $book->continuation('Children - continued', ['Name of child', 'Date of birth (dd/mm/yyyy)'], $overflow);
        }
    }

    /** @param list<PdsRowView> $rows */
    private function education(PdsWorkbookTemplate $book, array $rows): void
    {
        $positions = ['ELEMENTARY' => 54, 'SECONDARY' => 55, 'VOCATIONAL' => 56, 'COLLEGE' => 57, 'GRADUATE' => 58];
        $used = [];
        $overflow = [];

        foreach ($rows as $row) {
            $values = [self::text($row->school), self::text($row->course), $row->attended_from ?? self::NA, $row->attended_to ?? self::NA, self::text($row->units_earned), $row->year_graduated ?? self::NA, self::text($row->honors)];
            $position = $positions[$row->level] ?? null;

            if ($position === null || isset($used[$row->level])) {
                $overflow[] = array_merge([EmployeeFields::EDUCATION_LEVELS[$row->level] ?? $row->level], $values);

                continue;
            }

            $used[$row->level] = true;

            foreach (['D', 'G', 'J', 'K', 'L', 'M', 'N'] as $index => $column) {
                $book->cell(1, $column.$position, $values[$index], is_int($values[$index]) ? '0' : null);
            }
        }

        if ($overflow !== []) {
            $book->continuation('Education - continued', ['Level', 'Name of school', 'Degree / course', 'Attended from', 'Attended to', 'Highest level / units earned', 'Year graduated', 'Scholarship / honors'], $overflow);
        }
    }

    /** @param list<PdsRowView> $rows */
    private function eligibility(PdsWorkbookTemplate $book, array $rows): void
    {
        $overflow = [];
        $this->emptyList($book, 2, 5, ['A', 'F', 'G', 'I', 'L', 'M'], $rows);

        foreach ($rows as $index => $row) {
            if ($index >= 7) {
                $overflow[] = [self::text($row->name), self::text($row->rating), self::dateText($row->exam_date), self::text($row->exam_place), self::text($row->license_no), self::dateText($row->license_valid_until)];

                continue;
            }

            $line = 5 + $index;
            foreach (['A' => $row->name, 'F' => $row->rating, 'I' => $row->exam_place, 'L' => $row->license_no] as $column => $value) {
                $book->cell(2, $column.$line, self::text($value));
            }
            $this->date($book, 2, 'G'.$line, $row->exam_date);
            $this->date($book, 2, 'M'.$line, $row->license_valid_until);
        }

        if ($overflow !== []) {
            $book->continuation('Eligibility - continued', ['Eligibility', 'Rating', 'Exam / conferment (dd/mm/yyyy)', 'Place of exam / conferment', 'License number', 'Valid until (dd/mm/yyyy)'], $overflow);
        }
    }

    /** @param list<PdsRowView> $rows */
    private function experience(PdsWorkbookTemplate $book, array $rows): void
    {
        $overflow = [];
        $this->emptyList($book, 2, 18, ['A', 'C', 'D', 'G', 'J', 'K', 'L', 'M'], $rows);

        foreach ($rows as $index => $row) {
            $present = $row->kind === 'derived' && $row->date_to === null;
            if ($index >= 28) {
                $overflow[] = [self::dateText($row->date_from), $present ? 'Present' : self::dateText($row->date_to), self::text($row->position_title), self::text($row->company), $row->monthly_salary ?? self::NA, self::text($row->pay_grade), self::text($row->appointment_status), self::service($row->government_service)];

                continue;
            }

            $line = 18 + $index;
            $this->date($book, 2, 'A'.$line, $row->date_from);
            if ($present) {
                $book->cell(2, 'C'.$line, 'Present');
            } else {
                $this->date($book, 2, 'C'.$line, $row->date_to);
            }
            foreach (['D' => $row->position_title, 'G' => $row->company, 'K' => $row->pay_grade, 'L' => $row->appointment_status] as $column => $value) {
                $book->cell(2, $column.$line, self::text($value));
            }
            $book->cell(2, 'J'.$line, $row->monthly_salary ?? self::NA, '#,##0.00');
            $book->cell(2, 'M'.$line, self::service($row->government_service));
        }

        if ($overflow !== []) {
            $book->continuation('Work experience - continued', ['From (dd/mm/yyyy)', 'To (dd/mm/yyyy)', 'Position title', 'Department / agency / company', 'Monthly salary', 'Pay grade / step', 'Appointment status', 'Government service (Y/N)'], $overflow);
        }
    }

    /** @param list<PdsRowView> $rows */
    private function voluntary(PdsWorkbookTemplate $book, array $rows): void
    {
        $overflow = [];
        $this->emptyList($book, 3, 6, ['A', 'E', 'F', 'G', 'H'], $rows);

        foreach ($rows as $index => $row) {
            $organization = implode("\n", array_filter([trim((string) $row->organization), trim((string) $row->organization_address)], static fn (string $value): bool => $value !== ''));
            if ($index >= 7) {
                $overflow[] = [self::text($row->organization), self::text($row->organization_address), self::dateText($row->date_from), self::dateText($row->date_to), $row->hours ?? self::NA, self::text($row->position)];

                continue;
            }

            $line = 6 + $index;
            $book->cell(3, 'A'.$line, self::text($organization));
            $this->date($book, 3, 'E'.$line, $row->date_from);
            $this->date($book, 3, 'F'.$line, $row->date_to);
            $book->cell(3, 'G'.$line, $row->hours ?? self::NA, '0.##');
            $book->cell(3, 'H'.$line, self::text($row->position));
        }

        if ($overflow !== []) {
            $book->continuation('Voluntary work - continued', ['Organization', 'Address', 'From (dd/mm/yyyy)', 'To (dd/mm/yyyy)', 'Hours', 'Position / nature of work'], $overflow);
        }
    }

    /** @param list<PdsRowView> $rows */
    private function training(PdsWorkbookTemplate $book, array $rows): void
    {
        $overflow = [];
        $this->emptyList($book, 3, 18, ['A', 'E', 'F', 'G', 'H', 'I'], $rows);

        foreach ($rows as $index => $row) {
            if ($index >= 21) {
                $overflow[] = [self::text($row->title), self::dateText($row->date_from), self::dateText($row->date_to), $row->hours ?? self::NA, self::text($row->ld_type), self::text($row->sponsor)];

                continue;
            }

            $line = 18 + $index;
            $book->cell(3, 'A'.$line, self::text($row->title));
            $this->date($book, 3, 'E'.$line, $row->date_from);
            $this->date($book, 3, 'F'.$line, $row->date_to);
            $book->cell(3, 'G'.$line, $row->hours ?? self::NA, '0.##');
            $book->cell(3, 'H'.$line, self::text($row->ld_type));
            $book->cell(3, 'I'.$line, self::text($row->sponsor));
        }

        if ($overflow !== []) {
            $book->continuation('Training - continued', ['Training title', 'From (dd/mm/yyyy)', 'To (dd/mm/yyyy)', 'Hours', 'Type of L&D', 'Conducted / sponsored by'], $overflow);
        }
    }

    /** @param array<string, list<string>> $other */
    private function other(PdsWorkbookTemplate $book, array $other): void
    {
        $overflow = [];
        $count = max(1, count($other['skills'] ?? []), count($other['distinctions'] ?? []), count($other['memberships'] ?? []));

        for ($index = 0; $index < $count; $index++) {
            $values = [];
            foreach (['skills' => 'A', 'distinctions' => 'C', 'memberships' => 'I'] as $key => $column) {
                $lines = $other[$key] ?? [];
                $value = isset($lines[$index]) ? self::text($lines[$index]) : ($index === 0 ? self::NA : '');
                $values[] = $value;
                if ($index < 7) {
                    $book->cell(3, $column.(42 + $index), $value);
                }
            }
            if ($index >= 7) {
                $overflow[] = $values;
            }
        }

        if ($overflow !== []) {
            $book->continuation('Other information - continued', ['Special skills and hobbies', 'Distinctions / recognition', 'Association / organization memberships'], $overflow);
        }
    }

    /** @param Extra $extra */
    private function declarations(PdsWorkbookTemplate $book, EmployeeProfileView $employee, array &$extra): void
    {
        foreach (self::DECLARATIONS as $key => [$yes, $no]) {
            $answer = EmployeeFields::answerValue(EmployeeFields::answerForm(self::value($employee, $key)));
            $book->check($yes, $answer === true);
            $book->check($no, $answer === false);
        }

        // The paper provides one details space for both parts of question 34.
        $related = [];
        foreach (['decl_34a' => '34a', 'decl_34b' => '34b'] as $key => $label) {
            $value = trim((string) self::value($employee, $key.'_details'));
            if ($value !== '') {
                $related[] = $label.': '.$value;
                $extra[] = ['Declarations', $label.' details', $value];
            }
        }
        $book->cell(4, 'I11', $related === [] ? '' : 'See PDS Details');

        foreach ([
            'decl_35a_details' => ['I15', '35a', 28],
            'decl_35b_details' => ['G19', '35b', 0],
            'decl_35b_case_status' => ['J21', '35b case status', 16],
            'decl_36_details' => ['I25', '36', 28],
            'decl_37_details' => ['I29', '37', 28],
            'decl_38a_details' => ['K32', '38a', 12],
            'decl_38b_details' => ['K35', '38b', 12],
            'decl_39_country' => ['I39', '39 country', 28],
            'decl_40a_specify' => ['K44', '40a indigenous group', 20],
            'decl_40b_id_no' => ['K46', '40b disability ID number', 20],
            'decl_40c_id_no' => ['K48', '40c solo parent ID number', 20],
        ] as $field => [$cell, $label, $capacity]) {
            $value = trim((string) self::value($employee, $field));
            $question = explode('_', $field)[1];
            $answer = self::value($employee, 'decl_'.$question);
            $printed = $value === '' ? ($answer === false ? self::NA : '') : $value;

            if ($value !== '') {
                $extra[] = ['Declarations', $label, $value];
                if (mb_strlen($value) > $capacity) {
                    $printed = 'See PDS Details';
                }
            }

            $book->cell(4, $cell, $field === 'decl_35b_details' ? 'If YES, give details: '.$printed : $printed);
        }

        $filed = self::value($employee, 'decl_35b_date_filed');
        if (self::day($filed) !== '') {
            $this->date($book, 4, 'J20', $filed);
            $extra[] = ['Declarations', '35b date filed (dd/mm/yyyy)', self::dateText($filed)];
        } elseif (self::value($employee, 'decl_35b') === false) {
            $book->cell(4, 'J20', self::NA);
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<PdsRowView>  $rows
     */
    private function emptyList(PdsWorkbookTemplate $book, int $sheet, int $row, array $columns, array $rows): void
    {
        if ($rows !== []) {
            return;
        }
        foreach ($columns as $column) {
            $book->cell($sheet, $column.$row, self::NA);
        }
    }

    private static function value(EmployeeProfileView $employee, string $key): mixed
    {
        $field = EmployeeFields::field($key);

        return $field === null ? null : EmployeeFields::storedValue($employee, $field);
    }

    private static function text(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? self::NA : $value;
    }

    private static function service(?bool $value): string
    {
        return match ($value) {
            true => 'Y', false => 'N', null => self::NA,
        };
    }

    private static function day(mixed $value): string
    {
        $day = $value instanceof CarbonInterface ? $value->format('Y-m-d') : (is_string($value) ? trim($value) : '');

        return EmployeeFields::isCalendarDay($day) ? $day : '';
    }

    private static function dateText(mixed $value): string
    {
        $day = self::day($value);
        if ($day === '') {
            return self::NA;
        }
        [$year, $month, $date] = explode('-', $day);

        return $date.'/'.$month.'/'.$year;
    }

    private function date(PdsWorkbookTemplate $book, int $sheet, string $cell, mixed $value): void
    {
        $day = self::day($value);
        if ($day === '') {
            $book->cell($sheet, $cell, self::NA);

            return;
        }

        $timezone = new DateTimeZone('UTC');
        $epoch = new DateTimeImmutable('1899-12-31', $timezone);
        $date = new DateTimeImmutable($day, $timezone);
        $serial = (int) $epoch->diff($date)->format('%r%a');
        // Excel includes the nonexistent 29 February 1900 in its serial calendar.
        $book->cell($sheet, $cell, $serial >= 60 ? $serial + 1 : $serial, 'dd/mm/yyyy');
    }
}
