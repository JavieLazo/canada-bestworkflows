<?php

declare(strict_types=1);

namespace App\Livewire\Leaves;

use App\Domain\Attendance\DtrCalculator;
use App\Modules\Timekeeping\Application\LeaveHalves;
use App\Support\HrisFormat;
use App\Support\TextSort;

/**
 * The slice of `src/lib/hris.ts` the Leaves screens read — labels and
 * comparisons only, no computation. The label helpers forward to
 * App\Support\HrisFormat; the wrappers stay so the call sites and the Blade
 * import of DAY_TYPES keep pointing here.
 *
 * Ported members, line-for-line:
 *
 *   REQUEST_TYPES            hris.ts:283-287
 *   DAY_TYPES                hris.ts:290-293
 *   dayTypeLabel             hris.ts:295-296
 *   normalizeRequestType     hris.ts:326-332
 *   requestDescription       hris.ts:340-356
 *   compareText              useSort.ts:33-40
 *
 * plus `describe()` — the page-level memo of leaves-page.tsx:61-67 that
 * short-circuits official business before consulting Leave Settings.
 */
final class LeaveLabels
{
    /** dtr.ts:55 — official business is filed as the BUSINESS_TRAVEL type. */
    public const string OFFICIAL_BUSINESS = DtrCalculator::OFFICIAL_BUSINESS;

    /**
     * The built-in request types, the fallback for rows older than Leave
     * Settings (hris.ts:283-287).
     *
     * @var array<string, string>
     */
    public const array REQUEST_TYPES = HrisFormat::REQUEST_TYPES;

    /**
     * Whether a request covers the whole working day or half of it
     * (hris.ts:290-293). Order is the order the filter lists them in.
     *
     * @var array<string, string>
     */
    public const array DAY_TYPES = [
        'WHOLE_DAY' => 'Whole day',
        'HALF_DAY' => 'Half day',
    ];

    /** hris.ts:295-296 — an unknown or null day type reads as whole day; a half day names its half when it says which (divergence 21). */
    public static function dayTypeLabel(?string $value, ?string $half = null): string
    {
        $label = self::DAY_TYPES[$value] ?? 'Whole day';

        return $value === 'HALF_DAY' && isset(LeaveHalves::HALVES[$half]) ? $label.' · '.LeaveHalves::HALVES[$half] : $label;
    }

    /** Stored request types are the normalised form of a Leave Settings name (hris.ts:328), shared through HrisFormat. */
    public static function normalizeRequestType(string $name): string
    {
        return HrisFormat::normalizeRequestType($name);
    }

    /**
     * What a request is *for* — resolved against Leave Settings first so the
     * list HR manages is what shows, with the built-in types as a fallback for
     * older rows (hris.ts:340-356).
     *
     * @param  list<string>  $leaveTypeNames
     */
    public static function requestDescription(string $value, array $leaveTypeNames = []): string
    {
        return HrisFormat::requestDescription($value, $leaveTypeNames);
    }

    /**
     * The page's `describe` memo (leaves-page.tsx:61-67): official business
     * reads as "Official Business", never as its Leave Settings row.
     *
     * @param  list<string>  $leaveTypeNames
     */
    public static function describe(string $requestType, array $leaveTypeNames = []): string
    {
        return $requestType === self::OFFICIAL_BUSINESS
            ? 'Official Business'
            : self::requestDescription($requestType, $leaveTypeNames);
    }

    /**
     * Port of `compareText` (useSort.ts:33-40): blanks always sort last in an
     * ascending pass. `strcasecmp` stands in for `localeCompare` — a
     * case-insensitive byte comparison rather than a locale collation, which
     * for the codes, English names and labels this screen sorts is the same
     * ordering. Shared through App\Support\TextSort; this wrapper keeps the
     * PHP call sites unchanged (no Blade view calls it).
     */
    public static function compareText(?string $a, ?string $b): int
    {
        // One comparator for every sorted list, because per-module copies drift
        // apart (a case-only tiebreak, an ICU collation). This stays a wrapper
        // so no call site or Blade import changes; TextSortTest pins it.
        return TextSort::compare($a, $b);
    }
}
