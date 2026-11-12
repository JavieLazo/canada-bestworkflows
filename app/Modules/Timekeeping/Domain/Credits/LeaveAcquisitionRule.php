<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Credits;

use App\Domain\Payroll\PayrollPeriod;

/**
 * THE DAY A LEAVE TYPE IS ACQUIRED, for a type with a waiting period: the
 * date hired plus the months waited, pushed back ONE DAY FOR EACH WHOLE DAY
 * WITHOUT PAY before it when the rule says the wait extends — absences and
 * approved leave without pay; half days add up, and a half day left over
 * moves nothing. Lateness and undertime never count: they are minutes, not
 * days without pay.
 *
 * A rule applies from its effective date forward, so the day is never
 * earlier than the version that gives it, and a version gives it only
 * while it is still the one in force on that day. The versions are walked
 * oldest first and the first that reaches a day on or before today wins.
 *
 * Pure: the caller hands in the days without pay by date (it reads them
 * from the same Daily Time Record payroll does) and today's date.
 */
final class LeaveAcquisitionRule
{
    /** The extension settles in a few passes; this only stops a runaway loop. */
    private const int MAX_PASSES = 800;

    private function __construct() {}

    /**
     * @param  string  $hired  the date hired, 'Y-m-d'
     * @param  list<array{id: string, from: string, to: ?string, months: ?int, extends: bool}>  $versions  the type's approved versions, oldest first; `to` is the day before the next one, `months` null where the version has no waiting period
     * @param  array<string, float>  $unpaid  date => the part of that day without pay
     * @param  string  $today  the last day a type can have been acquired on
     * @param  string|null  $last  the employee's last day (date resigned), or null
     * @return array{date: string, version_id: string, months: int, extension_days: int, unpaid_days: float}|null null while not acquired yet
     */
    public static function resolve(string $hired, array $versions, array $unpaid, string $today, ?string $last = null): ?array
    {
        ksort($unpaid, SORT_STRING);
        foreach ($versions as $version) {
            if ($version['months'] === null) {
                continue;
            }
            $base = self::addMonths($hired, $version['months']);
            $extension = 0;
            for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
                $candidate = max(PayrollPeriod::addCalendarDays($base, $extension), $version['from']);
                if ($candidate > $today || ($last !== null && $candidate > $last)) {
                    break;
                }
                $days = $version['extends'] ? self::unpaidBefore($unpaid, $hired, $candidate) : 0.0;
                $whole = (int) floor($days + 1e-9);
                if ($whole <= $extension) {
                    if ($version['to'] !== null && $candidate > $version['to']) {
                        break;
                    }

                    return [
                        'date' => $candidate,
                        'version_id' => $version['id'],
                        'months' => $version['months'],
                        'extension_days' => $extension,
                        'unpaid_days' => LeaveQuantity::normalize($days),
                    ];
                }
                $extension = $whole;
            }
        }

        return null;
    }

    /**
     * The day the type would be acquired with no day without pay at all:
     * what a screen shows as "expected" before the day arrives. Null when
     * no version has a waiting period.
     *
     * @param  list<array{id: string, from: string, to: ?string, months: ?int, extends: bool}>  $versions
     */
    public static function expected(string $hired, array $versions): ?string
    {
        return self::resolve($hired, $versions, [], '9999-12-31')['date'] ?? null;
    }

    /**
     * `$date` plus whole calendar months, the day kept and clamped to the
     * month's last (Jan 31 + 1 month = Feb 28 or 29).
     */
    public static function addMonths(string $date, int $months): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        $total = $year * 12 + ($month - 1) + $months;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;
        $leap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
        $last = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$month - 1];

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
    }

    /**
     * The days without pay from `$from` up to the day before `$before`.
     *
     * @param  array<string, float>  $unpaid
     */
    private static function unpaidBefore(array $unpaid, string $from, string $before): float
    {
        $total = 0.0;
        foreach ($unpaid as $date => $days) {
            if ($date >= $from && $date < $before) {
                $total += $days;
            }
        }

        return $total;
    }
}
