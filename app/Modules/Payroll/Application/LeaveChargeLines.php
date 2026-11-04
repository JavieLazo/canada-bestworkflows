<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\Shift;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll's side of the leave-credit charges (DELIBERATE DIVERGENCE 15):
 * the day facts it hands Timekeeping's charge planner — read from the SAME
 * DTR rows the engine prices — and the planner's lines turned into what the
 * engine takes (the covered fraction of each late day's minutes, the
 * covered part of each absent day, the late days charged in all) for one
 * date range at a time: the payslip's own period, and each settlement's
 * days on their own.
 */
final class LeaveChargeLines
{
    private function __construct() {}

    /** Whether payslips can carry what they charged (2026_10_31_0006 has run). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('payslips', 'leave_charged_days'));
    }

    /**
     * The facts the planner reads from the rows: a day's lateness and
     * undertime minutes when the day is PRESENT or LATE (the engine prices
     * only those — an INCOMPLETE day's open Time In is an absence, never
     * late), and whether the day is an absence by the one per-day test the
     * payslip counts with.
     *
     * @param  list<DayRecord>  $days
     * @return list<array{date: string, late_minutes: float, undertime_minutes: float, absence_days: float}>
     */
    public static function dayFacts(array $days, ?Shift $schedule): array
    {
        $schedule ??= Shift::default();
        $facts = [];
        foreach ($days as $day) {
            $priced = $day->status === DayRecord::PRESENT || $day->status === DayRecord::LATE;
            $facts[] = [
                'date' => $day->date,
                'late_minutes' => $priced ? $day->lateMinutes : 0.0,
                'undertime_minutes' => $priced ? $day->undertimeMinutes : 0.0,
                'absence_days' => $day->isAbsenceDay($schedule) ? 1.0 : 0.0,
            ];
        }

        return $facts;
    }

    /**
     * @param  list<array{date: string, kind: string, minutes: ?int, days: float, covered: array<string, float>, uncovered_days: float, fraction: float}>  $lines
     * @return array{late: array<string, float>, absence: array<string, float>, late_days: float}
     */
    public static function engineShape(array $lines, string $from, string $to): array
    {
        $late = [];
        $absence = [];
        $lateDays = 0.0;
        foreach ($lines as $line) {
            if (strcmp($line['date'], $from) < 0 || strcmp($line['date'], $to) > 0) {
                continue;
            }
            $coveredDays = $line['days'] - $line['uncovered_days'];
            if ($coveredDays <= 0) {
                continue;
            }
            if ($line['kind'] === 'LATE') {
                $late[$line['date']] = $line['fraction'];
                $lateDays += $coveredDays;
            } else {
                $absence[$line['date']] = $coveredDays;
            }
        }

        return ['late' => $late, 'absence' => $absence, 'late_days' => $lateDays];
    }
}
