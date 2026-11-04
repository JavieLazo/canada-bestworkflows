<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\AttendanceLog;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\Views\WorkSiteView;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Contracts\Views\CorrectionPunchView;
use App\Modules\Timekeeping\Contracts\Views\DayCorrectionView;
use App\Modules\Timekeeping\Contracts\Views\ShiftOnDateView;
use App\Modules\Timekeeping\Domain\Attendance\CorrectionWindow;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Modules\Timekeeping\Domain\Roster\ResolvedLocation;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Platform\ReadModels\Snapshot;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Illuminate\Support\Collection;

/**
 * What the DTR correction dialog shows for one employee on one date — the
 * read half of Commands\CorrectPunches, and deliberately built from the SAME
 * pieces: the shift and the place for that date through ShiftRoster, the
 * rows a save would supersede through CorrectionWindow::replaceable (over
 * the same four-day span the save locks), the slot minute through
 * PunchSlots::slotOf and its day through PunchSlots::isNextDay. The dialog
 * therefore never describes a day differently from how the save will treat
 * it.
 *
 * The rows listed are the ones the DTR reads as THIS date's row — never the
 * previous night's after-midnight punches, which only get counted and
 * pointed at — and each slot comes with its "Next day" tick. A slot the DTR
 * table shows but does not own (a Time In an unfinished previous night lends
 * the row, on the scheduled-break timeline) is left out of the prefill and
 * named instead, so a save cannot replace another night's punch.
 *
 * view() is all-data EDTR only, like the write it precedes: a self-only
 * viewer has no correction dialog to open, and an unlinked account nothing
 * to look at. computedDay() makes no check of its own: it is scoped by
 * AttendanceSource::forDtr (which also lets a self-only viewer compute their
 * own day), and it is shared with the overtime commands (DecideOvertime,
 * DecidePendingOvertime) and the demo OvertimeApprovalSeeder, so the dialog,
 * the decision and the demo all compute a day the same way.
 */
final class DayCorrections
{
    public function __construct(private readonly ShiftRoster $roster) {}

    /**
     * Null for a date that is not a calendar day or an employee the actor
     * cannot see; a 403 for an actor who may not correct at all.
     */
    public function view(Access $actor, string $employeeId, string $date): ?DayCorrectionView
    {
        abort_unless($actor->mayReadAllData(ModuleCode::EDTR), 403);

        if (! PayrollPeriod::isPayrollDate($date)) {
            return null;
        }

        $employee = app(EmployeeDirectory::class)->forTimekeeping($actor, $employeeId);
        if ($employee === null) {
            return null;
        }

        $range = PayrollPeriod::attendanceDateRange(PayrollPeriod::addCalendarDays($date, -1), $date);
        $schedule = $this->roster->scheduleForEmployee($employeeId, $range['from'], $range['to']);
        $rostered = $schedule?->forDate($date);
        $shift = $rostered ?? DomainShift::default();

        // The place a correction of this date writes: date override, weekday
        // row, then Assigned Worksite — the same fallback CorrectPunches
        // applies, so the dialog's "Location" is what the save will store.
        $location = $this->roster->locationForDate($employeeId, $date)
            ?? ResolvedLocation::assigned($employee->work_site_id);
        $sites = app(OrganizationDirectory::class)->sites($actor)->keyBy('id');

        $day = $this->computedDay($actor, $employeeId, $date);
        ['rows' => $rows, 'owned' => $owned] = $this->window($actor, $employeeId, $date, $schedule);

        // Which of the table's four slots are this row's own punches: the
        // instants the row owns. A slot shown but not owned is borrowed.
        $ownedAt = [];
        foreach ($owned as $log) {
            $ownedAt[ManilaClock::ms(ManilaClock::instant($log->loggedAt))] = true;
        }
        $slots = ['time_in' => '', 'break_out' => '', 'break_in' => '', 'time_out' => ''];
        $nextDay = PunchSlots::SAME_DAY;
        $borrowed = [];
        $shownAt = [];
        foreach (self::dayStamps($day) as $key => $iso) {
            if ($iso === null || $iso === '') {
                continue;
            }
            $at = ManilaClock::ms(ManilaClock::instant($iso));
            $shownAt[$at] = true;
            if (! isset($ownedAt[$at])) {
                $borrowed[$key] = $iso;

                continue;
            }
            $slots[$key] = PunchSlots::slotOf($iso) ?? '';
            $nextDay[$key] = PunchSlots::isNextDay($iso, $date);
        }

        $ownedIds = [];
        foreach ($owned as $log) {
            $ownedIds[(string) $log->id] = true;
        }
        $previousDayPunches = 0;
        foreach ($rows as $row) {
            if (! isset($ownedIds[(string) $row->id]) && ManilaClock::dayKey($row->logged_at) === $date) {
                $previousDayPunches++;
            }
        }

        return new DayCorrectionView(
            employee_id: (string) $employee->id,
            employee_name: EmployeeName::display($employee),
            date: $date,
            shift: $this->shiftOnDate($date, $rostered !== null, $shift),
            location: RosterLocations::onDate($date, $location, $sites),
            day: $day,
            remark: trim(app(AttendanceRemarks::class)->between($actor, $employeeId, $date, $date)[$date] ?? ''),
            slots: $slots,
            punches: $this->punches($rows, $owned, $date, $sites, $shownAt),
            overtime: $day === null ? null : (app(OvertimeDecisions::class)->forDays($actor, $employeeId, [$day], $date, $date)[$date] ?? null),
            // view() already requires all-data EDTR; that includes one's own day.
            can_decide_overtime: true,
            next_day: $nextDay,
            next_day_allowed: CorrectionWindow::allowsNextDay($rostered),
            borrowed: $borrowed,
            previous_day_punches: $previousDayPunches,
            // The display read, without locks; CorrectPunches asks again under one.
            posted: (app(PostedPayrollDates::class)->coveredPeriods($actor, [$employeeId], $date, $date)[$employeeId] ?? []) !== [],
        );
    }

