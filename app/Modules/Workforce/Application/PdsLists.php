<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application;

use App\Models\EmployeeEducation;
use App\Models\EmployeeEligibility;
use App\Models\EmployeeTraining;
use App\Models\EmployeeVoluntaryWork;
use App\Models\EmployeeWorkExperience;
use App\Support\Money;
use InvalidArgumentException;

/**
 * The five Personal Data Sheet lists of an employee record — Educational
 * Background, Civil Service Eligibility, Work Experience, Learning and
 * Development, Voluntary Work — as one registry: each list's table, card
 * wording, dialog fields and rules. SavePdsRow and the wizard validate a row
 * here, and the record's list card and dialog are drawn from it, so a field
 * is named, capped and checked in one place.
 *
 * DELIBERATE DIVERGENCE: the reference record has no Personal Data Sheet
 * lists. None of them reads or writes a legacy column; the stored College
 * and Graduate lines are only shown beside the education rows (PdsRows).
 *
 * Pure except ready(), which asks whether a list's table exists yet (once
 * per request, EmployeeDetailRows::hasTable()).
 */
final class PdsLists
{
    /** Rows one employee may hold on one list: well above any real sheet, and the bound PdsRows pages in memory. */
    public const int MAX_ROWS = 100;

    /** @var list<string> */
    public const array KEYS = ['education', 'eligibility', 'experience', 'training', 'voluntary'];

    /** The Other info lines' table, asked for through ready('other'). */
    private const string OTHER_TABLE = 'employee_other_items';

    /**
     * The list's model, table, card and dialog wording, audit phrase and
     * fields. `noun` is the pagination plural and the cap message's, except
     * that PdsRows decides education's footer noun from the rows it
     * assembled; `one` names a single row in toasts and the confirm.
     *
     * @return array{model: class-string<EmployeeEducation|EmployeeEligibility|EmployeeWorkExperience|EmployeeTraining|EmployeeVoluntaryWork>, table: string, title: string, subtitle: string, add: string, noun: string, one: string, dialog: array{add: string, edit: string, text: string}, audit: string, fields: list<array{key: string, label: string, type: string, span: int, required: bool, cap: ?int, hint: ?string, options: list<array{value: string, label: string}>, aria: string}>}
     */
    public static function spec(string $list): array
    {
        $spec = match ($list) {
            'education' => [
                'model' => EmployeeEducation::class,
                'table' => 'employee_education',
                'title' => 'Educational Background',
                'subtitle' => 'Schools attended by {name}, from elementary to graduate studies.',
                'add' => 'Add School',
                'noun' => 'schools',
                'one' => 'school',
                'dialog' => ['add' => 'Add School', 'edit' => 'Edit School', 'text' => 'A level with no school prints as N/A on the PDS.'],
                'audit' => 'educational background',
            ],
            'eligibility' => [
                'model' => EmployeeEligibility::class,
                'table' => 'employee_eligibilities',
                'title' => 'Civil Service Eligibility',
                'subtitle' => 'Career service, board and bar eligibilities and licences held by {name}.',
                'add' => 'Add Eligibility',
                'noun' => 'eligibilities',
                'one' => 'eligibility',
                'dialog' => ['add' => 'Add Eligibility', 'edit' => 'Edit Eligibility', 'text' => 'Leave Rating, License no. and Valid until blank when they do not apply.'],
                'audit' => 'civil service eligibility',
            ],
            'experience' => [
                'model' => EmployeeWorkExperience::class,
                'table' => 'employee_work_experiences',
                'title' => 'Work Experience',
                'subtitle' => 'Most recent first. The first row comes from Employee Details.',
                'add' => 'Add Previous Job',
                'noun' => 'jobs',
                'one' => 'previous job',
                'dialog' => ['add' => 'Add Previous Job', 'edit' => 'Edit Previous Job', 'text' => 'A job before this one. The current job comes from Employee Details.'],
                'audit' => 'work experience',
            ],
            'training' => [
                'model' => EmployeeTraining::class,
                'table' => 'employee_trainings',
                'title' => 'Learning and Development',
                'subtitle' => 'Learning and development interventions and training programs attended, latest first.',
                'add' => 'Add Training',
                'noun' => 'trainings',
                'one' => 'training',
                'dialog' => ['add' => 'Add Training', 'edit' => 'Edit Training', 'text' => 'Write the title and the sponsor in full.'],
                'audit' => 'learning and development',
            ],
            'voluntary' => [
                'model' => EmployeeVoluntaryWork::class,
                'table' => 'employee_voluntary_works',
                'title' => 'Voluntary Work',
                'subtitle' => 'Voluntary work or involvement in civic, non-government, people and voluntary organizations.',
                'add' => 'Add Voluntary Work',
                'noun' => 'entries',
                'one' => 'voluntary work',
                'dialog' => ['add' => 'Add Voluntary Work', 'edit' => 'Edit Voluntary Work', 'text' => "Write the organization's name and address in full."],
                'audit' => 'voluntary work',
            ],
            default => throw new InvalidArgumentException("Unknown Personal Data Sheet list [{$list}]."),
        };

        return $spec + ['fields' => self::fields($list)];
    }

