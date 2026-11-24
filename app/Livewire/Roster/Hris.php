<?php

declare(strict_types=1);

namespace App\Livewire\Roster;

use App\Domain\Attendance\Shift as DomainShift;
use App\Livewire\Records\Support\Hris as RecordsHris;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Modules\Timekeeping\Contracts\Views\RosterLocationView;
use App\Modules\Timekeeping\Contracts\Views\RosterShiftView;
use App\Modules\Timekeeping\Contracts\Views\ShiftTypeView;
use App\Modules\Timekeeping\Contracts\Views\ShiftView;
use App\Modules\Timekeeping\Domain\Roster\CustomHours;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Domain\Roster\WorkWeek;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\TextSort;

/**
 * Display helpers for the Roster screens — the slice of `src/lib/hris.ts`
 * this module reads (fmtHm, statusKey, employmentStatusLabel, employeeName)
 * plus the two cell formatters the roster table defines for itself
 * (roster-page.tsx:41-51 `hoursOf` / `breakOf`).
 *
 * The hris.ts members forward to App\Support\HrisFormat, their shared home;
 * the wrappers stay so the roster's call sites and Blade imports keep
 * pointing here. `hoursOf()` is the one member that does not match its
 * reference line for line: a FLEXIBLE row reads as its daily hours, never
 * the filler clock (see there).
 */
final class Hris
{
    /**
     * A stored `"HH:mm"` / `"HH:mm:ss"` clock time as `"5:00 PM"`.
     * Unparseable input echoes back (hris.ts:217-225), shared through HrisFormat.
     */
    public static function fmtHm(?string $value): string
    {
        return HrisFormat::hm($value);
    }

    /**
     * A custom-hours draft as the option the day selects offer: "7:00 AM–3:00
     * PM (custom)", or "Custom hours" while a time is still missing.
     */
    public static function customHoursLabel(mixed $hours): string
    {
        $start = is_array($hours) && is_string($hours['start'] ?? null) ? trim($hours['start']) : '';
        $end = is_array($hours) && is_string($hours['end'] ?? null) ? trim($hours['end']) : '';

        return $start === '' || $end === '' ? CustomHours::NAME : self::fmtHm($start).'–'.self::fmtHm($end).' (custom)';
    }

    /** "Job Order", "JOB_ORDER" and "job order" are one status (hris.ts:382-387). */
    public static function statusKey(?string $value): string
    {
        $key = strtoupper(trim((string) $value));
        $key = (string) preg_replace('/[^A-Z0-9]+/', '_', $key);

        return trim($key, '_');
    }

    /** The four built-in statuses, as words (hris.ts:389-390), shared through HrisFormat. */
    public static function employmentStatusLabel(string $value): string
    {
        return HrisFormat::employmentStatus($value);
    }

    public static function employeeName(EmployeeProfile|DirectoryEmployee|null $employee): string
    {
        return EmployeeName::display($employee);
    }

    /** roster-page.tsx:38-39. */
    public static function initials(EmployeeProfile|DirectoryEmployee $employee): string
    {
        return EmployeeName::initials($employee);
    }

    /**
     * Case-insensitive byte compare that always sorts blanks last — the port
     * of `compareText` (useSort.ts:33-40), shared through App\Support\TextSort.
     * The roster reverses the whole sorted list for descending order, exactly
     * as the source did, so blanks land first on desc there too.
     */
    public static function compareText(?string $a, ?string $b): int
    {
        // One comparator for every sorted list, so no module sorts by a rule of
        // its own, such as a case-only tiebreak or ICU collation. This stays a
        // wrapper so no call site or Blade import changes; TextSortTest pins it.
        return TextSort::compare($a, $b);
    }

