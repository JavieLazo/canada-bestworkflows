<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Support;

use App\Domain\Attendance\DtrCalculator;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;

/**
 * The slice of `src/lib/hris.ts` the Portal screens read — labels, formats and
 * the leave-date rule, no computation. Kept inside the module's own namespace
 * because the file-ownership split forbids writing a shared lib from here
 * (the same reasoning as App\Livewire\Leaves\LeaveLabels).
 *
 * Ported members, line-for-line:
 *
 *   REQUEST_TYPES            hris.ts:283-287
 *   DAY_TYPES                hris.ts:290-293
 *   dayTypeLabel             hris.ts:295-296
 *   leaveRequestDateError    hris.ts:299-317
 *   leaveRangesOverlap       hris.ts:320-325
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
    public const string OFFICIAL_BUSINESS = DtrCalculator::OFFICIAL_BUSINESS;

    /**
     * The built-in request types, the fallback for rows older than Leave
     * Settings (hris.ts:283-287).
     *
     * @var array<string, string>
     */
    public const array REQUEST_TYPES = [
        'VACATION' => 'Vacation Leave',
        'SICK' => 'Sick Leave',
        'BUSINESS_TRAVEL' => 'Business Travel / OB',
    ];

    /**
     * Whether a request covers the whole working day or half of it
     * (hris.ts:290-293). Order matters: the filing form defaults to the FIRST
     * entry (portal-page.tsx:96 `DAY_TYPES[0]!.value`).
     *
     * @var array<string, string>
     */
    public const array DAY_TYPES = [
        'WHOLE_DAY' => 'Whole day',
        'HALF_DAY' => 'Half day',
    ];

    private function __construct() {}

    /** hris.ts:295-296 — an unknown or null day type reads as whole day. */
    public static function dayTypeLabel(?string $value): string
    {
        return self::DAY_TYPES[$value] ?? 'Whole day';
    }

    /** Stored request types are the normalised form of a Leave Settings name (hris.ts:326-332). */
    public static function normalizeRequestType(string $name): string
    {
        $key = (string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper(trim($name)));

        return trim($key, '_');
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
            static fn (string $word): string => ucfirst($word),
            explode('_', strtolower($value)),
        ));
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
        $parse = static function (string $value): ?CarbonImmutable {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
                return null;
            }
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

            if (! checkdate($month, $day, $year)) {
                return null;
            }

            return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
        };

        $startDate = $parse($start);
        $endDate = $parse($end);

        if ($startDate === null || $endDate === null) {
            return 'Choose valid start and end dates.';
        }
        if ($endDate->lessThan($startDate)) {
            return 'The end date cannot fall before the start date.';
        }

        return null;
    }

    /** Inclusive overlap for validated YYYY-MM-DD leave ranges (hris.ts:320-325). */
    public static function leaveRangesOverlap(
        string $firstStart,
        string $firstEnd,
        string $secondStart,
        string $secondEnd,
    ): bool {
        return $firstStart <= $secondEnd && $secondStart <= $firstEnd;
    }

    /**
     * The next `LR-001` style code (hris.ts:406-414), with the route handler's
     * guard folded in: codes numbered past JavaScript's safe integer range are
     * ignored, because `nextCode` there added one in float64 and such a value
     * would map to itself and wedge every filing
     * (api/leave-requests/route.ts:43-57).
     *
     * @param  list<string|null>  $codes
     */
    public static function nextCode(string $prefix, array $codes, int $width = 3): string
    {
        $highest = 0.0;

        foreach ($codes as $code) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/i', trim((string) $code), $m) !== 1) {
                continue;
            }

            $n = (float) $m[1];

            // Number.MAX_SAFE_INTEGER — mintCode's filter.
            if ($n > 9007199254740991.0) {
                continue;
            }

            $highest = max($highest, $n);
        }

        return $prefix.'-'.str_pad((string) (int) ($highest + 1), $width, '0', STR_PAD_LEFT);
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

    /** TS: `fmtDate(d)` — a 'Y-m-d' calendar day as `Aug 05, 2026`. */
    public static function fmtDate(string $d): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', substr($d, 0, 10), 'UTC');

        return $day === null ? $d : $day->format('M d, Y');
    }

    /** TS: `fmtDateTime(iso)` — `Aug 31, 2026, 2:05 PM`, Manila. */
    public static function fmtDateTime(string $iso): string
    {
        return ManilaClock::instant($iso)->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A');
    }

    /** Today's Manila calendar day, 'Y-m-d'. TS: `todayKey()`. */
    public static function todayKey(): string
    {
        return ManilaClock::dayKey(CarbonImmutable::now());
    }

    /** PayslipDetail.tsx:12 — whole days, unless a half-day leave landed in the count. */
    public static function dayCount(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : number_format($value, 1, '.', '');
    }
}
