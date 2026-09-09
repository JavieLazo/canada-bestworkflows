<?php

declare(strict_types=1);

namespace App\Livewire\Roster;

use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Support\EmployeeName;

/**
 * Display helpers for the Roster screens — the slice of `src/lib/hris.ts`
 * this module reads (fmtHm, statusKey, employmentStatusLabel, employeeName)
 * plus the two cell formatters the roster table defines for itself
 * (roster-page.tsx:41-51 `hoursOf` / `breakOf`).
 *
 * Lives inside the Roster namespace because there is no shared HRIS display
 * helper in the app yet and this module may only write its own files; keep it
 * in sync with any sibling module's copy until a shared home exists.
 */
final class Hris
{
    /**
     * The four statuses that shipped before the list was editable
     * (hris.ts:376-381). Rows written back then hold `JOB_ORDER` where a row
     * written now holds "Job Order".
     *
     * @var array<string, string>
     */
    private const array BUILT_IN_STATUSES = [
        'REGULAR' => 'Regular',
        'PROBATIONARY' => 'Probationary',
        'CONTRACTUAL' => 'Contractual',
        'JOB_ORDER' => 'Job Order',
    ];

    /**
     * A stored `"HH:mm"` / `"HH:mm:ss"` clock time as `"5:00 PM"`.
     * Unparseable input echoes back (hris.ts:217-226).
     */
    public static function fmtHm(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
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

    /** "Job Order", "JOB_ORDER" and "job order" are one status (hris.ts:382-387). */
    public static function statusKey(?string $value): string
    {
        $key = strtoupper(trim((string) $value));
        $key = (string) preg_replace('/[^A-Z0-9]+/', '_', $key);

        return trim($key, '_');
    }

    public static function employmentStatusLabel(string $value): string
    {
        return self::BUILT_IN_STATUSES[$value] ?? $value;
    }

    public static function employeeName(?EmployeeProfile $employee): string
    {
        return EmployeeName::display($employee);
    }

    /** roster-page.tsx:38-39. */
    public static function initials(EmployeeProfile $employee): string
    {
        return EmployeeName::initials($employee);
    }

    /**
     * Locale-aware-ish compare that always sorts blanks last — the port of
     * `compareText` (useSort.ts:33-40). The roster reverses the whole sorted
     * list for descending order, exactly as the source did, so blanks land
     * first on desc there too.
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

    /** roster-page.tsx:41-44. */
    public static function hoursOf(?Shift $shift): string
    {
        if ($shift === null) {
            return '—';
        }

        return self::fmtHm($shift->start_time).'–'.self::fmtHm($shift->end_time);
    }

    /** roster-page.tsx:46-51. */
    public static function breakOf(?Shift $shift): string
    {
        if ($shift === null) {
            return '—';
        }

        if ($shift->break_start !== null && $shift->break_start !== ''
            && $shift->break_end !== null && $shift->break_end !== '') {
            return self::fmtHm($shift->break_start).'–'.self::fmtHm($shift->break_end);
        }

        return $shift->break_minutes ? $shift->break_minutes.' min' : '—';
    }

    /**
     * The one-line description of a chosen pattern under the dialog's select
     * (add-shift-dialog.tsx:160-170).
     */
    public static function patternSummary(ShiftType $pattern): string
    {
        if ($pattern->mode === 'FLEXIBLE') {
            return ($pattern->required_hours ?? 0).' h per day';
        }

        $summary = self::fmtHm($pattern->start_time).'–'.self::fmtHm($pattern->end_time)
            .' · grace '.$pattern->grace_minutes.'m';

        if ($pattern->break_start !== null && $pattern->break_start !== '') {
            $summary .= ' · break '.self::fmtHm($pattern->break_start).'–'.self::fmtHm($pattern->break_end);
        }

        return $summary;
    }
}