    /**
     * The Hours cell (roster-page.tsx:41-44).
     *
     * DELIBERATE DIVERGENCE: the reference printed `start_time–end_time` for
     * every row, so a FLEXIBLE row — whose `shifts` row stores the filler
     * 08:00/17:00 clock by design (attendance.ts:87-102) — read "8:00 AM–5:00
     * PM" as if it were fixed. Here a FLEXIBLE row reads as its daily hours,
     * the way the Records table and the eDTR dialog already describe it. A
     * null or unknown mode stays on the clock, the engine's own rule
     * (`DomainShift::isFlexible()` tests only for MODE_FLEXIBLE).
     */
    public static function hoursOf(Shift|ShiftView|null $shift): string
    {
        if ($shift === null) {
            return '—';
        }

        if ($shift->mode === DomainShift::MODE_FLEXIBLE) {
            return 'Flexible · '.HrisFormat::hoursPerDay($shift->required_hours);
        }

        return self::fmtHm($shift->start_time).'–'.self::fmtHm($shift->end_time);
    }

    /** roster-page.tsx:46-51. */
    public static function breakOf(Shift|ShiftView|null $shift): string
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

    /** A pattern's hours alone, for an option label: "7:00 AM–4:00 PM", or "8 h per day" when flexible. */
    public static function patternHours(ShiftType|ShiftTypeView $pattern): string
    {
        return $pattern->mode === DomainShift::MODE_FLEXIBLE
            ? HrisFormat::hoursPerDay($pattern->required_hours)
            : self::fmtHm($pattern->start_time).'–'.self::fmtHm($pattern->end_time);
    }

    /**
     * The one-line description of a chosen pattern under the dialog's select
     * (add-shift-dialog.tsx:160-170). The FLEXIBLE branch forwards to
     * `HrisFormat::hoursPerDay()`, the spelling `hoursOf()` and the audit
     * sentence share, so the three never disagree on "8 h per day".
     */
    public static function patternSummary(ShiftType|ShiftTypeView $pattern): string
    {
        if ($pattern->mode === DomainShift::MODE_FLEXIBLE) {
            return HrisFormat::hoursPerDay($pattern->required_hours);
        }

        $summary = self::fmtHm($pattern->start_time).'–'.self::fmtHm($pattern->end_time)
            .' · grace '.$pattern->grace_minutes.'m';

        if ($pattern->break_start !== null && $pattern->break_start !== '') {
            $summary .= ' · break '.self::fmtHm($pattern->break_start).'–'.self::fmtHm($pattern->break_end);
        }

        return $summary;
    }

    /** Saved weekday hours, including copies whose source pattern was deleted. */
    public static function weekdayHours(RosterShiftView $day): string
    {
        if ($day->is_rest_day) {
            return 'Rest day';
        }

        $shift = new ShiftView($day->shift_data);

        return self::hoursOf($shift);
    }

    /**
     * The weekdays an employee's weekly schedule works, 0 Sunday … 6 Saturday,
     * ascending: the standing pattern's work week (`Records\Support\Hris::workWeek`,
     * Monday–Friday when there is no row or it predates `work_days`), then
     * each weekday choice — a REST pick rests that day, a pattern pick works
     * it regardless of the pattern's own work week (the time-and-DTR guide).
     * The Default schedule dialog's rest-day hints and placeSummary() both
     * read this, so the two cannot disagree.
     *
     * @param  list<int>|null  $workDays  the standing row's `work_days`
     * @param  array<int, bool>  $overrides  weekday => works, from the saved weekday shifts or the dialog's draft
     * @return list<int>
     */
    public static function workingWeekdays(?array $workDays, array $overrides = []): array
    {
        $works = array_fill_keys(RecordsHris::workWeek($workDays), true);

        foreach ($overrides as $weekday => $worksThatDay) {
            if ($worksThatDay) {
                $works[$weekday] = true;
            } else {
                unset($works[$weekday]);
            }
        }

        $days = array_keys($works);
        sort($days);

        return $days;
    }

