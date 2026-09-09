<?php

declare(strict_types=1);

namespace App\Livewire\Employees;

use App\Livewire\Kit\DateText;
use App\Models\EmployeeProfile;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Collator;

/**
 * One definition of what an employee record holds — the port of
 * `src/app/(protected)/employees/employee-fields.tsx` (SECTIONS, REQUIRED,
 * NUMERIC, BOOLEAN, readValue, toForm) plus the slice of `src/lib/hris.ts`
 * this module reads (statusKey, employmentStatusLabel, isRegular, peso,
 * emailError, nextEmployeeCode, todayKey, compareText, humanSize) and the
 * cross-field date policy of `src/lib/employee-policy.ts`.
 *
 * Shared by the record page and the add-employee stepper so the two can never
 * drift apart, exactly as the source arranged it.
 */
final class EmployeeFields
{
    public const string CODE_PREFIX = 'BWF-';

    /** attachments.ts:20 — API mode: 2 MB per stored file. */
    public const int MAX_FILE_BYTES = 2_000_000;

    /** portrait.ts:15 — acceptance cap on a picked photo, not what is stored. */
    public const int MAX_PHOTO_BYTES = 4_000_000;

    /** portrait.ts:18-19 — longest edge of the stored portrait, JPEG quality. */
    public const int PORTRAIT_MAX_EDGE = 512;

    public const int PORTRAIT_JPEG_QUALITY = 85;

    /**
     * storage.ts:15 — the server's 1 GB sanity ceiling across every
     * attachment, overridable per install. Read through env() with a default
     * because this module owns no config file; move the key into config/ when
     * the host adopts `config:cache`.
     */
    public static function storageCeilingBytes(): int
    {
        return (int) config('bestworkflows.uploads.storage_ceiling_bytes', 1_000_000_000);
    }

    /** @var list<array{value: string, label: string}> */
    public const array SEX = [
        ['value' => 'Male', 'label' => 'Male'],
        ['value' => 'Female', 'label' => 'Female'],
    ];

    /** @var list<array{value: string, label: string}> */
    public const array CIVIL = [
        ['value' => 'Single', 'label' => 'Single'],
        ['value' => 'Married', 'label' => 'Married'],
        ['value' => 'Widowed', 'label' => 'Widowed'],
        ['value' => 'Separated', 'label' => 'Separated'],
    ];

    /**
     * The three the UI offers (employee-fields.tsx:64-68). The server schema
     * accepts only the first two — picking "Quarterly" fails on save exactly
     * as it did against the reference API. Ported quirk; do not "fix" either.
     *
     * @var list<array{value: string, label: string}>
     */
    public const array MODES = [
        ['value' => 'SEMI_MONTHLY', 'label' => 'Semi-monthly'],
        ['value' => 'MONTHLY', 'label' => 'Monthly'],
        ['value' => 'QUARTERLY', 'label' => 'Quarterly'],
    ];

    /**
     * hris.ts BUILT_IN_STATUSES — legacy tokens still on old rows.
     *
     * @var array<string, string>
     */
    private const array BUILT_IN_STATUSES = [
        'REGULAR' => 'Regular',
        'PROBATIONARY' => 'Probationary',
        'CONTRACTUAL' => 'Contractual',
        'JOB_ORDER' => 'Job Order',
    ];

    /**
     * employee-documents.tsx BUILT_IN_CATEGORIES — kept only so documents
     * filed as `GOVERNMENT_ID` still read as "Government ID".
     *
     * @var array<string, string>
     */
    public const array BUILT_IN_CATEGORIES = [
        'GOVERNMENT_ID' => 'Government ID',
        'CONTRACT' => 'Contract',
        'CERTIFICATE' => 'Certificate',
        'MEDICAL' => 'Medical',
        'RESUME' => 'Résumé',
        'CASE' => 'Case',
        'OTHER' => 'Other',
    ];

    /**
     * The dialog's REQUIRED set (add-employee-dialog.tsx:31-49) — everything
     * HR needs on day one. The code is assigned, not typed, so it is not here.
     *
     * @var list<string>
     */
    public const array REQUIRED = [
        'first_name', 'last_name', 'phone', 'email', 'birth_date', 'address',
        'sex', 'civil_status',
        'emergency_name', 'emergency_relation', 'emergency_phone',
        'position', 'employment_status', 'work_site_id', 'date_hired', 'monthly_rate',
    ];