    /**
     * The list's fields in dialog order. `key` is the column; `type` is
     * text, select, year, date, money or number; `span` 2 takes the
     * dialog's full width; `cap` is the column's width; `aria` names the
     * input, because the dialog's labels sit apart from their controls.
     *
     * @return list<array{key: string, label: string, type: string, span: int, required: bool, cap: ?int, hint: ?string, options: list<array{value: string, label: string}>, aria: string}>
     */
    public static function fields(string $list): array
    {
        $fields = match ($list) {
            'education' => [
                ['key' => 'level', 'label' => 'Level', 'type' => 'select', 'span' => 2, 'required' => true, 'options' => self::options(EmployeeFields::EDUCATION_LEVELS)],
                ['key' => 'school', 'label' => 'Name of School', 'span' => 2, 'required' => true, 'cap' => 150, 'hint' => 'Write in full, as on the diploma or transcript.'],
                ['key' => 'course', 'label' => 'Basic Education / Degree / Course', 'span' => 2, 'cap' => 150, 'hint' => 'Write in full — no abbreviations.'],
                ['key' => 'attended_from', 'label' => 'Attended From', 'type' => 'year'],
                ['key' => 'attended_to', 'label' => 'Attended To', 'type' => 'year'],
                ['key' => 'units_earned', 'label' => 'Highest Level / Units Earned', 'cap' => 60, 'hint' => 'Only if not graduated.'],
                ['key' => 'year_graduated', 'label' => 'Year Graduated', 'type' => 'year'],
                ['key' => 'honors', 'label' => 'Scholarship / Academic Honors Received', 'span' => 2, 'cap' => 150],
            ],
            'eligibility' => [
                ['key' => 'name', 'label' => 'Eligibility', 'span' => 2, 'required' => true, 'cap' => 255, 'hint' => "Career service, RA 1080 (board or bar), under special laws, CES, CSEE, barangay eligibility or driver's license — write in full."],
                ['key' => 'rating', 'label' => 'Rating', 'cap' => 20, 'hint' => 'If applicable.'],
                ['key' => 'exam_date', 'label' => 'Date of Exam / Conferment', 'type' => 'date'],
                ['key' => 'exam_place', 'label' => 'Place of Exam / Conferment', 'span' => 2, 'cap' => 150],
                ['key' => 'license_no', 'label' => 'License No.', 'cap' => 40, 'hint' => 'If applicable.'],
                ['key' => 'license_valid_until', 'label' => 'Valid Until', 'type' => 'date'],
            ],
            'experience' => [
                ['key' => 'position_title', 'label' => 'Position Title', 'span' => 2, 'required' => true, 'cap' => 150, 'hint' => 'Write in full — no abbreviations.'],
                ['key' => 'company', 'label' => 'Department / Agency / Office / Company', 'span' => 2, 'required' => true, 'cap' => 150, 'hint' => 'Write in full — no abbreviations.'],
                ['key' => 'date_from', 'label' => 'From', 'type' => 'date', 'required' => true],
                ['key' => 'date_to', 'label' => 'To', 'type' => 'date', 'required' => true],
                ['key' => 'monthly_salary', 'label' => 'Monthly Salary', 'type' => 'money'],
                ['key' => 'pay_grade', 'label' => 'Pay Grade', 'cap' => 20, 'hint' => 'Salary, job or pay grade and step, like "11-2", if applicable.'],
                ['key' => 'appointment_status', 'label' => 'Appointment Status', 'cap' => 60],
                ['key' => 'government_service', 'label' => "Gov't Service", 'type' => 'select', 'options' => EmployeeFields::GOV_SERVICE],
            ],
            'training' => [
                ['key' => 'title', 'label' => 'Title', 'span' => 2, 'required' => true, 'cap' => 255, 'hint' => 'Write in full.'],
                ['key' => 'date_from', 'label' => 'From', 'type' => 'date', 'required' => true],
                ['key' => 'date_to', 'label' => 'To', 'type' => 'date', 'required' => true],
                ['key' => 'hours', 'label' => 'Hours', 'type' => 'number'],
                ['key' => 'ld_type', 'label' => 'Type of L&D', 'cap' => 60, 'hint' => 'Managerial, supervisory, technical, foundation …'],
                ['key' => 'sponsor', 'label' => 'Conducted / Sponsored By', 'span' => 2, 'cap' => 255, 'hint' => 'Write in full.'],
            ],
            'voluntary' => [
                ['key' => 'organization', 'label' => 'Organization', 'span' => 2, 'required' => true, 'cap' => 150, 'hint' => 'Write in full.'],
                ['key' => 'organization_address', 'label' => 'Address', 'span' => 2, 'cap' => 255],
                ['key' => 'date_from', 'label' => 'From', 'type' => 'date'],
                ['key' => 'date_to', 'label' => 'To', 'type' => 'date'],
                ['key' => 'hours', 'label' => 'Hours', 'type' => 'number'],
                ['key' => 'position', 'label' => 'Position / Nature of Work', 'cap' => 150],
            ],
            default => throw new InvalidArgumentException("Unknown Personal Data Sheet list [{$list}]."),
        };

        return array_values(array_map(static fn (array $field): array => [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'] ?? 'text',
            'span' => $field['span'] ?? 1,
            'required' => $field['required'] ?? false,
            'cap' => $field['cap'] ?? null,
            'hint' => $field['hint'] ?? null,
            'options' => $field['options'] ?? [],
            'aria' => $field['label'],
        ], $fields));
    }

