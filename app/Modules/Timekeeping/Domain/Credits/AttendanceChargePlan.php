<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

use App\Domain\Attendance\Minutes;

/**
 * WHAT A PAYROLL CHARGES TO LEAVE CREDITS, and what is left for pay. Pure:
 * the policy's charge rules, the conversion table in use, each leave
 * type's balance and how far below zero it may go, and the period's days
 * as the Daily Time Record read them.
 *
 * Absences first, in date order: each absent day (or the part of it credits
 * have not covered yet) is taken from the absence types in the policy's
 * order, each type down to its own floor (`available + below_zero_limit`),
 * and what no type covers stays unpaid. Then lateness and undertime, by the
 * conversion table: each day's minutes on their own (DAY), or the payroll's
 * minutes added up and converted once (PAYROLL) with the covered part
 * spread over the days pro-rata — the engine prices each day's uncovered
 * minutes. Without a conversion table in use, lateness is not charged and
 * the plan says so. A day already charged by an earlier payslip is never
 * charged again.
 *
 * A LEAVE TYPE THAT WAITS IS CHARGED ONLY FROM THE DAY IT WAS ACQUIRED (a
 * balance's `from`): on an earlier day it is left out of the order, and a
 * day no type in the order is open on is not charged at all — it stays a
 * matter of pay, exactly as when charging is off, and is not marked as
 * looked at. Pooled lateness (PAYROLL) is pooled among the days that share
 * the same open types, so a day before the acquisition is never covered by
 * credits acquired after it.
 *
 * A line: `date`, `kind` (LATE | ABSENCE), `minutes` (lateness and
 * undertime together; null on an absence), `days` (what the day converts
 * to), `covered` (type => days taken, in order), `uncovered_days` and
 * `fraction` (the covered part of the day's minutes, 0..1, for the engine).
 */
final class AttendanceChargePlan
{
    public const string LATE = 'LATE';

    public const string ABSENCE = 'ABSENCE';

    private function __construct() {}

