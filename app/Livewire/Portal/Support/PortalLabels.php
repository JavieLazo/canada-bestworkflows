<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Support;

use App\Modules\Timekeeping\Application\LeaveHalves;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Support\CodeSequence;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;

/**
 * The slice of `src/lib/hris.ts` the Portal screens read — labels, formats and
 * the leave-date rule, no computation. The formatting members forward to
 * App\Support\HrisFormat and App\Support\CodeSequence; the wrappers keep
 * every call site and Blade import pointing here.
 *
 * Ported members, line-for-line:
 *
 *   REQUEST_TYPES            hris.ts:283-287
 *   DAY_TYPES                hris.ts:290-293
 *   dayTypeLabel             hris.ts:295-296
 *   leaveRequestDateError    hris.ts:299-317
 *   normalizeRequestType     hris.ts:326-332
 *   requestDescription       hris.ts:340-356
 *   nextCode                 hris.ts:406-414
 *   employeeName             hris.ts:416-418
 *   peso                     hris.ts:186-191
 *   fmtDate                  hris.ts:210-214
 *   fmtDateTime              hris.ts:201-208
 *   todayKey                 hris.ts:243
 *
 * plus `describe()` — the portal table's cell (portal-page.tsx:317-319) that
 * short-circuits official business before consulting Leave Settings — and
 * `dayCount()` from PayslipDetail.tsx:12.
 */
final class PortalLabels
{
    /** dtr.ts:55 — official business is filed as the BUSINESS_TRAVEL type. */
    public const string OFFICIAL_BUSINESS = AbsencePolicy::OFFICIAL_BUSINESS;

    /**
     * The built-in request types, the fallback for rows older than Leave
     * Settings (hris.ts:283-287).
     *
     * @var array<string, string>
     */
    public const array REQUEST_TYPES = HrisFormat::REQUEST_TYPES;

    /**
     * Whether a request covers the whole working day or half of it
     * (hris.ts:290-293). Order matters: the filing form defaults to the FIRST
     * entry (portal-page.tsx:96 `DAY_TYPES[0]!.value`).
     *
     * @var array<string, string>
     */
    public const array DAY_TYPES = AbsencePolicy::DAY_TYPES;

    private function __construct() {}

    /** Which half of a half day is off, with the names on screen (divergence 21). */
    public const array HALVES = LeaveHalves::HALVES;

    /** hris.ts:295-296 — an unknown or null day type reads as whole day; a half day names its half when it says which. */
    public static function dayTypeLabel(?string $value, ?string $half = null): string
    {
        $label = self::DAY_TYPES[$value] ?? 'Whole day';

        return $value === 'HALF_DAY' && isset(self::HALVES[$half]) ? $label.' · '.self::HALVES[$half] : $label;
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
     * The portal table's description cell (portal-page.tsx:317-319): official
     * business reads as "Official Business", never as its Leave Settings row.
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
     * Validates the calendar-day range used by leave and official-business
     * requests (hris.ts:299-317). Null when the range is fine.
     */
    public static function leaveRequestDateError(string $start, string $end): ?string
    {
        return AbsencePolicy::dateError($start, $end);
    }

    /**
     * The next leave id through the one mint in CodeSequence, which carries
     * the leave route handler's safe-integer guard
     * (api/leave-requests/route.ts:46-57) for every column. A bare number;
     * `$legacyPrefix` is the `LR` an unconverted row carries, which the mint
     * reads too, so an unconverted table mints above it.
     *
     * @param  list<string|null>  $codes
     */
    public static function nextCode(string $legacyPrefix, array $codes): string
    {
        return CodeSequence::next($legacyPrefix, $codes);
    }

    /** TS: `employeeName(e)` — "First Last", or the em dash for nobody. */
    public static function employeeName(?object $e): string
    {
        return EmployeeName::display($e);
    }

    /** TS: `peso(n)` — ₱12,345.67, always two decimals (hris.ts:186-191). */
    public static function peso(float $n): string
    {
        return "\u{20B1}".number_format(is_finite($n) ? $n : 0.0, 2, '.', ',');
    }

    /** TS: `fmtDate(d)` — a 'Y-m-d' calendar day as `Aug 05, 2026`, shared through HrisFormat. */
    public static function fmtDate(string $d): string
    {
        return HrisFormat::date($d);
    }

    /** TS: `fmtDateTime(iso)` — `Aug 31, 2026, 2:05 PM`, Manila. */
    public static function fmtDateTime(string $iso): string
    {
        return ManilaClock::instant($iso)->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A');
    }

    /** Today's Manila calendar day, 'Y-m-d'. TS: `todayKey()` — ManilaClock's one clock read. */
    public static function todayKey(): string
    {
        return ManilaClock::todayKey();
    }

    /** PayslipDetail.tsx:12 — whole days, unless a half-day leave landed in the count. */
    public static function dayCount(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : number_format($value, 1, '.', '');
    }

    /** Leave credits as a leave card states them: three decimals, a fourth only when it is not zero (LeaveQuantity::credits). */
    public static function credits(float $value): string
    {
        return LeaveQuantity::credits($value);
    }
}
