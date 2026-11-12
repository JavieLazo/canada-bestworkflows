<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Attendance\Minutes;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The hris.ts / attachments.ts formatting slice, written once.
 *
 * Each helper here is the one body behind three to five module classes
 * (`Roster\Hris`, `Records\Support\Hris`, `Edtr\Support\Hris`, `PortalLabels`,
 * `LeaveLabels`, `ProjectsData`, `PayrollData`, `EmployeeFields`, `MyTasksPage`).
 * Copies kept "in sync" by hand drift apart — a date spelled `Sep 5, 2026`
 * where the reference (`day: "2-digit"`) prints `Sep 05, 2026`, megabytes
 * with a thousands separator that `toFixed(1)` never has. The module classes
 * carry the method names and forward here — the `EmployeeName` pattern — so
 * Blade views and call sites reach these bodies through their module's class.
 * `tests/Unit/HrisFormatTest.php` pins each behaviour and that every wrapper
 * forwards here.
 *
 * It also holds `hoursPerDay()`, the one spelling of a FLEXIBLE pattern's
 * daily hours, which the Timekeeping module's audit sentence and the roster's
 * cells and dialogs share so they cannot drift.
 *
 * Pure: no container and no clock. `todayKey()` lives on ManilaClock. Bare
 * `round()` is banned under app/Support by PayrollArchitectureTest; the
 * kilobyte branch rounds with intdiv() for that reason.
 */
final class HrisFormat
{
    /**
     * TS: `REQUEST_TYPES` (hris.ts:281-285) — the built-in request kinds and
     * their labels, the fallback when no configured leave type matches.
     *
     * @var array<string, string>
     */
    public const array REQUEST_TYPES = [
        'VACATION' => 'Vacation Leave',
        'SICK' => 'Sick Leave',
        'BUSINESS_TRAVEL' => 'Business Travel / OB',
    ];

    /**
     * TS: `BUILT_IN_STATUSES` (hris.ts:374-379) — the built-in
     * employment-status tokens, which stored rows carry beside the values of
     * the HR-editable status list.
     *
     * @var array<string, string>
     */
    public const array BUILT_IN_STATUSES = [
        'REGULAR' => 'Regular',
        'PROBATIONARY' => 'Probationary',
        'CONTRACTUAL' => 'Contractual',
        'JOB_ORDER' => 'Job Order',
    ];

    private function __construct() {}

    /**
     * TS: `fmtDuration(minutes)` — `457` reads as `7h 37m`. Whole hours drop
     * the minutes and spans under an hour drop the hours, so nothing shows 0;
     * a negative span keeps its sign and a non-finite one reads as 0m.
     *
     * The one duration spelling: the DTR table, tiles and dialog
     * (`Edtr\Support\Hris::fmtDuration`) and the overtime audit rows,
     * notices and refusals (`OvertimeApprovalPolicy::duration`) forward here,
     * so the audit trail cannot drift from the screen.
     */
    public static function duration(float $minutes): string
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
     * A weekday number — 0 Sunday to 6 Saturday, the `shifts.work_days` and
     * `roster_locations.weekday` numbering — as "Mon" or "Monday". The one
     * spelling the roster's Location cells, its Weekly locations dialog and
     * the Timekeeping module's audit sentence share: module code may not
     * import `Livewire\Records\Support\Hris::WEEK`, so the word lives here.
     *
     * @throws \InvalidArgumentException outside 0–6 — a weekday is validated
     *                                   upstream, so this is a programming error, never input
     */
    public static function weekday(int $day, bool $short = true): string
    {
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        if ($day < 0 || $day > 6) {
            throw new \InvalidArgumentException("Weekday must be 0 (Sunday) to 6 (Saturday), got {$day}.");
        }

        return $short ? substr($names[$day], 0, 3) : $names[$day];
    }

    /**
     * TS: `fmtHm(value)` (hris.ts:217-225) — a stored `HH:mm` or `HH:mm:ss`
     * as `5:00 PM`. Blank is an em dash; anything unparseable echoes back.
     */
    public static function hm(?string $value): string
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