    /**
     * The Location cell (ems only — the reference has one worksite per
     * employee): the place the employee works on MOST working days, then
     * each other place with the days it applies, in the week's order
     * (`WorkWeek`: Sunday first, Saturday last), consecutive days merged —
     * "Mon–Wed, Fri", "Sun–Mon", never Saturday joined to the Sunday after
     * it (the `Records\Support\Hris::describeWeek` spelling). A working
     * weekday with no row is the Assigned Worksite from
     * Employee Records, because that is what RecordPunch falls back to, and a
     * weekday rostered AT that site is the same place; rest days are left
     * out, so a place nobody is scheduled at never shows. A tie for the main
     * place goes to the Assigned Worksite, then to the place seen first in
     * the week. Pure — it reads the views it is given and nothing else, so
     * tests/Unit pins it.
     *
     * @param  array<int, RosterLocationView>  $week  keyed by weekday, 0 Sunday … 6 Saturday; the rows in effect
     * @param  list<int>  $workingWeekdays  from `workingWeekdays()`
     * @param  string|null  $assignedSiteId  the Assigned Worksite, null when none is set
     * @param  string|null  $assignedSiteName  its name
     * @return array{main: string|null, others: list<array{days: string, label: string}>} `main` is null when it is the unset Assigned Worksite
     */
    public static function placeSummary(array $week, array $workingWeekdays, ?string $assignedSiteId, ?string $assignedSiteName): array
    {
        $assigned = $assignedSiteId === null ? 'assigned' : 'site:'.$assignedSiteId;
        /** @var array<string, array{label: string|null, first: int, days: list<int>}> $places keyed by place; `first` and `days` are positions in the week's order */
        $places = [];

        foreach (WorkWeek::weekdays() as $position => $weekday) {
            if (! in_array($weekday, $workingWeekdays, true)) {
                continue;
            }

            $view = $week[$weekday] ?? null;

            if ($view === null) {
                [$key, $label] = [$assigned, $assignedSiteName];
            } elseif ($view->kind === LocationKind::WFH) {
                [$key, $label] = ['wfh', LocationKind::WFH];
            } else {
                [$key, $label] = ['site:'.($view->site_id ?? ''), $view->site_name ?? 'Site unavailable'];
            }

            $places[$key] ??= ['label' => $label, 'first' => $position, 'days' => []];
            $places[$key]['days'][] = $position;
        }

        if ($places === []) {
            return ['main' => $assignedSiteName, 'others' => []];
        }

        // Most days first; a tie goes to the Assigned Worksite, then to the
        // place seen first in the week.
        uksort($places, static fn (string $a, string $b): int => [count($places[$b]['days']), $a === $assigned ? 0 : 1, $places[$a]['first']]
            <=> [count($places[$a]['days']), $b === $assigned ? 0 : 1, $places[$b]['first']]);

        $main = array_shift($places);

        uasort($places, static fn (array $a, array $b): int => $a['first'] <=> $b['first']);

        return [
            'main' => $main['label'],
            'others' => array_values(array_map(static fn (array $place): array => [
                'days' => self::dayRanges($place['days']),
                'label' => $place['label'] ?? 'No worksite set',
            ], $places)),
        ];
    }

    /**
     * "Mon–Wed, Fri" from positions in the week's order: consecutive
     * positions merge into a range with an en dash, the rest are listed.
     *
     * @param  list<int>  $positions  ascending, 0 the week's first day … 6 its last
     */
    private static function dayRanges(array $positions): string
    {
        $labels = array_map(static fn (int $weekday): string => HrisFormat::weekday($weekday), WorkWeek::weekdays());
        $ranges = [];
        $from = $to = null;

        foreach ($positions as $position) {
            if ($to !== null && $position === $to + 1) {
                $to = $position;

                continue;
            }
            if ($from !== null) {
                $ranges[] = $from === $to ? $labels[$from] : $labels[$from].'–'.$labels[$to];
            }
            $from = $to = $position;
        }
        if ($from !== null) {
            $ranges[] = $from === $to ? $labels[$from] : $labels[$from].'–'.$labels[$to];
        }

        return implode(', ', $ranges);
    }
}