    /**
     * Whether a list's table exists yet — or, for 'other', the Other info
     * lines' table. Answered once per request (EmployeeDetailRows::hasTable()):
     * the code can run before the migration that creates the table.
     */
    public static function ready(string $list): bool
    {
        return EmployeeDetailRows::hasTable($list === 'other' ? self::OTHER_TABLE : self::spec($list)['table']);
    }

    /**
     * An empty dialog draft. An education draft starts on `$level` when it
     * is a known level, else on the first one, so a new row opens on a
     * level; its select also offers a blank, which a row with an answer
     * is refused for (Level is required).
     *
     * @return array<string, string>
     */
    public static function blankRow(string $list, ?string $level = null): array
    {
        $row = array_fill_keys(array_column(self::fields($list), 'key'), '');

        if ($list === 'education') {
            $row['level'] = $level !== null && isset(EmployeeFields::EDUCATION_LEVELS[$level])
                ? $level
                : (string) array_key_first(EmployeeFields::EDUCATION_LEVELS);
        }

        return $row;
    }

    /**
     * Why each field of one row is not in shape, keyed by field, first
     * problem only. Every answer is read through EmployeeInput::text(), so a
     * tampered array meets "is required". Years, dates, amounts and hours go
     * through EmployeeInput::fieldError(), the record's own rules. Text
     * NEVER does: its caps come from this registry alone, because the list
     * keys `position`, `name`, `title` and `level` collide with
     * EmployeeFields::TEXT_CAPS keys, and routing Voluntary Work's "Position
     * / Nature of Work" there would cap it at the profile's 120 instead of
     * its column's 150. The cross-field date rules follow, for fields that
     * passed on their own.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    public static function errors(string $list, array $row): array
    {
        $errors = [];

        foreach (self::fields($list) as $field) {
            $key = $field['key'];
            $label = $field['label'];
            $text = EmployeeInput::text($row[$key] ?? '');

            if ($text === '') {
                if ($field['required']) {
                    $errors[$key] = "{$label} is required.";
                }

                continue;
            }

            $message = match ($field['type']) {
                'select' => in_array($text, array_column($field['options'], 'value'), true) ? null : "{$label} must be one of the listed options.",
                'year' => EmployeeInput::fieldError(['key' => $key, 'label' => $label, 'rule' => 'year'], $text),
                'date', 'money', 'number' => EmployeeInput::fieldError(['key' => $key, 'label' => $label, 'type' => $field['type']], $text),
                default => $field['cap'] !== null && mb_strlen($text) > $field['cap'] ? "{$label} must be at most {$field['cap']} characters." : null,
            };

            if ($message !== null) {
                $errors[$key] = $message;
            }
        }

        $before = static function (string $later, string $earlier) use ($row, $errors): bool {
            $to = EmployeeInput::text($row[$later] ?? '');
            $from = EmployeeInput::text($row[$earlier] ?? '');

            // Years are four digits and dates YYYY-MM-DD once they passed, so text order is time order.
            return $to !== '' && $from !== '' && ! isset($errors[$later]) && ! isset($errors[$earlier]) && $to < $from;
        };

        $rules = match ($list) {
            'education' => [
                ['attended_to', 'attended_from', 'Attended to cannot be before Attended from.'],
                ['year_graduated', 'attended_from', 'Year graduated cannot be before Attended from.'],
            ],
            'eligibility' => [['license_valid_until', 'exam_date', 'Valid until cannot be before the date of exam.']],
            default => [['date_to', 'date_from', 'To cannot be before From.']],
        };

        foreach ($rules as [$later, $earlier, $message]) {
            if ($before($later, $earlier)) {
                $errors[$later] = $message;
            }
        }

        return $errors;
    }

    /**
     * One row as stored, every field present: blank is NULL, a year an
     * integer, a date its `YYYY-MM-DD` key, an amount or hours rounded to
     * the centavo through Money::round() (the one rounding helper), Gov't
     * service Y/N true/false. A caller MUST have run errors() first.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function columns(string $list, array $row): array
    {
        $columns = [];

        foreach (self::fields($list) as $field) {
            $key = $field['key'];
            $text = EmployeeInput::text($row[$key] ?? '');

            $columns[$key] = match (true) {
                $text === '' => null,
                $key === 'government_service' => $text === 'Y',
                $field['type'] === 'year' => (int) $text,
                $field['type'] === 'money', $field['type'] === 'number' => Money::round((float) $text),
                default => $text,
            };
        }

        return $columns;
    }

    /**
     * The first problem among a hire's rows of one list, named by the row's
     * place in the wizard ("School 2: Name of School is required." for the
     * second row drawn, even when the first was left blank), or the cap.
     * Rows left entirely blank are not rows (rows() drops them) and are
     * neither checked nor counted.
     */
    public static function rowsError(string $list, mixed $rows): ?string
    {
        if ($rows === null || $rows === []) {
            return null;
        }

        if (! is_array($rows)) {
            return self::spec($list)['title'].' must be a list.';
        }

        if (count(self::kept($list, $rows)) > self::MAX_ROWS) {
            return self::capMessage($list);
        }

        foreach (self::rowErrors($list, $rows) as $index => $errors) {
            return ucfirst(self::spec($list)['one']).' '.($index + 1).': '.reset($errors);
        }

        return null;
    }

