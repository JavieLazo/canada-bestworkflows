<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which of an employee's two identifiers a surface shows, in one place.
 *
 * There are two on purpose, and they are not interchangeable:
 *
 *   employee_code    the ROW ID. Minted by CreateEmployee as a bare number
 *                    (`42`), never null, unique, and what every list sorts on,
 *                    through {@see CodeSort} because bare numbers do not sort
 *                    as text. The system owns it; the UI labels it "ID".
 *   employee_id_no   the EMPLOYEE ID. Typed by HR, because the company assigns
 *                    it on paper. NULLABLE — it arrives late, and some staff
 *                    never get one — and unique when it is there.
 *
 * WHICH ONE A SURFACE SHOWS IS NOT A FREE CHOICE:
 *
 *   - A column or field that names the row id ("ID") shows {@see index()}.
 *     Employee Records, the roster list, My Portal, My Profile. NOT the 201
 *     page: it has no ID field, because nobody types a minted id and a
 *     disabled box is not worth a save-side guard. The list is where the row
 *     id is read.
 *   - A column or field that names the person's own number ("Employee ID")
 *     shows {@see number()}, and an em dash when they have none.
 *   - EVERYWHERE AN IDENTIFIER SITS BESIDE A NAME — a picker row, a checklist,
 *     a select option, the eDTR header, a payroll line — it is the Employee ID
 *     or NOTHING AT ALL: {@see besideName()}. The row id is an internal key and
 *     never introduces a person. This is the rule the rest of this class exists
 *     to keep, and it is not a per-screen taste.
 *
 * The nullability is the whole reason the class exists. `employee_code` is a
 * NOT NULL varchar, so the idioms written for it — `!== '' ? … : '—'`, or a
 * bare echo with a ' · ' separator after it — assume a value. Neither
 * survives a null: the first prints an empty cell because `null !== ''` is
 * true, and the second leaves a dangling separator in front of the position.
 * {@see meta()} is the answer to the second — it joins only the parts that
 * are actually there.
 *
 * {@see EmployeeName} is the same argument about how a name is spelled, and
 * says why a second copy is the thing to avoid rather than the inconsistency
 * itself.
 *
 * Accepts a model, a `Record` projection, a stdClass from a raw select or a
 * plain array row — the same shapes EmployeeName accepts, for the same reason.
 */
final class EmployeeIdentity
{
    /** The em dash every "nothing here" cell already uses. */
    public const string NONE = EmployeeName::NONE;

    /** The separator between the parts of a one-line summary under a name. */
    public const string SEPARATOR = ' · ';

    /**
     * The hand-typed Employee ID alone — the 201 field, the Employee Records
     * column, the payslip header. An em dash when the company has not given
     * this person a number yet.
     *
     * @param  object|array<string, mixed>|null  $employee
     */
    public static function number(object|array|null $employee, string $fallback = self::NONE): string
    {
        $value = self::part($employee, 'employee_id_no');

        return $value === '' ? $fallback : $value;
    }

    /** The row id alone, for a column or field that names it "ID".
     *
     * @param  object|array<string, mixed>|null  $employee
     */
    public static function index(object|array|null $employee, string $fallback = self::NONE): string
    {
        $value = self::part($employee, 'employee_code');

        return $value === '' ? $fallback : $value;
    }

    /**
     * The identifier that may stand NEXT TO A NAME: the Employee ID, or the
     * empty string.
     *
     * NOT the row id, and NOT an em dash. A person with no company number is
     * introduced by their name alone — a row id beside "Juan Dela Cruz" tells
     * a reader nothing they can look up on paper, and an em dash beside a name
     * reads as a missing name rather than a missing number.
     *
     * The empty string rather than null so every caller can compare it with
     * `!== ''` and so `meta()` drops it without a special case.
     *
     * @param  object|array<string, mixed>|null  $employee
     */
    public static function besideName(object|array|null $employee): string
    {
        return self::number($employee, '');
    }

    /**
     * The one-line summary under a name — "2024-118 · Driver · inactive" —
     * built from only the parts that exist.
     *
     * A hand-written `$x ? ' · '.$x : ''` chain is correct exactly while the
     * FIRST part is never empty, and `besideName()` can be empty, so the join
     * lives here: pass the parts in order and a missing Employee ID costs its
     * separator too, rather than leaving the line opening with " · ".
     */
    public static function meta(?string ...$parts): string
    {
        $kept = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $kept[] = $part;
            }
        }

        return implode(self::SEPARATOR, $kept);
    }

    /**
     * A select option or picker row: "2024-118 — Juan Dela Cruz", and the name
     * alone for someone with no company number.
     *
     * The name is passed in rather than spelled here because each surface
     * already has its own speller (`EmployeeName::display`, `Hris::employeeName`,
     * `PayrollData::employeeName`) and they are not interchangeable.
     *
     * @param  object|array<string, mixed>|null  $employee
     */
    public static function option(object|array|null $employee, string $name): string
    {
        $number = self::besideName($employee);

        return $number === '' ? $name : "{$number} — {$name}";
    }

    /**
     * @param  object|array<string, mixed>|null  $employee
     */
    private static function part(object|array|null $employee, string $key): string
    {
        if ($employee === null) {
            return '';
        }

        $value = is_array($employee)
            ? ($employee[$key] ?? null)
            : ($employee->{$key} ?? null);

        return is_string($value) ? trim($value) : '';
    }
}
