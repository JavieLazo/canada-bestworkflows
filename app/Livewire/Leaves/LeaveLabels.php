<?php

declare(strict_types=1);

namespace App\Livewire\Leaves;

use App\Domain\Attendance\DtrCalculator;

/**
 * The slice of `src/lib/hris.ts` the Leaves screens read — labels and
 * comparisons only, no computation. Kept inside the module's own namespace
 * because the file-ownership split forbids writing a shared lib from here.
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
    public const array REQUEST_TYPES = [
        'VACATION' => 'Vacation Leave',
        'SICK' => 'Sick Leave',
        'BUSINESS_TRAVEL' => 'Business Travel / OB',
    ];

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
     * ordering.
     */
    public static function compareText(?string $a, ?string $b): int
    {
        $left = trim($a ?? '');
        $right = trim($b ?? '');

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
}