    /**
     * The field problems of each failing row of a hire's list, keyed by the
     * row's place in the wizard and then by field, so the wizard draws each
     * message under its own input. Rows left entirely blank are skipped.
     *
     * @return array<int, non-empty-array<string, string>>
     */
    public static function rowErrors(string $list, mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $failing = [];

        foreach (self::kept($list, $rows) as $index => $row) {
            $errors = self::errors($list, $row);

            if ($errors !== []) {
                $failing[$index] = $errors;
            }
        }

        return $failing;
    }

    /**
     * A hire's rows of one list as stored (columns()), in the order given,
     * rows left entirely blank dropped: an untouched draft is not a row. A
     * caller MUST have run rowsError() first.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $list, mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(static fn (array $row): array => self::columns($list, $row), self::kept($list, $rows)));
    }

    /**
     * The words a row is named by, in the row's menu and the delete
     * confirm: the school, the eligibility, "position, company", the title
     * or the organization.
     *
     * @param  array<string, mixed>  $row
     */
    public static function label(string $list, array $row): string
    {
        $text = static fn (string $key): string => EmployeeInput::text($row[$key] ?? '');

        return match ($list) {
            'education' => $text('school'),
            'eligibility' => $text('name'),
            'experience' => implode(', ', array_filter([$text('position_title'), $text('company')], static fn (string $part): bool => $part !== '')),
            'training' => $text('title'),
            'voluntary' => $text('organization'),
            default => '',
        };
    }

    /** The card's subtitle naming the employee, without a doubled full stop after a name ending in "Jr.". */
    public static function subtitle(string $list, string $name): string
    {
        return (string) preg_replace('/\.\.$/', '.', str_replace('{name}', $name, self::spec($list)['subtitle']));
    }

    /** "At most 100 schools can be listed." */
    public static function capMessage(string $list): string
    {
        return 'At most '.self::MAX_ROWS.' '.self::spec($list)['noun'].' can be listed.';
    }

    /**
     * The rows that carry an answer, keyed by their place in the list as
     * given (0 for the first row drawn). A level alone is not an answer: a
     * new education row opens on one. Anything that is not a row is kept
     * as an empty one, so it fails as one.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function kept(string $list, array $rows): array
    {
        $keys = array_column(self::fields($list), 'key');
        $kept = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                $kept[$index] = [];

                continue;
            }

            foreach ($keys as $key) {
                if ($key !== 'level' && EmployeeInput::text($row[$key] ?? '') !== '') {
                    $kept[$index] = $row;

                    continue 2;
                }
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $labels): array
    {
        $options = [];

        foreach ($labels as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }
}
