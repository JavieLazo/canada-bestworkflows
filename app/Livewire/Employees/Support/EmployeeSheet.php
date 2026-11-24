<?php

declare(strict_types=1);

namespace App\Livewire\Employees\Support;

use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use Carbon\CarbonImmutable;

/**
 * How the printed 201 file spells its values — the presentation half of
 * `livewire/employees/partials/sheet`, the print-only part of the record
 * page that lays out the company's single-page personnel record.
 *
 * Every value is a plain string for a labelled field: '' where the record
 * holds nothing, so the field prints empty rather than "—". Dates are spelled
 * out in full, "August 1, 2026"; the record's text stays as typed. The
 * paper form's license and passport lines are not printed at all: those are
 * documents on the Documents card.
 *
 * Presentation only: it reads the detached view the record page already
 * holds and computes nothing its cards do not (age, the measures and the
 * civil status, citizenship, home address, spouse and College / Graduate
 * Studies lines come from EmployeeFields, so the sheet prefers the
 * structured values and falls back to the stored legacy text the way the
 * cards do). The height stays in centimetres, as the record stores it.
 */
final class EmployeeSheet
{
    /** The sheet has three ruled lines for children; more children add lines. */
    public const int CHILD_LINES = 3;

    /** The spouse's name line: the spouse typed in parts, else the stored Spouse's Name. */
    public static function spouseName(EmployeeProfileView $employee): string
    {
        return EmployeeFields::spouseNameText($employee);
    }

    /** The spouse's employment line: occupation and employer, else the stored Spouse's Employment. */
    public static function spouseEmployment(EmployeeProfileView $employee): string
    {
        return EmployeeFields::spouseEmploymentText($employee);
    }

    /**
     * The College or Graduate Studies lines: the latest school of that
     * level, else the stored lines.
     *
     * @return array{school: string, course: string, year: string}
     */
    public static function education(EmployeeProfileView $employee, string $level): array
    {
        return EmployeeFields::educationFallback($employee, $level);
    }

    /** A text value, or '' for a blank line. */
    public static function text(?string $value): string
    {
        return trim((string) $value);
    }

    /**
     * The e-mail line as escaped HTML: the address with a break opportunity
     * (`<wbr>`) before each "@" and ".", so one too long for its field wraps
     * there — "rosa.villanueva" / "@company.com" — rather than mid-word,
     * which is where the sheet's last-resort `overflow-wrap` would cut it.
     * '' for a blank line.
     */
    public static function email(?string $value): string
    {
        return (string) preg_replace('/(?=[@.])/', '<wbr>', e(self::text($value)));
    }

    /** `2026-08-01` → "August 1, 2026"; '' when unset. */
    public static function date(CarbonImmutable|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof CarbonImmutable) {
            return $value->format('F j, Y');
        }

        // A `YYYY-MM-DD` key (a child's birthdate off the detached view).
        return EmployeeFields::isCalendarDay($value) ? CarbonImmutable::parse($value)->format('F j, Y') : '';
    }

    /** 178.00 → "178 cm"; '' when unset. */
    public static function measure(?float $value, string $unit): string
    {
        return $value === null ? '' : EmployeeFields::measure($value).' '.$unit;
    }

    /**
     * The civil status line: "Other — <specification>" for Other/s, any other
     * status exactly as stored.
     */
    public static function civilStatus(EmployeeProfileView $employee): string
    {
        return $employee->civil_status === 'Other'
            ? EmployeeFields::civilStatusText($employee)
            : self::text($employee->civil_status);
    }

    /** The citizenship line: the chosen citizenship, else the stored text. */
    public static function citizenship(EmployeeProfileView $employee): string
    {
        return EmployeeFields::citizenshipText($employee);
    }

    /** The home address line: the residential address typed in parts, else the stored one-line address. */
    public static function homeAddress(EmployeeProfileView $employee): string
    {
        return EmployeeFields::residentialText($employee);
    }

    /** The age line: EmployeeFields' local-calendar age, '' when the birthday is unset. */
    public static function age(EmployeeProfileView $employee): string
    {
        $age = EmployeeFields::ageFrom($employee->birth_date);

        return $age === '—' ? '' : $age;
    }

    /**
     * The children's lines: at least CHILD_LINES rows so the blank sheet
     * keeps its ruled lines, each `[name, birthdate]` spelled for the sheet.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function children(EmployeeProfileView $employee): array
    {
        $rows = [];

        foreach (EmployeeFields::childRows($employee) as $child) {
            $rows[] = [$child['name'], self::date($child['birth_date'])];
        }

        while (count($rows) < self::CHILD_LINES) {
            $rows[] = ['', ''];
        }

        return $rows;
    }

    /** "No. of children": the count when any are listed, else a blank line. */
    public static function childCount(EmployeeProfileView $employee): string
    {
        $count = count(EmployeeFields::childRows($employee));

        return $count === 0 ? '' : (string) $count;
    }
}
