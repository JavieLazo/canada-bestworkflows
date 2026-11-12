<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeaveEligibility;
use App\Modules\Workforce\Contracts\EmployeeLeaveFacts;
use App\Support\HrisFormat;

/**
 * WHO MAY USE THE LEAVE TYPES NOT EVERYONE MAY (LeaveEligibility), applied
 * to employees: for each restricted type, the day the employee became
 * eligible — the waiting period counts from it, and the type is not theirs
 * on any date before it — or nothing while they are not, with why. Judged
 * on the employment status and sex their record holds TODAY, read through
 * Workforce's EmployeeLeaveFacts; the status timeline is read only when a
 * type is restricted by status.
 *
 * A type open to everyone never appears, so every reader treats a missing
 * type as everyone's. LeaveRules hands the days to the terms (LeaveTerms),
 * which filing, approval, the earning job and payroll's charges all read;
 * the cash conversion and the screens ask here directly.
 */
final class LeaveEligibilities
{
    private function __construct() {}

    /**
     * Each employee's standing on each restricted type: `since` is the day
     * they became eligible, null while they are not, and `refused_by` why
     * not (LeaveEligibility::BY_STATUS or BY_SEX).
     *
     * @param  list<string>  $employeeIds
     * @param  array<string, array{date_hired: string, employment_status: ?string, employment_status_id: ?string, sex: ?string}>|null  $facts  EmployeeLeaveFacts::forPosting rows the caller already holds
     * @param  array<string, LeaveEligibility>|null  $restricted  LeaveTypeCreditRecords::restricted(), when the caller already holds it
     * @return array<string, array<string, array{since: ?string, refused_by: ?string}>> employee id => token => standing; employees with no record are left out
     */
    public static function forEmployees(array $employeeIds, ?array $facts = null, ?array $restricted = null): array
    {
        $restricted ??= LeaveTypeCreditRecords::restricted();
        if ($employeeIds === [] || $restricted === []) {
            return [];
        }
        $facts ??= app(EmployeeLeaveFacts::class)->forPosting($employeeIds);
        $byStatus = array_filter($restricted, static fn (LeaveEligibility $rule): bool => $rule->statuses !== null) !== [];
        $timelines = $byStatus ? app(EmployeeLeaveFacts::class)->statusTimelines(array_values(array_filter($employeeIds, static fn (string $id): bool => isset($facts[$id])))) : [];
        $out = [];
        foreach ($employeeIds as $employeeId) {
            $employee = $facts[$employeeId] ?? null;
            if ($employee === null) {
                continue;
            }
            $statusId = $employee['employment_status_id'];
            // The record's status today ends the timeline, whatever the
            // history holds: it is what eligibility is judged on.
            $timeline = $timelines[$employeeId] ?? [];
            $last = array_pop($timeline);
            $timeline[] = ['from' => $last['from'] ?? $employee['date_hired'], 'status_id' => $statusId];
            foreach ($restricted as $token => $rule) {
                $out[$employeeId][$token] = [
                    'since' => $rule->since($employee['date_hired'], $timeline, $employee['sex']),
                    'refused_by' => $rule->refusedBy($statusId, $employee['sex']),
                ];
            }
        }

        return $out;
    }

    /**
     * Why the employee may not use the type from `$start`, or null when they
     * may: not eligible at all, or eligible only from a later day.
     */
    public static function refusal(string $employeeId, string $token, string $start): ?string
    {
        $entry = LeaveTypeCreditRecords::rules()[$token] ?? null;
        if ($entry === null || $entry['rules']->eligibility->isEveryone()) {
            return null;
        }
        $facts = app(EmployeeLeaveFacts::class)->forPosting([$employeeId]);
        $standing = self::forEmployees([$employeeId], $facts, [$token => $entry['rules']->eligibility])[$employeeId][$token] ?? null;
        if ($standing === null) {
            return null;
        }
        if ($standing['since'] === null) {
            return self::notEligible($entry['name'], $entry['rules']->eligibility, $standing['refused_by'], $facts[$employeeId] ?? null);
        }
        if ($standing['since'] > $start) {
            return "{$entry['name']} is available from ".HrisFormat::date($standing['since']).'. Choose dates from that day on.';
        }

        return null;
    }

    /**
     * "Vacation Leave is not available to Job Order employees." — why an
     * employee may not use a type at all.
     *
     * @param  array{employment_status: ?string, employment_status_id: ?string, sex: ?string}|null  $employee
     */
    public static function notEligible(string $name, LeaveEligibility $rule, ?string $refusedBy, ?array $employee): string
    {
        if ($refusedBy === LeaveEligibility::BY_SEX) {
            $who = strtolower(LeaveEligibility::SEX_LABELS[(string) $rule->sex] ?? 'some employees only');

            return "{$name} is for {$who}.".(($employee['sex'] ?? null) === null ? ' No sex is recorded on this employee\'s record.' : '');
        }
        $status = $employee['employment_status'] ?? null;
        if ($status === null) {
            return "{$name} is not available to an employee with no employment status on record.";
        }

        return ($employee['employment_status_id'] ?? null) === null
            ? "{$name} is not available to this employee: their employment status, {$status}, is not on the Employment list."
            : "{$name} is not available to {$status} employees.";
    }
}
