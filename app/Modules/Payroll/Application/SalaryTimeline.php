<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Modules\Workforce\Contracts\EmployeePayData;
use App\Modules\Workforce\Contracts\SalaryHistory;
use App\Support\Access;

/**
 * Each member's salary over the period as payroll reads it (DIVERGENCE
 * 18): the approved version in force on the period's first day becomes the
 * member's `monthly_rate` / `daily_rate` — the figures the evidence,
 * the contribution base and the reported daily rate stand on — and every
 * later version taking over inside the period rides along as a dated
 * segment the engine prices from its date. A member with no version keeps
 * the record's rates and has no segments, which is the reference's shape.
 * And each member's highest salary on record, which terminal leave is
 * priced from.
 */
final class SalaryTimeline
{
    private function __construct() {}

    /**
     * @param  list<EmployeePayData>  $employees
     * @return list<EmployeePayData>
     */
    public static function overlay(Access $actor, array $employees, string $periodStart, string $periodEnd): array
    {
        if ($employees === []) {
            return $employees;
        }
        $segments = app(SalaryHistory::class)->segments($actor, array_map(static fn (EmployeePayData $e): string => $e->id, $employees), $periodStart, $periodEnd);
        if ($segments === []) {
            return $employees;
        }
        $out = [];
        foreach ($employees as $employee) {
            $list = $segments[$employee->id] ?? [];
            if ($list === []) {
                $out[] = $employee;

                continue;
            }
            $monthly = $employee->monthly_rate;
            $daily = $employee->daily_rate;
            $later = [];
            foreach ($list as $segment) {
                if ($segment['from'] <= $periodStart) {
                    // The version in force on the first day: the member's rates.
                    $monthly = $segment['monthly_rate'];
                    $daily = $segment['daily_rate'];
                } else {
                    $later[] = $segment;
                }
            }
            $out[] = $employee->withSalary($monthly, $daily, $later);
        }

        return $out;
    }

    /**
     * The HIGHEST salary on record for each of `$employeeIds` — what
     * terminal leave is priced from: of the approved versions that took
     * effect by the employee's Date Resigned (by the period's end when
     * none is set), the one with the highest monthly rate, the latest on a
     * tie; with no monthly rate on any, the one with the highest daily
     * rate. An employee with no version has the record's rates, undated.
     *
     * @param  list<EmployeePayData>  $employees  as read for the run
     * @param  list<string>  $employeeIds
     * @return array<string, array{from: ?string, monthly_rate: ?float, daily_rate: ?float}>
     */
    public static function highest(Access $actor, array $employees, array $employeeIds, string $periodEnd): array
    {
        $out = [];
        foreach ($employees as $employee) {
            if (! in_array($employee->id, $employeeIds, true)) {
                continue;
            }
            $through = $employee->date_resigned?->format('Y-m-d') ?? $periodEnd;
            $versions = app(SalaryHistory::class)->segments($actor, [$employee->id], '0001-01-01', $through)[$employee->id] ?? [];
            $best = null;
            foreach ($versions as $version) {
                $key = [(float) ($version['monthly_rate'] ?? 0.0), (float) ($version['daily_rate'] ?? 0.0)];
                if ($best === null || $key >= $best['key']) {
                    $best = ['key' => $key, 'version' => $version];
                }
            }
            $out[$employee->id] = $best === null
                ? ['from' => null, 'monthly_rate' => $employee->monthly_rate, 'daily_rate' => $employee->daily_rate]
                : ['from' => $best['version']['from'], 'monthly_rate' => $best['version']['monthly_rate'], 'daily_rate' => $best['version']['daily_rate']];
        }

        return $out;
    }
}