    /**
     * SECTIONS, transcribed field for field from employee-fields.tsx:70-176.
     * `derived` names a computed read-only value; `auto` is system-assigned;
     * `source` pulls options from a Records Management list; `site` from
     * Site Settings.
     *
     * @return list<array{id: string, title: string, column: string, fields: list<array<string, mixed>>}>
     */
    public static function sections(): array
    {
        return [
            [
                'id' => 'personal',
                'title' => 'Personal Details',
                'column' => 'left',
                'fields' => [
                    ['key' => 'employee_code', 'label' => 'Employee ID', 'auto' => true],
                    ['key' => 'first_name', 'label' => 'First Name'],
                    ['key' => 'middle_name', 'label' => 'Middle Name'],
                    ['key' => 'last_name', 'label' => 'Last Name'],
                    ['key' => 'suffix', 'label' => 'Suffix'],
                    ['key' => 'phone', 'label' => 'Contact Number', 'type' => 'tel'],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'email'],
                    ['key' => 'birth_date', 'label' => 'Birthday', 'type' => 'date'],
                    ['key' => 'age', 'label' => 'Age', 'derived' => 'age'],
                    ['key' => 'address', 'label' => 'Address', 'type' => 'textarea'],
                    ['key' => 'sex', 'label' => 'Gender', 'type' => 'select', 'options' => self::SEX],
                    ['key' => 'civil_status', 'label' => 'Civil Status', 'type' => 'select', 'options' => self::CIVIL],
                    ['key' => 'residence_cert', 'label' => 'Residence Certificate'],
                ],
            ],
            [
                'id' => 'emergency',
                'title' => 'Emergency Contact',
                'column' => 'left',
                'fields' => [
                    ['key' => 'emergency_name', 'label' => 'Full Name', 'wide' => true],
                    ['key' => 'emergency_relation', 'label' => 'Relationship'],
                    ['key' => 'emergency_phone', 'label' => 'Contact Number', 'type' => 'tel'],
                ],
            ],
            [
                'id' => 'employment',
                'title' => 'Employee Details',
                'column' => 'right',
                'fields' => [
                    ['key' => 'position', 'label' => 'Position', 'type' => 'select', 'source' => 'positions'],
                    ['key' => 'position_level', 'label' => 'Position Level'],
                    ['key' => 'employment_status', 'label' => 'Employment Status', 'type' => 'select', 'source' => 'employment_statuses'],
                    ['key' => 'work_site_id', 'label' => 'Assigned Worksite', 'type' => 'select', 'site' => true],
                    ['key' => 'date_hired', 'label' => 'Date Joined', 'type' => 'date'],
                    ['key' => 'date_regular', 'label' => 'Date Regularized', 'type' => 'date'],
                    ['key' => 'date_resigned', 'label' => 'Date Resigned', 'type' => 'date'],
                    // KNOWN-GAP (ported deliberately): this switch is the ONLY
                    // path to deactivate an employee — the reference ships no
                    // dedicated deactivate action, and its add dialog hardcodes
                    // is_active=true. Kept faithful rather than adding one.
                    ['key' => 'is_active', 'label' => 'Active employee', 'type' => 'switch'],
                    ['key' => 'monthly_rate', 'label' => 'Rate Per Month', 'type' => 'money'],
                    ['key' => 'allowances', 'label' => 'Allowances', 'type' => 'money'],
                    ['key' => 'vacation_credits', 'label' => 'Vacation Leave Credits', 'type' => 'number'],
                    ['key' => 'sick_credits', 'label' => 'Sick Leave Credits', 'type' => 'number'],
                    ['key' => 'length_of_service', 'label' => 'Length Of Service', 'derived' => 'length_of_service'],
                ],
            ],
            [
                'id' => 'statutory',
                'title' => 'Statutory & Bank',
                'column' => 'right',
                'fields' => [
                    ['key' => 'sss_no', 'label' => 'SSS Number'],
                    ['key' => 'philhealth_no', 'label' => 'PhilHealth Number'],
                    ['key' => 'pagibig_no', 'label' => 'Pag-IBIG Number'],
                    ['key' => 'tin', 'label' => 'TIN'],
                    ['key' => 'bank_account_no', 'label' => 'Account Number'],
                    ['key' => 'bank_code', 'label' => 'Bank Code (SWIFT)'],
                    ['key' => 'bank_type', 'label' => 'Bank Type'],
                ],
            ],
            [
                'id' => 'payroll',
                'title' => 'Payroll Settings',
                'column' => 'right',
                'fields' => [
                    ['key' => 'hourly_rate', 'label' => 'Hourly Rate Override (blank = from shift)', 'type' => 'money'],
                    ['key' => 'pay_code', 'label' => 'Pay Code'],
                    ['key' => 'agency_code', 'label' => 'Agency Code'],
                    ['key' => 'section_code', 'label' => 'Section Code'],
                    ['key' => 'tax_code', 'label' => 'Tax Code'],
                    ['key' => 'allow_sss', 'label' => 'Deduct SSS', 'type' => 'switch'],
                    ['key' => 'allow_philhealth', 'label' => 'Deduct PhilHealth', 'type' => 'switch'],
                    ['key' => 'allow_pagibig', 'label' => 'Deduct Pag-IBIG', 'type' => 'switch'],
                    ['key' => 'allow_withholding_tax', 'label' => 'Deduct Withholding Tax', 'type' => 'switch'],
                    ['key' => 'annualize_tax', 'label' => 'Annualize Tax', 'type' => 'switch'],
                    ['key' => 'fixed_tax', 'label' => 'Fixed Tax', 'type' => 'money'],
                    ['key' => 'percent_tax', 'label' => 'Percent Tax', 'type' => 'number'],
                    ['key' => 'fixed_sss_ee', 'label' => 'Fixed SSS — Employee', 'type' => 'money'],
                    ['key' => 'fixed_sss_er', 'label' => 'Fixed SSS — Employer', 'type' => 'money'],
                    ['key' => 'fixed_philhealth_ee', 'label' => 'Fixed PhilHealth — Employee', 'type' => 'money'],
                    ['key' => 'fixed_philhealth_er', 'label' => 'Fixed PhilHealth — Employer', 'type' => 'money'],
                    ['key' => 'fixed_pagibig_ee', 'label' => 'Fixed Pag-IBIG — Employee', 'type' => 'money'],
                    ['key' => 'fixed_pagibig_er', 'label' => 'Fixed Pag-IBIG — Employer', 'type' => 'money'],
                    ['key' => 'sss_mode', 'label' => 'SSS Mode', 'type' => 'select', 'options' => self::MODES],
                    ['key' => 'philhealth_mode', 'label' => 'PhilHealth Mode', 'type' => 'select', 'options' => self::MODES],
                    ['key' => 'pagibig_mode', 'label' => 'Pag-IBIG Mode', 'type' => 'select', 'options' => self::MODES],
                ],
            ],
        ];
    }

    /**
     * Every non-derived field, flat (ALL_FIELDS in the source).
     *
     * @return list<array<string, mixed>>
     */
    public static function allFields(): array
    {
        $fields = [];

        foreach (self::sections() as $section) {
            foreach ($section['fields'] as $field) {
                if (! isset($field['derived'])) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /** @return array<string, mixed>|null */
    public static function section(string $id): ?array
    {
        foreach (self::sections() as $section) {
            if ($section['id'] === $id) {
                return $section;
            }
        }

        return null;
    }

    /** @return list<string> keys typed number or money (NUMERIC in the source) */
    public static function numericKeys(): array
    {
        $keys = [];

        foreach (self::allFields() as $field) {
            if (in_array($field['type'] ?? null, ['number', 'money'], true)) {
                $keys[] = (string) $field['key'];
            }
        }

        return $keys;
    }

    /** @return list<string> keys typed switch (BOOLEAN in the source) */
    public static function booleanKeys(): array
    {
        $keys = [];

        foreach (self::allFields() as $field) {
            if (($field['type'] ?? null) === 'switch') {
                $keys[] = (string) $field['key'];
            }
        }

        return $keys;
    }

    /** Collapsed into a single "Full Name" row outside edit mode. */
    public const array NAME_PARTS = ['first_name', 'middle_name', 'last_name', 'suffix'];

    /**
     * blankForm() (add-employee-dialog.tsx:52-69): '' everywhere, booleans
     * true, then the sensible starting points.
     *
     * @return array<string, mixed>
     */
    public static function blankForm(): array
    {
        $boolean = self::booleanKeys();
        $form = [];

        foreach (self::allFields() as $field) {
            $key = (string) $field['key'];
            $form[$key] = in_array($key, $boolean, true) ? true : '';
        }

        $form['employment_status'] = 'Probationary';
        $form['date_hired'] = self::todayKey();
        $form['annualize_tax'] = false;
        $form['sss_mode'] = 'SEMI_MONTHLY';
        $form['philhealth_mode'] = 'SEMI_MONTHLY';
        $form['pagibig_mode'] = 'SEMI_MONTHLY';

        foreach (self::numericKeys() as $key) {
            $form[$key] = '0';
        }

        $form['monthly_rate'] = '';

        return $form;
    }

    /**
     * toForm() (employee-fields.tsx:186-193): the record as editable strings
     * and booleans. Dates come out as the `YYYY-MM-DD` key the DateField binds.
     *
     * @return array<string, mixed>
     */
    public static function toForm(EmployeeProfile $employee): array
    {
        $boolean = self::booleanKeys();
        $form = [];

        foreach (self::allFields() as $field) {
            $key = (string) $field['key'];
            $value = $employee->getAttribute($key);

            if (in_array($key, $boolean, true)) {
                $form[$key] = (bool) $value;
            } elseif ($value instanceof CarbonImmutable) {
                $form[$key] = $value->format('Y-m-d');
            } elseif ($value === null) {
                $form[$key] = '';
            } elseif (is_float($value)) {
                // String(25000) → "25000", not "25000.00" — the MoneyInput
                // settles the decimals itself.
                $form[$key] = $value == (int) $value ? (string) (int) $value : (string) $value;
            } else {
                $form[$key] = (string) $value;
            }
        }

        return $form;
    }

    /** A form/select boolean however Livewire round-tripped it. */
    public static function asBool(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === '1' || $value === 1;
    }

    /**
     * readValue() (employee-fields.tsx:242-259) — one field as display text.
     *
     * @param  array<string, mixed>  $field
     */
    public static function readValue(EmployeeProfile $employee, array $field): string
    {
        $key = (string) $field['key'];
        $type = $field['type'] ?? null;
        $raw = $employee->getAttribute($key);

        if ($type === 'switch') {
            return $raw ? 'Yes' : 'No';
        }

        if ($raw === null || $raw === '') {
            return '—';
        }

        if ($type === 'date') {
            return $raw instanceof CarbonImmutable
                ? DateText::toDisplay($raw->format('Y-m-d'))
                : DateText::toDisplay((string) $raw);
        }

        if ($type === 'number' || $type === 'money') {
            $n = (float) $raw;

            if (str_contains($key, 'credit')) {
                $shown = $n == (int) $n ? (string) (int) $n : (string) $n;

                return $shown.' day'.($n == 1.0 ? '' : 's');
            }

            return self::peso($n);
        }

        if ($type === 'select') {
            if ($key === 'employment_status') {
                return self::employmentStatusLabel((string) $raw);
            }

            foreach ((array) ($field['options'] ?? []) as $option) {
                if (($option['value'] ?? null) === $raw) {
                    return (string) $option['label'];
                }
            }

            return (string) $raw;
        }

        return (string) $raw;
    }

    /**
     * Which read-mode values render in the tabular `num` face (Field, employee-fields.tsx:333-341).
     *
     * @param  array<string, mixed>  $field
     */
    public static function isNumFace(array $field): bool
    {
        $type = $field['type'] ?? null;

        return in_array($type, ['number', 'money', 'date'], true)
            || str_ends_with((string) $field['key'], '_no');
    }

    public static function fullName(EmployeeProfile $employee): string
    {
        return EmployeeName::full($employee);
    }

    /**
     * employeeName() (hris.ts:425-427): first + last, now carrying the suffix.
     * See {@see EmployeeName} for why this no longer composes its own.
     */
    public static function employeeName(?EmployeeProfile $employee): string
    {
        return EmployeeName::display($employee);
    }

    public static function initials(EmployeeProfile $employee): string
    {
        return EmployeeName::initials($employee);
    }

    /** ageFrom() (employee-fields.tsx:224-236), on the Manila calendar. */
    public static function ageFrom(?CarbonImmutable $birth): string
    {
        if ($birth === null) {
            return '—';
        }

        $today = CarbonImmutable::parse(self::todayKey());
        $age = $today->year - $birth->year;

        if ($today->month < $birth->month || ($today->month === $birth->month && $today->day < $birth->day)) {
            $age--;
        }

        return $age >= 0 ? (string) $age : '—';
    }

    /** yearsAndMonths() (employee-fields.tsx:205-222). */
    public static function yearsAndMonths(?CarbonImmutable $from): string
    {
        if ($from === null) {
            return '—';
        }

        $to = CarbonImmutable::parse(self::todayKey());
        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month);

        if ($to->day < $from->day) {
            $months--;
        }

        if ($months < 0) {
            return '—';
        }

        $y = intdiv($months, 12);
        $m = $months % 12;

        $parts = [];

        if ($y !== 0) {
            $parts[] = $y.' year'.($y === 1 ? '' : 's');
        }

        if ($m !== 0 || $y === 0) {
            $parts[] = $m.' month'.($m === 1 ? '' : 's');
        }

        return implode(' and ', $parts);
    }

    /** @param  array<string, mixed>  $field */
    public static function derivedValue(EmployeeProfile $employee, array $field): string
    {
        return match ($field['derived'] ?? null) {
            'age' => self::ageFrom($employee->birth_date),
            'length_of_service' => self::yearsAndMonths($employee->date_hired),
            default => '—',
        };
    }

    /** "Job Order", "JOB_ORDER" and "job order" are one status (hris.ts:380-386). */
    public static function statusKey(?string $value): string
    {
        $key = strtoupper(trim($value ?? ''));
        $key = (string) preg_replace('/[^A-Z0-9]+/', '_', $key);

        return trim($key, '_');
    }

    public static function employmentStatusLabel(string $value): string
    {
        return self::BUILT_IN_STATUSES[$value] ?? $value;
    }

    public static function isRegular(?string $value): bool
    {
        return self::statusKey($value) === 'REGULAR';
    }

    /** categoryLabel() (employee-documents.tsx:66): token → label, names echo back. */
    public static function categoryLabel(string $value): string
    {
        return self::BUILT_IN_CATEGORIES[$value] ?? $value;
    }

    /** Today's Manila calendar day, `YYYY-MM-DD` (hris.ts todayKey). */
    public static function todayKey(): string
    {
        return ManilaClock::dayKey(CarbonImmutable::now());
    }

    /** peso() (hris.ts:180-186): ₱ + en-PH grouping, always two decimals. */
    public static function peso(float $n): string
    {
        if (! is_finite($n)) {
            $n = 0.0;
        }

        return "\u{20B1}".number_format($n, 2);
    }

    /** humanSize() (attachments.ts:26-29). */
    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1_000_000) {
            return sprintf('%.1f MB', $bytes / 1_000_000);
        }

        return max(1, (int) round($bytes / 1000)).' KB';
    }

    /** emailError() (hris.ts:230-238) — blank is fine; required is the form's call. */
    public static function emailError(string $value): ?string
    {
        $v = trim($value);

        if ($v === '') {
            return null;
        }

        return preg_match('/^[^\s@]+@[^\s@]+$/u', $v) === 1
            ? null
            : 'An email address needs an "@", like name@company.com.';
    }

    /**
     * nextEmployeeCode() (hris.ts:407-414): highest BWF-00000 suffix + 1.
     *
     * @param  array<array-key, string|null>  $codes
     */
    public static function nextEmployeeCode(array $codes): string
    {
        $highest = 0;

        foreach ($codes as $code) {
            if (preg_match('/^BWF-(\d+)$/i', trim((string) $code), $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return self::CODE_PREFIX.str_pad((string) ($highest + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * compareText() (useSort.ts:33-40): locale-aware, blanks last. The list
     * screen sorts in PHP because statusKey/label mapping cannot be expressed
     * in SQL, exactly as the source sorted in the client.
     */
    public static function compareText(?string $a, ?string $b): int
    {
        $left = trim($a ?? '');
        $right = trim($b ?? '');

        if ($left === '' && $right === '') {
            return 0;
        }

        if ($left === '') {
            return 1;
        }

        if ($right === '') {
            return -1;
        }

        if (class_exists(Collator::class)) {
            static $collator = null;
            $collator ??= new Collator('en');

            return $collator->compare($left, $right) ?: 0;
        }

        return strcasecmp($left, $right) <=> 0;
    }

    /** A real `YYYY-MM-DD` calendar day (lib/payroll-period.ts payrollDate). */
    public static function isCalendarDay(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * employeeDateError() (lib/employee-policy.ts) — the cross-field calendar
     * rules shared by create and update, message for message.
     *
     * @param  array{birth_date?: ?string, date_hired: string, date_regular?: ?string, date_resigned?: ?string}  $value
     */
    public static function employeeDateError(array $value, string $today): ?string
    {
        $birth = $value['birth_date'] ?? null;
        $regular = $value['date_regular'] ?? null;
        $resigned = $value['date_resigned'] ?? null;
        $hired = $value['date_hired'];

        foreach ([
            ['Birth date', $birth],
            ['Date joined', $hired],
            ['Date regularized', $regular],
            ['Date resigned', $resigned],
        ] as [$label, $date]) {
            if ($date !== null && $date !== '' && ! self::isCalendarDay($date)) {
                return "{$label} must be a real calendar date.";
            }
        }

        if ($birth && $birth > $today) {
            return 'Birth date cannot be in the future.';
        }

        if ($birth && $birth > $hired) {
            return 'Birth date cannot be after the date joined.';
        }

        if ($regular && $regular < $hired) {
            return 'Date regularized cannot be before the date joined.';
        }

        if ($resigned && $resigned < $hired) {
            return 'Date resigned cannot be before the date joined.';
        }

        if ($regular && $resigned && $regular > $resigned) {
            return 'Date regularized cannot be after the date resigned.';
        }

        return null;
    }

    /**
     * The per-field caps of `EmployeeInput` (src/lib/api/schemas.ts:11-82) —
     * transcribed so an overlong value fails on save the way the API's 400 did.
     *
     * @var array<string, int>
     */
    public const array TEXT_CAPS = [
        'employee_code' => 50, 'first_name' => 100, 'middle_name' => 100,
        'last_name' => 100, 'suffix' => 20, 'position' => 120, 'position_level' => 60,
        'employment_status' => 60, 'sex' => 20, 'civil_status' => 30,
        'address' => 300, 'phone' => 40, 'email' => 200, 'residence_cert' => 60,
        'emergency_name' => 120, 'emergency_relation' => 60, 'emergency_phone' => 40,
        'sss_no' => 40, 'philhealth_no' => 40, 'pagibig_no' => 40, 'tin' => 40,
        'bank_account_no' => 60, 'bank_code' => 40, 'bank_type' => 40,
        'pay_code' => 40, 'agency_code' => 40, 'section_code' => 40, 'tax_code' => 40,
    ];

    /** @var array<string, float> Zod numeric maxima (schemas.ts:50-79). */
    public const array NUMERIC_CAPS = [
        'monthly_rate' => 99_999_999, 'hourly_rate' => 99_999_999,
        'allowances' => 99_999_999, 'vacation_credits' => 9999, 'sick_credits' => 9999,
        'fixed_tax' => 99_999_999, 'percent_tax' => 100,
        'fixed_sss_ee' => 99_999_999, 'fixed_sss_er' => 99_999_999,
        'fixed_philhealth_ee' => 99_999_999, 'fixed_philhealth_er' => 99_999_999,
        'fixed_pagibig_ee' => 99_999_999, 'fixed_pagibig_er' => 99_999_999,
    ];

    /** The two the server accepts — schemas.ts:80-82. */
    public const array SERVER_MODES = ['SEMI_MONTHLY', 'MONTHLY'];
}