    /**
     * TS: `fmtDate(d)` (hris.ts:209-214) — a calendar day as `Aug 05, 2026`,
     * the day always two digits. A DATE column carries no zone to shift, so a
     * Carbon is formatted as it is and a string is read as its first ten
     * characters, `Y-m-d`. Blank is an em dash.
     */
    public static function date(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return "\u{2014}";
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('M d, Y');
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), 'UTC');

        return $day === null ? $value : $day->format('M d, Y');
    }

    /**
     * TS: `humanSize(bytes)` (attachments.ts:25-28) — `1.5 MB` from a million
     * bytes up (`toFixed(1)`: one decimal, no thousands separator), otherwise
     * whole kilobytes and never less than `1 KB`.
     */
    public static function bytes(int $bytes): string
    {
        if ($bytes >= 1_000_000) {
            return number_format($bytes / 1_000_000, 1, '.', '').' MB';
        }

        // Math.round(bytes / 1000) for a non-negative count, without round().
        return max(1, intdiv(max(0, $bytes) + 500, 1000)).' KB';
    }

    /**
     * TS: `${pattern.required_hours} h per day` — the FLEXIBLE pattern's daily
     * hours as the roster's SHIFT_ASSIGNED sentence (roster-page.tsx:147-155)
     * and the Assign dialog's summary (add-shift-dialog.tsx:160-170) both
     * write them. A whole number drops its `.0` (JS prints 8, not 8.0), a
     * half keeps it (7.5), and null — a FIXED pattern's column — reads as 0,
     * the `?? 0` fallback the dialog uses. No round(): the value is printed,
     * never rounded, and PayrollArchitectureTest bans round() here anyway.
     */
    public static function hoursPerDay(float|int|null $hours): string
    {
        $hours ??= 0;

        $label = (float) $hours === (float) (int) $hours ? (string) (int) $hours : (string) $hours;

        return $label.' h per day';
    }

    /**
     * TS: `employmentStatusLabel(v)` (hris.ts:389-390) — the label of a
     * built-in status token; an HR-defined status is already its own label.
     */
    public static function employmentStatus(string $value): string
    {
        return self::BUILT_IN_STATUSES[$value] ?? $value;
    }

    /**
     * TS: `normalizeRequestType(name)` (hris.ts:328) — a leave-type name as
     * the token the request row stores: upper-cased, runs of anything but
     * A-Z0-9 collapsed to `_`, and no leading or trailing `_`.
     */
    public static function normalizeRequestType(string $name): string
    {
        $key = (string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper(trim($name)));

        return trim($key, '_');
    }

    /**
     * TS: `requestDescription(value, leaveTypes)` (hris.ts:340-350) — a stored
     * request type as its label. A configured leave type whose normalised
     * name equals the token, with or without a `_LEAVE` suffix, wins; then the
     * built-in REQUEST_TYPES; then the token itself, title-cased word by word.
     *
     * @param  list<string>  $leaveTypeNames
     */
    public static function requestDescription(string $value, array $leaveTypeNames = []): string
    {
        foreach ($leaveTypeNames as $name) {
            if (self::requestTypeMatches(self::normalizeRequestType($name), $value)) {
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
     * A leave or official-business request's stored status as the screens,
     * exports and reports print it. The stored codes stay as they are; a
     * refused request reads DISAPPROVED, the word of the leave application
     * ("Disapproved due to"), while its row keeps `REJECTED`.
     */
    public static function requestStatus(string $status): string
    {
        return $status === 'REJECTED' ? 'DISAPPROVED' : $status;
    }

    /**
     * Whether a leave type's normalised key names a stored request token —
     * the `_LEAVE` tolerance of `requestDescription` (hris.ts:340-350) as ONE
     * body: legacy rows hold `SICK` where a filing today stores `SICK_LEAVE`,
     * so the key `SICK_LEAVE` matches both.
     */
    public static function requestTypeMatches(string $key, string $token): bool
    {
        return $key === $token || $key === $token.'_LEAVE';
    }
}
