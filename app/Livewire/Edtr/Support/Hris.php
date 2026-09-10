<?php

declare(strict_types=1);

namespace App\Livewire\Edtr\Support;

use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Minutes;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;

/**
 * The display helpers the eDTR screens use — ports of the relevant pieces of
 * `copems/src/lib/hris.ts`, kept inside the EDTR module because no shared
 * formatting layer exists yet. Every function is a literal transcription of
 * the TypeScript, en-PH locale rendered as PHP date formats:
 *
 *   fmtTime      toLocaleTimeString(hour numeric, minute/second 2-digit, h12)
 *   fmtDateTime  toLocaleString(year numeric, month short, day 2-digit, ...)
 *   fmtDate      toLocaleDateString(year numeric, month short, day 2-digit)
 *
 * All instants are shown in Manila via ManilaClock — never format a UTC value
 * directly.
 */
final class Hris
{
    /** TS: `SOURCES` — hris.ts. The punch-source select's options. */
    public const SOURCES = [
        ['value' => 'WEB_GPS', 'label' => 'Web GPS'],
        ['value' => 'BIOMETRIC', 'label' => 'Biometric'],
        ['value' => 'MANUAL', 'label' => 'Manual'],
    ];

    /** TS: `REQUEST_TYPES` — the built-in fallbacks for requestDescription. */
    private const REQUEST_TYPES = [
        'VACATION' => 'Vacation Leave',
        'SICK' => 'Sick Leave',
        'BUSINESS_TRAVEL' => 'Business Travel / OB',
    ];

    /** TS: `BUILT_IN_STATUSES` — rows written before Employment was editable. */
    private const BUILT_IN_STATUSES = [
        'REGULAR' => 'Regular',
        'PROBATIONARY' => 'Probationary',
        'CONTRACTUAL' => 'Contractual',
        'JOB_ORDER' => 'Job Order',
    ];

    private function __construct() {}

    /** Today's Manila calendar day, 'Y-m-d'. TS: `todayKey()`. */
    public static function todayKey(): string
    {
        return ManilaClock::dayKey(CarbonImmutable::now());
    }

    /**
     * TS: `semiMonthlyPeriod(reference)` — the cut-off a date falls in: the
     * 1st to the 15th, or the 16th to the month's own last day.
     *
     * @return array{start: string, end: string}
     */
    public static function semiMonthlyPeriod(?string $reference = null): array
    {
        $reference ??= self::todayKey();
        [$y, $m, $d] = array_map(intval(...), explode('-', $reference));

        $key = static fn (int $dayOfMonth): string => sprintf('%04d-%02d-%02d', $y, $m, $dayOfMonth);

        if ($d <= 15) {
            return ['start' => $key(1), 'end' => $key(15)];
        }

        $lastDay = (int) CarbonImmutable::createFromDate($y, $m, 1, 'UTC')->endOfMonth()->format('j');

        return ['start' => $key(16), 'end' => $key($lastDay)];
    }

    /**
     * TS: `fmtDuration(minutes)` — `457` reads as `7h 37m`. Whole hours drop
     * the minutes and spans under an hour drop the hours, so nothing shows 0.
     */
    public static function fmtDuration(float $minutes): string
    {
        $total = (int) Minutes::round(is_finite($minutes) ? $minutes : 0.0);
        $sign = $total < 0 ? '-' : '';
        $abs = abs($total);
        $hours = intdiv($abs, 60);
        $mins = $abs % 60;

        if ($hours === 0) {
            return "{$sign}{$mins}m";
        }
        if ($mins === 0) {
            return "{$sign}{$hours}h";
        }

        return "{$sign}{$hours}h {$mins}m";
    }

    /**
     * The colour a DTR duration is shown in — the tone when there is something
     * to show, muted when there is not.
     *
     * ZERO IS DELIBERATELY NOT COLOURED. fmtDuration renders an untroubled day
     * as "0m" in three of these four columns, so tinting by column rather than
     * by value would paint a clean month solid red and orange and leave the
     * exceptions nothing to stand out against. The colour marks what happened,
     * not what the column is about.
     *
     * The rounding mirrors fmtDuration's first line exactly, so the tone can
     * never disagree with the text beside it: 0.4 minutes prints "0m", and is
     * therefore grey rather than red.
     *
     * The tone is passed in rather than mapped here because app.css scans only
     * `resources/views` (@source), so a class named nowhere but this file would
     * never be generated. `text-muted-foreground` is safe to name here only
     * because the views already use it everywhere.
     */
    public static function durationTone(float $minutes, string $tone): string
    {
        $rounded = (int) Minutes::round(is_finite($minutes) ? $minutes : 0.0);

        return $rounded === 0 ? 'text-muted-foreground' : $tone;
    }

