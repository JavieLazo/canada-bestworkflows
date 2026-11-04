<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\EmployeeLeavePolicy;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use Illuminate\Support\Facades\Schema;

/**
 * Each employee's leave policy as the rules read it: the APPROVED version in
 * force on a date — the latest whose effective date is on or before it —
 * and nothing for an employee with none, which means today's behaviour
 * (LeavePolicy::none(): nothing earns, nothing is charged). Drafts are
 * never in force. Before the table exists (the window before the cron
 * migrates 2026_10_31_0005) nobody has a policy.
 */
final class LeavePolicyStore
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('employee_leave_policies'));
    }

    /** The approved version in force for one employee on `$date`, or null. */
    public static function inForce(string $employeeId, string $date): ?EmployeeLeavePolicy
    {
        return self::forEmployees([$employeeId], $date)[$employeeId] ?? null;
    }

    /**
     * The approved version in force on `$date` for each of these
     * employees, in one query; employees with none are absent.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, EmployeeLeavePolicy>
     */
    public static function forEmployees(array $employeeIds, string $date): array
    {
        if ($employeeIds === [] || ! self::ready()) {
            return [];
        }
        $out = [];
        $rows = EmployeeLeavePolicy::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', EmployeeLeavePolicy::APPROVED)
            ->where('effective_from', '<=', $date)
            ->orderBy('effective_from')->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $out[$row->employee_id] = $row;
        }

        return $out;
    }

    /** The policy a version holds, as the pure rules read it. */
    public static function policy(?EmployeeLeavePolicy $row): LeavePolicy
    {
        return $row === null ? LeavePolicy::none() : LeavePolicy::fromParams($row->params);
    }

    /**
     * The earliest effective date of any approved version of an employee:
     * where the earning job starts counting months. Null with none.
     */
    public static function firstEffective(string $employeeId): ?string
    {
        if (! self::ready()) {
            return null;
        }
        $from = EmployeeLeavePolicy::query()->where('employee_id', $employeeId)->where('status', EmployeeLeavePolicy::APPROVED)->min('effective_from');

        return $from === null ? null : substr((string) $from, 0, 10);
    }

    /**
     * Every employee with an approved version at all: who the earning job
     * walks.
     *
     * @return list<string>
     */
    public static function employeesWithPolicies(): array
    {
        if (! self::ready()) {
            return [];
        }

        return array_values(array_map('strval', EmployeeLeavePolicy::query()->where('status', EmployeeLeavePolicy::APPROVED)->distinct()->orderBy('employee_id')->pluck('employee_id')->all()));
    }
}
