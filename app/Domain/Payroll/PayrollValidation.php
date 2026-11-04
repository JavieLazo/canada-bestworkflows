<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use DateTimeInterface;

/**
 * A port of the referential rules in copems/src/lib/payroll-validation.ts.
 *
 * The reference mixes three pure decisions with the Prisma queries that feed
 * them. The queries belong to the action layer (and change shape anyway: MySQL
 * has no array column, so `payroll_runs.employee_ids` becomes the
 * payroll_run_members pivot). The decisions and their wording live here, pure,
 * so the draft screen and the write path cannot drift apart on what a payroll
 * may contain.
 *
 * The reference throws `ApiError(status, message)` from each `require*`; these
 * return the message instead, because a domain class in this namespace owns no
 * HTTP. Non-null means refuse.
 *
 * Membership is checked under a lock in both systems. The reference takes
 * `pg_advisory_xact_lock(hashtext('copems:payroll-membership'))`; here
 * PayrollData::lockMembership() takes an InnoDB row lock on the `locks` row
 * named MEMBERSHIP_LOCK, which is likewise released on commit AND on rollback.
 */
final class PayrollValidation
{
    /** TS: lockPayrollMembership()'s advisory-lock key, verbatim. */
    public const MEMBERSHIP_LOCK = 'copems:payroll-membership';

    public const MESSAGE_DUPLICATE_EMPLOYEE = 'An employee can only appear once on a payroll.';

    public const MESSAGE_MISSING_EMPLOYEE = 'One or more selected employees no longer exist.';

    public const MESSAGE_OUTSIDE_EMPLOYMENT = 'Every selected employee must overlap the payroll period.';

    public const MESSAGE_UNKNOWN_OVERRIDE = 'Every draft adjustment must belong to a selected employee.';

    /**
     * TS: requirePayrollEmployees() — everything it decides once the rows are loaded.
     *
     * The caller does the fetch (`where id in (...)`) and passes the rows; this
     * checks them in the reference's own order: duplicates, then existence, then
     * employment overlap. An empty selection is allowed, exactly as the reference
     * returns [] before doing any work.
     *
     * `$employees` rows need `id`, `date_hired` and `date_resigned`. Dates may be
     * DateTimeInterface (an Eloquent DATE cast) or an already-formatted
     * 'YYYY-MM-DD' string; both resolve through PayrollPeriod::dateKeyFromDate,
     * the port of the reference's `dateKeyFromDate`.
     *
     * @param  list<string>  $employeeIds
     * @param  iterable<mixed>  $employees
     */
    public static function employeesError(
        array $employeeIds,
        iterable $employees,
        string $periodStart,
        string $periodEnd,
    ): ?string {
        if (count($employeeIds) === 0) {
            return null;
        }

        if (count(array_unique($employeeIds, SORT_STRING)) !== count($employeeIds)) {
            return self::MESSAGE_DUPLICATE_EMPLOYEE;
        }

        $found = [];
        $outsideEmployment = false;
        foreach ($employees as $employee) {
            $found[(string) self::read($employee, 'id')] = true;

            $hired = self::dayValue(self::read($employee, 'date_hired', 'dateHired'));
            $resignedRaw = self::read($employee, 'date_resigned', 'dateResigned');
            $resigned = $resignedRaw === null ? null : self::dayValue($resignedRaw);

            if ($hired > $periodEnd || ($resigned !== null && $resigned < $periodStart)) {
                $outsideEmployment = true;
            }
        }

        foreach ($employeeIds as $id) {
            if (! isset($found[(string) $id])) {
                return self::MESSAGE_MISSING_EMPLOYEE;
            }
        }

        return $outsideEmployment ? self::MESSAGE_OUTSIDE_EMPLOYMENT : null;
    }

    /**
     * TS: findUnknownPayrollOverrideEmployee().
     *
     * The first employee id carried by a draft-adjustment map that is not on the
     * payroll, or null. Maps are keyed by employee id — draftDeductions,
     * draftAllowances, draftEarnings.
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, mixed>  ...$overrideMaps
     */
    public static function findUnknownOverrideEmployee(array $employeeIds, array ...$overrideMaps): ?string
    {
        $members = [];
        foreach ($employeeIds as $id) {
            $members[(string) $id] = true;
        }

        foreach ($overrideMaps as $overrides) {
            foreach (array_keys($overrides) as $employeeId) {
                if (! isset($members[(string) $employeeId])) {
                    return (string) $employeeId;
                }
            }
        }

        return null;
    }

    /**
     * TS: requirePayrollOverrideMembers() — ApiError(400) when an adjustment names
     * someone who is not on the run.
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, mixed>  ...$overrideMaps
     */
    public static function overrideMembersError(array $employeeIds, array ...$overrideMaps): ?string
    {
        return self::findUnknownOverrideEmployee($employeeIds, ...$overrideMaps) === null
            ? null
            : self::MESSAGE_UNKNOWN_OVERRIDE;
    }

    /**
     * TS: findPayrollMembershipConflict().
     *
     * One employee may not receive more than one payroll for the same cut-off.
     * The caller passes the other runs for this exact period (ordered by
     * created_at ascending, as the reference queries them), each carrying its own
     * member ids; the first run holding any selected employee wins.
     *
     * PORTED QUIRK: the employee reported is the first one in THAT RUN's member
     * list, not the first in the selection — the reference searches
     * `run.employee_ids`, not `employeeIds`. It decides which name appears in the
     * 409, so keep the iteration in this order.
     *
     * @param  list<string>  $employeeIds
     * @param  iterable<mixed>  $candidates  rows with `code` and `employee_ids`
     * @return array{employeeId: string, runCode: string}|null
     */
    public static function findMembershipConflict(array $employeeIds, iterable $candidates): ?array
    {
        $selected = [];
        foreach ($employeeIds as $id) {
            $selected[(string) $id] = true;
        }

        foreach ($candidates as $run) {
            /** @var iterable<mixed> $members */
            $members = self::read($run, 'employee_ids', 'employeeIds') ?? [];
            foreach ($members as $employeeId) {
                if (isset($selected[(string) $employeeId])) {
                    return [
                        'employeeId' => (string) $employeeId,
                        'runCode' => (string) self::read($run, 'code'),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * TS: the ApiError(409) thrown by requireUniquePayrollMembership().
     *
     * `$employeeName` is `"{$first_name} {$last_name}"` when the conflicting
     * employee row was found, and null when it was not — the reference falls back
     * to "That employee" rather than failing the lookup. No trimming: the
     * reference joins the two names with a single space, whatever they hold.
     */
    public static function membershipConflictMessage(?string $employeeName, string $runCode): string
    {
        $name = $employeeName ?? 'That employee';

        return "{$name} is already included in {$runCode} for this cut-off.";
    }

    /** A DATE column as its 'YYYY-MM-DD' key; an already-formatted string passes through. */
    private static function dayValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface
            ? PayrollPeriod::dateKeyFromDate($value)
            : (string) $value;
    }

    /**
     * Read one field off a row that may be an array, a DTO or an Eloquent model.
     * The first key that is present wins; a present null is returned as null.
     */
    private static function read(mixed $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($row)) {
                if (array_key_exists($key, $row)) {
                    return $row[$key];
                }

                continue;
            }
            if (is_object($row) && (isset($row->{$key}) || property_exists($row, $key))) {
                return $row->{$key};
            }
        }

        return null;
    }
}