    /** TS: `fmtTime(iso)` — `2:05:31 PM`, Manila. */
    public static function fmtTime(string $iso): string
    {
        return ManilaClock::instant($iso)->setTimezone(ManilaClock::TZ)->format('g:i:s A');
    }

    /** TS: `fmtDateTime(iso)` — `Aug 31, 2026, 2:05 PM`, Manila. */
    public static function fmtDateTime(string $iso): string
    {
        return ManilaClock::instant($iso)->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A');
    }

    /** TS: `fmtDate(d)` — a 'Y-m-d' calendar day as `Aug 05, 2026`. */
    public static function fmtDate(string $d): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', substr($d, 0, 10), 'UTC');

        return $day === null ? $d : $day->format('M d, Y');
    }

    /** TS: `fmtHm(value)` — a stored 'HH:mm[:ss]' clock time as `5:00 PM`. */
    public static function fmtHm(?string $value): string
    {
        if ($value === null || $value === '') {
            return "\u{2014}";
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $m) !== 1) {
            return $value;
        }
        $h24 = (int) $m[1];
        if ($h24 > 23) {
            return $value;
        }
        $h12 = $h24 % 12 === 0 ? 12 : $h24 % 12;

        return $h12.':'.$m[2].' '.($h24 < 12 ? 'AM' : 'PM');
    }

    /** TS: `employeeName(e)` — "First Last", or the em dash for nobody. */
    public static function employeeName(?object $e): string
    {
        return EmployeeName::display($e);
    }

    /** TS: `employmentStatusLabel(v)` — built-in enum values become words. */
    public static function employmentStatusLabel(string $v): string
    {
        return self::BUILT_IN_STATUSES[$v] ?? $v;
    }

    /** TS: `normalizeRequestType(name)` — a Leave Settings name as a stored key. */
    public static function normalizeRequestType(string $name): string
    {
        $key = strtoupper(trim($name));
        $key = (string) preg_replace('/[^A-Z0-9]+/', '_', $key);

        return trim($key, '_');
    }

    /**
     * TS: `requestDescription(value, leaveTypes)` — what a request is FOR,
     * resolved against Leave Settings first, the built-ins second, and a
     * title-cased spelling of the raw value last.
     *
     * @param  list<array{name: string}>  $leaveTypes
     */
    public static function requestDescription(string $value, array $leaveTypes = []): string
    {
        foreach ($leaveTypes as $type) {
            $key = self::normalizeRequestType($type['name']);
            if ($key === $value || $key === $value.'_LEAVE') {
                return $type['name'];
            }
        }

        if (isset(self::REQUEST_TYPES[$value])) {
            return self::REQUEST_TYPES[$value];
        }

        $words = explode('_', strtolower($value));

        return implode(' ', array_map(ucfirst(...), $words));
    }

    /**
     * TS: `autoRemark(day, holidays, leaveTypes)` — what the eDTR says about a
     * day when nobody has written anything. This replaced the status pill
     * because the pill announced a verdict on days that hold no record at all.
     *
     * @param  list<HolidaySetting>  $holidays
     * @param  list<array{name: string}>  $leaveTypes
     */
    public static function autoRemark(DayRecord $day, array $holidays, array $leaveTypes): string
    {
        if ($day->excuseType !== null && $day->excuseType !== '') {
            $label = self::requestDescription($day->excuseType, $leaveTypes);
            if ($day->excusePaid === true) {
                return "{$label} (paid)";
            }
            if ($day->excusePaid === false) {
                return "{$label} (unpaid)";
            }

            return $label;
        }

        $holiday = HolidaySetting::on($day->date, $holidays);
        if ($holiday !== null) {
            return $holiday->name;
        }

        if ($day->remarks !== null && $day->remarks !== '') {
            return $day->remarks;
        }

        if ($day->status === DayRecord::ABSENT) {
            return 'No punches';
        }
        if ($day->status === DayRecord::INCOMPLETE) {
            return 'No time out';
        }
        if ($day->status === DayRecord::EXCUSED) {
            return 'Rest day';
        }

        $notes = [];
        if ($day->lateMinutes > 0) {
            $notes[] = ((int) Minutes::round($day->lateMinutes)).' min late';
        }
        if ($day->undertimeMinutes > 0) {
            $notes[] = ((int) Minutes::round($day->undertimeMinutes)).' min undertime';
        }

        return implode(' · ', $notes);
    }

    /**
     * TS: `slotOf(iso)` — `2026-01-05T07:47:31` → `07:47`, the shape the time
     * fields speak. Seconds are dropped rather than rounded, so a 07:47:31
     * punch never reads back as 07:48.
     */
    public static function slotOf(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        $total = (int) floor(ManilaClock::minutesOfDay(ManilaClock::instant($iso)));

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }
}
