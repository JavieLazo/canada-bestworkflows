<?php

declare(strict_types=1);

namespace App\Livewire\Edtr\Support;

use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Minutes;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * The display helpers the eDTR screens use — ports of the relevant pieces of
 * `copems/src/lib/hris.ts`. The members other modules share forward to
 * App\Support\HrisFormat; the wrappers stay so the eDTR call sites and
 * Blade imports keep pointing here. Every function is a literal
 * transcription of the TypeScript, en-PH locale rendered as PHP date
 * formats:
 *
 *   fmtTime      toLocaleTimeString(hour numeric, minute 2-digit, h12) — the
 *                reference printed seconds too; see the method
 *   fmtDateTime  toLocaleString(year numeric, month short, day 2-digit, ...)
 *   fmtDate      toLocaleDateString(year numeric, month short, day 2-digit)
 *
 * All instants are shown in local via LocalClock — never format a UTC value
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

    private function __construct() {}

    /** Today's local calendar day, 'Y-m-d'. TS: `todayKey()` — LocalClock's one clock read. */
    public static function todayKey(): string
    {
        return LocalClock::todayKey();
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
     * TS: `fmtDuration(minutes)` — `457` reads as `7h 37m`. Forwards to
     * HrisFormat::duration, the one spelling the overtime audit rows and
     * notices share.
     */
    public static function fmtDuration(float $minutes): string
    {
        return HrisFormat::duration($minutes);
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

    /**
     * TS: `fmtTime(iso)` — `2:05 PM`, local.
     *
     * DELIBERATE DIVERGENCE: hris.ts prints `2:05:31 PM`. The seconds are
     * dropped here for DISPLAY ONLY — the four DTR punch cells and the
     * correction dialog's recorded-punch times. Nothing stored or audited
     * changes: the DTR_CORRECTED sentence (CorrectPunches::apply) and the
     * live clock keep their seconds, and slotOf() still floors them.
     */
    public static function fmtTime(string $iso): string
    {
        return LocalClock::instant($iso)->setTimezone(LocalClock::zone())->format('g:i A');
    }

    /** TS: `fmtDateTime(iso)` — `Aug 31, 2026, 2:05 PM`, local. */
    public static function fmtDateTime(string $iso): string
    {
        return LocalClock::instant($iso)->setTimezone(LocalClock::zone())->format('M d, Y, g:i A');
    }

    /** TS: `fmtDate(d)` — a 'Y-m-d' calendar day as `Aug 05, 2026`, shared through HrisFormat. */
    public static function fmtDate(string $d): string
    {
        return HrisFormat::date($d);
    }

    /** TS: `fmtHm(value)` — a stored 'HH:mm[:ss]' clock time as `5:00 PM`, shared through HrisFormat. */
    public static function fmtHm(?string $value): string
    {
        return HrisFormat::hm($value);
    }

    /** TS: `employeeName(e)` — "First Last", or the em dash for nobody. */
    public static function employeeName(?object $e): string
    {
        return EmployeeName::display($e);
    }

    /** TS: `employmentStatusLabel(v)` — built-in enum values become words, shared through HrisFormat. */
    public static function employmentStatusLabel(string $v): string
    {
        return HrisFormat::employmentStatus($v);
    }

    /** TS: `normalizeRequestType(name)` — a Leave Settings name as a stored key, shared through HrisFormat. */
    public static function normalizeRequestType(string $name): string
    {
        return HrisFormat::normalizeRequestType($name);
    }

    /**
     * TS: `requestDescription(value, leaveTypes)` — what a request is FOR,
     * resolved against Leave Settings first, the built-ins second, and a
     * title-cased spelling of the raw value last. Shared through HrisFormat;
     * this module hands over the leave-type rows, the others their names.
     *
     * @param  list<array{name: string}>  $leaveTypes
     */
    public static function requestDescription(string $value, array $leaveTypes = []): string
    {
        return HrisFormat::requestDescription($value, array_column($leaveTypes, 'name'));
    }

    /**
     * TS: `autoRemark(day, holidays, leaveTypes)` — what the eDTR says about a
     * day when nobody has written anything. It stands in for a status pill,
     * which would announce a verdict on days that hold no record at all.
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
     *
     * Forwards to the Timekeeping domain rule: the same flooring decides, in
     * CorrectionWindow::reconcile, whether a slot left as prefilled still
     * matches the punch it came from and may keep it.
     */
    public static function slotOf(?string $iso): ?string
    {
        return PunchSlots::slotOf($iso);
    }

    /**
     * Whether a punch shown on the DTR row keyed on `$date` falls on the
     * next calendar day — the "next day" tag beside a night's Time Out.
     * Forwards to the Timekeeping domain rule, the one the correction's
     * prefilled ticks and audit sentence read too.
     */
    public static function isNextDay(?string $iso, string $date): bool
    {
        return PunchSlots::isNextDay($iso, $date);
    }
}