    /**
     * One day as the DTR computes it, for the correction dialog AND the
     * overtime decision (Commands\DecideOvertime) — one body, so the dialog
     * never shows a day, or an overtime figure, that the save computes
     * differently. The DTR never computes a day that has not happened
     * (EdtrPage caps its period at today), so a future date, like a
     * non-date, has no verdict: null.
     */
    public function computedDay(Access $actor, string $employeeId, string $date): ?DayRecord
    {
        if (! PayrollPeriod::isPayrollDate($date) || $date > ManilaClock::todayKey()) {
            return null;
        }

        $inputs = app(AttendanceSource::class)->forDtr($actor, $employeeId, $date, $date);

        // Keep the full schedule so yesterday's night tail is grouped using
        // yesterday's hours even when this date has a different shift.
        return DtrCalculator::compute(
            $inputs->logs,
            $inputs->shift,
            $inputs->leaves,
            $date,
            $date,
            app(HolidayCalendar::class)->all(),
        )[0] ?? null;
    }

    private function shiftOnDate(string $date, bool $rostered, DomainShift $shift): ShiftOnDateView
    {
        $geometry = ShiftResolver::geometry($shift);

        return new ShiftOnDateView(
            date: $date,
            rostered: $rostered,
            mode: $shift->isFlexible() ? DomainShift::MODE_FLEXIBLE : DomainShift::MODE_FIXED,
            start_time: $shift->startTime,
            end_time: $shift->endTime,
            grace_minutes: $shift->graceMinutes,
            required_hours: $shift->requiredHours,
            break_start: $shift->breakStart,
            break_end: $shift->breakEnd,
            break_minutes: $shift->breakMinutes ?? 0,
            overnight: $geometry->overnight,
            uses_scheduled_break: ShiftResolver::scheduledBreakWindow($shift, $geometry) !== null,
            is_work_day: ShiftResolver::worksOn($date, ShiftResolver::workWeek($shift)),
        );
    }

    /**
     * The four stamps the DTR table shows for the day, by slot key.
     *
     * @return array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}
     */
    private static function dayStamps(?DayRecord $day): array
    {
        return [
            'time_in' => $day?->timeIn,
            'break_out' => $day?->breakOut,
            'break_in' => $day?->breakIn,
            'time_out' => $day?->timeOut,
        ];
    }

    /**
     * Two dates before the correction through the next day's rows — the same
     * span the command reads under lock — and the ones the DTR
     * reads as this date's row (CorrectionWindow::replaceable).
     *
     * @return array{rows: Collection<int, AttendanceLog>, owned: list<DomainLog>}
     */
    private function window(Access $actor, string $employeeId, string $date, ?DomainShift $schedule): array
    {
        $days = PayrollPeriod::attendanceDateRange(PayrollPeriod::addCalendarDays($date, -1), $date);
        $span = PayrollPeriod::attendanceInstants($days['from'], $days['to']);
        $rows = AttendanceLog::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->where('employee_id', $employeeId)
            ->whereBetween('logged_at', [$span['gte'], $span['lte']])
            ->orderBy('logged_at')
            ->orderBy('id')
            ->get();

        $logs = array_values($rows->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray([
            'id' => $row->id, 'log_type' => $row->log_type, 'logged_at' => $row->logged_at,
        ]))->all());

        return ['rows' => $rows, 'owned' => CorrectionWindow::replaceable($date, $logs, $schedule)];
    }

    /**
     * The rows a save of this day would supersede, oldest first, each with
     * its captured location, whether it falls on the next day, whether the
     * DTR table shows it in one of the four slots at all, and whether it was
     * entered by hand (MANUAL at the station, or a saved CORRECTION).
     *
     * @param  Collection<int, AttendanceLog>  $rows
     * @param  list<DomainLog>  $owned  window()
     * @param  Collection<array-key, WorkSiteView>  $sites  keyed by id
     * @param  array<int, true>  $shownAt  the instants (ms) the DTR row shows in a slot
     * @return list<CorrectionPunchView>
     */
    private function punches(Collection $rows, array $owned, string $date, Collection $sites, array $shownAt): array
    {
        $byId = $rows->keyBy('id');

        $out = [];
        foreach ($owned as $log) {
            $row = $byId->get((string) $log->id);
            if (! $row instanceof AttendanceLog) {
                continue;
            }
            $iso = DomainLog::isoFromUtc($row->logged_at);
            $out[] = Snapshot::of($row, CorrectionPunchView::class, [
                'site_name' => $row->site_id === null ? null : $sites->get($row->site_id)?->name,
                'slot' => PunchSlots::slotOf($iso),
                'is_carried' => PunchSlots::isNextDay($iso, $date),
                'in_dtr_slot' => isset($shownAt[ManilaClock::ms(ManilaClock::instant($iso))]),
                // An admin's MANUAL punch at the station, or a row a saved
                // correction inserted: the dialog says "Manually Entered" for
                // these, with no place.
                'hand_entered' => in_array($row->source, ['MANUAL', AttendanceLog::SOURCE_CORRECTION], true),
            ]);
        }

        return $out;
    }
}
