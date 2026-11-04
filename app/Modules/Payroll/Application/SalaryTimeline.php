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
}
