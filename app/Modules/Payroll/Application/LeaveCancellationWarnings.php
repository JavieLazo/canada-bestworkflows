<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

/** Warns from the same captured facts as the preview so typed Leave pay cannot hide a cancellation. */
final class LeaveCancellationWarnings
{
    /** @return list<array{employee_id: string, dates: list<string>, overridden: bool}> */
    public static function forInputs(?PayrollInputs $inputs): array
    {
        if ($inputs === null) {
            return [];
        }
        $warnings = [];
        foreach ($inputs->employeeIds as $employeeId) {
            $dates = [];
            foreach ($inputs->attendance[$employeeId]->leaves as $leave) {
                foreach ($leave->cancelledDates as $date) {
                    if ($date >= $inputs->periodStart && $date <= $inputs->periodEnd) {
                        $dates[$date] = true;
                    }
                }
            }
            if ($dates !== []) {
                $dates = array_keys($dates);
                sort($dates);
                $warnings[] = [
                    'employee_id' => $employeeId,
                    'dates' => $dates,
                    'overridden' => ($inputs->earnings[$employeeId]['leave_pay'] ?? null) !== null,
                ];
            }
        }

        return $warnings;
    }
}
