<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Support\Currency;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use App\Support\TextSort;
use Carbon\CarbonImmutable;

/**
 * Display helpers for the Records Management screens — the slice of
 * `src/lib/hris.ts` (fmtDate, fmtHm, peso, todayKey, haversineMeters,
 * requestDescription and friends) plus the normalizer and week describer the
 * records screens define for themselves (reference-list.tsx:52-57,
 * shift-types-page.tsx:60-88).
 *
 * The hris.ts members forward to App\Support\HrisFormat, their shared home;
 * the wrappers stay so the records screens' call sites and Blade imports
 * keep pointing here.
 */
final class Hris
{
    /** hris.ts:281-285 — the built-in request types (HrisFormat's list). */
    public const array REQUEST_TYPES = HrisFormat::REQUEST_TYPES;

    /**
     * `work_days` order, Sunday first, so the picker reads like a calendar
     * (shift-types-page.tsx:59-67).
     *
     * @var array<int, string>
     */
    public const array WEEK = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    private function __construct() {}

    /** TS: `fmtDate(d)` — a 'Y-m-d' calendar day as `Aug 05, 2026`, shared through HrisFormat. No zone to shift. */
    public static function fmtDate(CarbonImmutable|string|null $value): string
    {
        return HrisFormat::date($value);
    }

    /** TS: `fmtHm(value)` — a stored 'HH:mm[:ss]' clock time as `5:00 PM` (hris.ts:217-225), shared through HrisFormat. */
    public static function fmtHm(?string $value): string
    {
        return HrisFormat::hm($value);
    }

    /** `$17,000.00`: the one figure format, Currency::format(). */
    public static function money(mixed $n): string
    {
        return Currency::format($n);
    }

    /** Today's local calendar day, 'Y-m-d'. TS: `todayKey()` — LocalClock's one clock read. */
    public static function todayKey(): string
    {
        return LocalClock::todayKey();
    }

    /**
     * "Government ID" and "GOVERNMENT_ID" are the same option to a human
     * (reference-list.tsx:52-57).
     */
    public static function normalizeOption(?string $value): string
    {
        return (string) preg_replace('/[\s-]+/', '_', strtoupper(trim((string) $value)));
    }

    /** Stored request types are the normalised form of a Leave Settings name (hris.ts:328), shared through HrisFormat. */
    public static function normalizeRequestType(string $name): string
    {
        return HrisFormat::normalizeRequestType($name);
    }

    /**
     * What a request is *for* — resolved against Leave Settings first, with the
     * built-in types as a fallback for older rows (hris.ts:340-350), shared
     * through HrisFormat.
     *
     * @param  list<string>  $leaveTypeNames
     */
    public static function requestDescription(string $value, array $leaveTypeNames = []): string
    {
        return HrisFormat::requestDescription($value, $leaveTypeNames);
    }

    /**
     * Case-insensitive byte compare that always sorts blanks last — the port
     * of `compareText` (useSort.ts:33-40), shared through App\Support\TextSort.
     * The records screens reverse the whole sorted list for descending order,
     * exactly as the source did.
     */
    public static function compareText(?string $a, ?string $b): int
    {
        // One comparator for every sorted list, so no module sorts by a rule of
        // its own, such as a case-only tiebreak or ICU collation. This stays a
        // wrapper so no call site or Blade import changes; TextSortTest pins it.
        return TextSort::compare($a, $b);
    }

    /**
     * `workWeek(shift)` (dtr.ts:119-122): the scheduled days, or Monday–Friday
     * when a row predates `work_days`.
     *
     * @param  list<int>|null  $days
     * @return list<int>
     */
    public static function workWeek(?array $days): array
    {
        return $days !== null && $days !== [] ? $days : [1, 2, 3, 4, 5];
    }

    /**
     * "Mon–Fri" where the days run consecutively, otherwise "Mon, Wed, Fri"
     * (shift-types-page.tsx:69-88).
     *
     * @param  list<int>|null  $days
     */
    public static function describeWeek(?array $days): string
    {
        $week = self::workWeek($days);
        $picked = [];

        foreach (self::WEEK as $day => $label) {
            if (in_array($day, $week, true)) {
                $picked[] = ['day' => $day, 'label' => $label];
            }
        }

        if ($picked === []) {
            return "\u{2014}";
        }

        $consecutive = true;
        $previous = null;

        foreach ($picked as $d) {
            if ($previous !== null && $d['day'] !== $previous + 1) {
                $consecutive = false;
                break;
            }

            $previous = $d['day'];
        }

        return $consecutive && count($picked) > 2
            ? $picked[0]['label']."\u{2013}".$picked[count($picked) - 1]['label']
            : implode(', ', array_column($picked, 'label'));
    }

    /** A fraction as the percentage a settings page shows: 0.025 → "2.5", 0.1 → "10". */
    public static function percentText(mixed $rate): string
    {
        return is_numeric($rate) ? rtrim(rtrim(number_format((float) $rate * 100, 4, '.', ''), '0'), '.') : '';
    }

    /** A peso figure as a settings input shows it: "5000" or "937.5". */
    public static function moneyText(mixed $amount): string
    {
        return is_numeric($amount) ? rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.') : '';
    }

    /** TS: `haversineMeters` (hris.ts:153-162). */
    public static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $toRad = static fn (float $d): float => $d * M_PI / 180;
        $dLat = $toRad($lat2 - $lat1);
        $dLon = $toRad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos($toRad($lat1)) * cos($toRad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(sqrt($a));
    }
}
