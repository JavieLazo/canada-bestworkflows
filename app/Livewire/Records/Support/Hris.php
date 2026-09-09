<?php

declare(strict_types=1);

namespace App\Livewire\Records\Support;

use App\Support\ManilaClock;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Display helpers for the Records Management screens — the slice of
 * `src/lib/hris.ts` (fmtDate, fmtHm, peso, todayKey, haversineMeters,
 * requestDescription and friends) plus the normalizer and week describer the
 * records screens define for themselves (reference-list.tsx:52-57,
 * shift-types-page.tsx:60-88).
 *
 * Lives inside the Records namespace because there is no shared HRIS display
 * helper in the app yet and this module may only write its own files; keep it
 * in sync with any sibling module's copy until a shared home exists.
 */
final class Hris
{
    /** hris.ts:281-285 — the request types that shipped before Leave Settings. */
    public const array REQUEST_TYPES = [
        'VACATION' => 'Vacation Leave',
        'SICK' => 'Sick Leave',
        'BUSINESS_TRAVEL' => 'Business Travel / OB',
    ];

    /**
     * `work_days` order, Sunday first, so the picker reads like a calendar
     * (shift-types-page.tsx:59-67).
     *
     * @var array<int, string>
     */
    public const array WEEK = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    private function __construct() {}

    /** TS: `fmtDate(d)` — a 'Y-m-d' calendar day as `Aug 05, 2026`. No zone to shift. */
    public static function fmtDate(CarbonImmutable|string|null $value): string
    {
        if ($value === null || $value === '') {
            return "\u{2014}";
        }

        $day = $value instanceof CarbonImmutable
            ? $value
            : CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), 'UTC');

        return $day === null ? (string) $value : $day->format('M d, Y');
    }

    /** TS: `fmtHm(value)` — a stored 'HH:mm[:ss]' clock time as `5:00 PM` (hris.ts:217-226). */
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

        return sprintf('%d:%s %s', $h12, $m[2], $h24 < 12 ? 'AM' : 'PM');
    }

    /** TS: `peso(n)` — `₱17,000.00` (hris.ts:184-189). */
    public static function peso(mixed $n): string
    {
        $f = is_numeric($n) ? (float) $n : 0.0;

        if (! is_finite($f)) {
            $f = 0.0;
        }

        return "\u{20B1}".number_format($f, 2);
    }

    /** Today's Manila calendar day, 'Y-m-d'. TS: `todayKey()`. */
    public static function todayKey(): string
    {
        return ManilaClock::dayKey(CarbonImmutable::now());
    }

    /**
     * "Government ID" and "GOVERNMENT_ID" are the same option to a human
     * (reference-list.tsx:52-57).
     */
    public static function normalizeOption(?string $value): string
    {
        return (string) preg_replace('/[\s-]+/', '_', strtoupper(trim((string) $value)));
    }

    /** Stored request types are the normalised form of a Leave Settings name (hris.ts:328-333). */
    public static function normalizeRequestType(string $name): string
    {
        $key = strtoupper(trim($name));
        $key = (string) preg_replace('/[^A-Z0-9]+/', '_', $key);

        return trim($key, '_');
    }

    /**
     * What a request is *for* — resolved against Leave Settings first, with the
     * built-in types as a fallback for older rows (hris.ts:340-353).
     *
     * @param  list<string>  $leaveTypeNames
     */
    public static function requestDescription(string $value, array $leaveTypeNames = []): string
    {
        foreach ($leaveTypeNames as $name) {
            $key = self::normalizeRequestType($name);

            if ($key === $value || $key === $value.'_LEAVE') {
                return $name;
            }
        }

        if (isset(self::REQUEST_TYPES[$value])) {
            return self::REQUEST_TYPES[$value];
        }

        return implode(' ', array_map(
            static fn (string $w): string => ucfirst($w),
            explode('_', strtolower($value)),
        ));
    }

    /**
     * Locale-aware-ish compare that always sorts blanks last — the port of
     * `compareText` (useSort.ts:33-40). The records screens reverse the whole
     * sorted list for descending order, exactly as the source did.
     */
    public static function compareText(?string $a, ?string $b): int
    {
        $left = trim((string) $a);
        $right = trim((string) $b);

        if ($left === '' && $right === '') {
            return 0;
        }

        if ($left === '') {
            return 1;
        }

        if ($right === '') {
            return -1;
        }

        return strcasecmp($left, $right);
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

    /**
     * The formulas page's `round2` (formulas-page.tsx:36) — Math.round(n*100)/100,
     * which is exactly {@see Money::round()}. Display-only rounding for the
     * sample derivation; payroll's own rounding lives in the domain layer.
     */
    public static function round2(mixed $n): float
    {
        return Money::round($n);
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