    /**
     * @param  array{enabled: bool, combine: string, late: list<string>, absence: list<string>}  $charges  LeavePolicy's charge rules
     * @param  array{rows: array{minutes: array<int|string, string|float>, hours: array<int|string, string|float>}, hours_per_day: float, decimals: int}|null  $conversion  the table in force, or null for none
     * @param  array<string, array{available: float, below_zero_limit: float, from?: ?string}>  $balances  token => the balance before this payroll, its floor and, for a type that waits, the day it was acquired
     * @param  list<array{date: string, late_minutes: float, undertime_minutes: float, absence_days: float}>  $days  the period's days; `absence_days` is the part of the day absent (0 when not)
     * @param  array{late?: list<string>, absence?: list<string>}  $charged  dates an earlier payslip already charged, by kind
     * @return array{lines: list<array{date: string, kind: string, minutes: ?int, days: float, covered: array<string, float>, uncovered_days: float, fraction: float}>, balances: array<string, float>, notes: list<string>}
     */
    public static function plan(array $charges, ?array $conversion, array $balances, array $days, array $charged = []): array
    {
        $left = [];
        foreach ($balances as $token => $balance) {
            $left[$token] = LeaveQuantity::normalize($balance['available']);
        }
        if (! $charges['enabled']) {
            return ['lines' => [], 'balances' => $left, 'notes' => []];
        }
        usort($days, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        $lines = [];
        $notes = [];
        $chargedAbsence = $charged['absence'] ?? [];
        $chargedLate = $charged['late'] ?? [];

        // Absences, in date order.
        if ($charges['absence'] !== []) {
            foreach ($days as $day) {
                if ($day['absence_days'] <= 0 || in_array($day['date'], $chargedAbsence, true)) {
                    continue;
                }
                $order = self::open($charges['absence'], $balances, $day['date']);
                if ($order === []) {
                    continue;
                }
                $need = LeaveQuantity::normalize($day['absence_days']);
                [$covered, $uncovered] = self::take($need, $left, $balances, $order);
                $lines[] = [
                    'date' => $day['date'], 'kind' => self::ABSENCE, 'minutes' => null, 'days' => $need,
                    'covered' => $covered, 'uncovered_days' => $uncovered,
                    'fraction' => $need > 0 ? LeaveQuantity::normalize(($need - $uncovered) / $need) : 0.0,
                ];
            }
        }

        // Lateness and undertime, by the conversion table.
        if ($charges['late'] !== []) {
            $lateDays = [];
            foreach ($days as $day) {
                $minutes = (int) Minutes::round($day['late_minutes'] + $day['undertime_minutes']);
                if ($minutes <= 0 || in_array($day['date'], $chargedLate, true)) {
                    continue;
                }
                $order = self::open($charges['late'], $balances, $day['date']);
                if ($order === []) {
                    continue;
                }
                $lateDays[] = ['date' => $day['date'], 'minutes' => $minutes, 'order' => $order];
            }
            if ($lateDays !== [] && $conversion === null) {
                $notes[] = 'Lateness and undertime are charged to pay: no minutes-to-days conversion table is in use.';
            } elseif ($lateDays !== []) {
                $toDays = static fn (int $minutes): float => MinuteConversion::days($minutes, $conversion['rows'], $conversion['hours_per_day'], $conversion['decimals']);
                if ($charges['combine'] === 'PAYROLL') {
                    // One pool per set of open types, in date order: with no
                    // type waiting that is one pool, the whole payroll.
                    $pools = [];
                    foreach ($lateDays as $day) {
                        $pools[implode('|', $day['order'])][] = $day;
                    }
                    foreach ($pools as $pool) {
                        $total = array_sum(array_column($pool, 'minutes'));
                        $need = $toDays($total);
                        [$covered, $uncovered] = self::take($need, $left, $balances, $pool[0]['order']);
                        $fraction = $need > 0 ? LeaveQuantity::normalize(($need - $uncovered) / $need) : 0.0;
                        foreach ($pool as $index => $day) {
                            // The pooled coverage, spread over the days by their minutes; the covered
                            // types are listed on the first line so the ledger charges them once.
                            $share = $need > 0 ? LeaveQuantity::normalize($need * $day['minutes'] / $total) : 0.0;
                            $lines[] = [
                                'date' => $day['date'], 'kind' => self::LATE, 'minutes' => $day['minutes'], 'days' => $share,
                                'covered' => $index === 0 ? $covered : [],
                                'uncovered_days' => LeaveQuantity::normalize($share * (1 - $fraction)),
                                'fraction' => $fraction,
                            ];
                        }
                    }
                } else {
                    foreach ($lateDays as $day) {
                        $need = $toDays($day['minutes']);
                        [$covered, $uncovered] = self::take($need, $left, $balances, $day['order']);
                        $lines[] = [
                            'date' => $day['date'], 'kind' => self::LATE, 'minutes' => $day['minutes'], 'days' => $need,
                            'covered' => $covered, 'uncovered_days' => $uncovered,
                            'fraction' => $need > 0 ? LeaveQuantity::normalize(($need - $uncovered) / $need) : 0.0,
                        ];
                    }
                }
            }
        }

        return ['lines' => $lines, 'balances' => $left, 'notes' => $notes];
    }

    /**
     * The charge order as it stands on `$date`: a type that waits is in it
     * only from the day it was acquired.
     *
     * @param  list<string>  $order
     * @param  array<string, array{available: float, below_zero_limit: float, from?: ?string}>  $balances
     * @return list<string>
     */
    private static function open(array $order, array $balances, string $date): array
    {
        return array_values(array_filter($order, static fn (string $token): bool => ($balances[$token]['from'] ?? null) === null || $balances[$token]['from'] <= $date));
    }

    /**
     * Takes `$need` days from the types in order, each down to its floor;
     * returns what each type gave and what is left uncovered.
     *
     * @param  array<string, float>  $left  by reference: the running balances
     * @param  array<string, array{available: float, below_zero_limit: float}>  $balances
     * @param  list<string>  $order
     * @return array{0: array<string, float>, 1: float}
     */
    private static function take(float $need, array &$left, array $balances, array $order): array
    {
        $covered = [];
        $remaining = $need;
        foreach ($order as $token) {
            if ($remaining <= 0) {
                break;
            }
            if (! isset($left[$token])) {
                continue;
            }
            $room = LeaveQuantity::normalize(max(0.0, $left[$token] + ($balances[$token]['below_zero_limit'] ?? 0.0)));
            if ($room <= 0) {
                continue;
            }
            $take = LeaveQuantity::normalize(min($remaining, $room));
            $covered[$token] = $take;
            $left[$token] = LeaveQuantity::normalize($left[$token] - $take);
            $remaining = LeaveQuantity::normalize($remaining - $take);
        }

        return [$covered, max(0.0, $remaining)];
    }
}
