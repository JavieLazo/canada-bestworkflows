<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How a person's name is spelled, in one place.
 *
 * It was in six. `EmployeeFields::fullName()` composed every part including the
 * suffix; five separate `employeeName()` helpers — Employees, Roster, Payroll,
 * Portal, eDTR — each composed first + last from their own three-line copy, and
 * two Blade files declared the same closure inline. When `suffix` was added to
 * `employee_profiles` only the first of those learned about it, so the field
 * could be entered on Employee Records and appeared nowhere else in the system.
 *
 * The five helpers and the two closures now delegate here. The point is not
 * tidiness: it is that the next name part cannot be half-adopted, because there
 * is no longer a second place to forget.
 *
 * TWO SPELLINGS, DELIBERATELY.
 *
 *   full()     first + middle + last + suffix. The 201 record's own header,
 *              where the whole legal name belongs.
 *   display()  first + last + suffix. Every list, table, label, alt text,
 *              toast and audit line. A middle name in a table column buys
 *              nothing and costs the width that the surname needs.
 *
 * The suffix rides with BOTH, because it is part of how a person is addressed —
 * two brothers at the same company are "Dela Cruz" and "Dela Cruz Jr.", and a
 * table that drops it names them identically.
 *
 * Accepts a model, a stdClass from a raw select, or a plain array row: the
 * reminder sweep works on array projections and the payroll grid on stdClass,
 * and both spell names the same way as the pages do.
 */
final class EmployeeName
{
    /** The em dash every caller already used for "no employee". */
    public const string NONE = '—';

    /**
     * Every part, in order — the Employee Records header spelling.
     *
     * @param  object|array<string, mixed>|null  $employee
     */
    public static function full(object|array|null $employee, string $fallback = self::NONE): string
    {
        if ($employee === null) {
            return $fallback;
        }

        return self::join([
            self::part($employee, 'first_name'),
            self::part($employee, 'middle_name'),
            self::part($employee, 'last_name'),
            self::part($employee, 'suffix'),
        ], $fallback);
    }

    /**
     * first + last + suffix — the spelling every other surface uses.
     *
     * `$fallback` exists because the reminder sweep says "an employee" rather
     * than an em dash when an id resolves to nothing (notify.ts), and a
     * notification body reading "— filed a leave" would be worse than useless.
     *
     * @param  object|array<string, mixed>|null  $employee
     */
    public static function display(object|array|null $employee, string $fallback = self::NONE): string
    {
        if ($employee === null) {
            return $fallback;
        }

        return self::join([
            self::part($employee, 'first_name'),
            self::part($employee, 'last_name'),
            self::part($employee, 'suffix'),
        ], $fallback);
    }

    /**
     * First and last initials.
     *
     * NO SUFFIX: an avatar reads "JD", never "JDJ". A suffix is not a name
     * part for this purpose — it distinguishes two people who share one.
     *
     * @param  object|array<string, mixed>|null  $employee
     */
    public static function initials(object|array|null $employee): string
    {
        if ($employee === null) {
            return '';
        }

        return mb_strtoupper(
            mb_substr(self::part($employee, 'first_name'), 0, 1)
            .mb_substr(self::part($employee, 'last_name'), 0, 1)
        );
    }

    /**
     * @param  list<string>  $parts
     */
    private static function join(array $parts, string $fallback): string
    {
        $name = trim(implode(' ', array_filter(
            $parts,
            static fn (string $part): bool => $part !== '',
        )));

        // A row with every name column empty is a broken record, not a person
        // called "". Callers get the same fallback they get for a missing one.
        return $name === '' ? $fallback : $name;
    }

    /**
     * @param  object|array<string, mixed>  $employee
     */
    private static function part(object|array $employee, string $key): string
    {
        $value = is_array($employee)
            ? ($employee[$key] ?? null)
            : ($employee->{$key} ?? null);

        return is_string($value) ? trim($value) : '';
    }
}
