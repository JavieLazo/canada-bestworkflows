<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\AttendanceLog;
use App\Models\LeaveTravelRequest;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Domain\Roster\EffectiveDatePolicy;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Holds every schedule change to the history it must not disturb. One
 * employee at a time, inside the change's own transaction:
 *
 *   before()  — after the employee row is locked and BEFORE anything is
 *               written: refuses a past date and a day already paid on a
 *               posted payslip, locks the employee's recent punches so none
 *               can land between the check and the commit, and records the
 *               attendance of the three dates before the change.
 *   verify()  — after the rows are written, reading them back through the
 *               same resolver the DTR uses: today's cutoff (D4), the earlier
 *               dates' records and punch ownership unchanged (so yesterday's
 *               night keeps its morning punches), and no shift newly left
 *               running into the next one (D5).
 *
 * `beforeFill()` / `verifyFill()` are the pair for the one change that
 * reaches back — past dates that had no shift — holding the dates around
 * them instead of the dates before.
 *
 * A refusal throws and the caller's transaction rolls back, so a change that
 * fails any rule writes nothing. Trusted internal reads: the commands that
 * call this have already authorized the actor.
 */
final class ScheduleGuard
{
    /** Dates before the change whose records are compared: the engine's carry looks two back. */
    private const PROTECTED_DAYS = 3;

    /** How far past an open-ended change the overlap rule looks: two full weeks of weekdays. */
    private const OPEN_HORIZON_DAYS = 14;

    public function __construct(private readonly ShiftRoster $roster) {}

    /** @throws ScheduleChangeRefused */
    public function before(string $employeeId, string $from, ?string $to = null): ScheduleSnapshot
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Schedule changes are guarded inside their own transaction.');
        }
        $today = ManilaClock::todayKey();
        $error = EffectiveDatePolicy::dateError($from, $today) ?? ($to === null ? null : EffectiveDatePolicy::dateError($to, $today));
        if ($error !== null) {
            throw new ScheduleChangeRefused($error);
        }
        if ($to !== null && $to < $from) {
            throw new ScheduleChangeRefused('The last date cannot be before the first date.');
        }
        $posted = app(PostedPayrollDates::class);
        if ($posted->lockAndCovers($employeeId, $from)) {
            throw new ScheduleChangeRefused(HrisFormat::date($from).' is already paid on a posted payslip, so its schedule can no longer change. Choose a date after that payroll period.');
        }
        // A change reaches every date from `$from` on (through `$to`, or for
        // good), so a payslip posted AHEAD for any of them refuses it too.
        $paidThrough = $posted->lockAndPaidThrough($employeeId, $from, $to);
        if ($paidThrough !== null) {
            throw new ScheduleChangeRefused('Dates after '.HrisFormat::date($from).' are already paid on a posted payslip through '.HrisFormat::date($paidThrough).', so this change would reach them. Choose a date after '.HrisFormat::date($paidThrough).'.');
        }

        $contextFrom = PayrollPeriod::addCalendarDays($from, -(self::PROTECTED_DAYS + 2));
        $horizon = $to === null ? PayrollPeriod::addCalendarDays($from, self::OPEN_HORIZON_DAYS) : PayrollPeriod::addCalendarDays($to, 1);
        // The punches that decide those records, and today's: locked, so a
        // punch waits for this change (RecordPunch locks the same rows).
        $window = PayrollPeriod::attendanceInstants($contextFrom, max($from, $today));
        $logs = AttendanceLog::query()->where('employee_id', $employeeId)
            ->whereBetween('logged_at', [$window['gte'], $window['lte']])
            ->orderBy('logged_at')->orderBy('id')->lockForUpdate()->get(['id', 'log_type', 'logged_at'])
            ->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray(['id' => $row->id, 'employee_id' => $employeeId, 'log_type' => $row->log_type, 'logged_at' => $row->logged_at]))
            ->all();
        $logs = array_values($logs);
        // A leave approved partly with pay is read as its paid part and its
        // part without pay, as the DTR reads it (LeavePayDates).
        $leaves = [];
        foreach (LeaveTravelRequest::query()->with('cancellations:id,leave_travel_request_id,date')->where('employee_id', $employeeId)
            ->get(LeaveHalves::columns(['id', 'employee_id', 'request_type', 'day_type', 'start_date', 'end_date', 'status', 'is_paid', ...(LeavePayDates::ready() ? ['unpaid_dates'] : [])])) as $leave) {
            foreach (LeavePayDates::domain($leave, LeaveHalves::of($leave->half ?? null)) as $part) {
                $leaves[] = $part;
            }
        }
        $holidays = app(HolidayCalendar::class)->all();

        $protected = [];
        foreach (range(self::PROTECTED_DAYS, 1) as $back) {
            $date = PayrollPeriod::addCalendarDays($from, -$back);
            // A date that has not happened has no record to protect.
            if ($date <= $today) {
                $protected[] = $date;
            }
        }
        $schedule = $this->roster->scheduleForEmployee($employeeId, $contextFrom, $horizon);

        return new ScheduleSnapshot(
            $employeeId, $from, $to, $today, $contextFrom, $horizon, $logs, $leaves, $holidays, $schedule, $protected,
            self::records($logs, $schedule, $leaves, $holidays, $protected),
            self::overlapKeys($schedule, PayrollPeriod::addCalendarDays($from, -1), $horizon),
            $from === $today ? $this->place($employeeId, $today) : '',
        );
    }

    /**
     * @throws TodayNotEligible when the change is for today and today's cutoff has passed
     * @throws ScheduleChangeRefused when it would disturb an earlier date or overlap a running shift
     */
    public function verify(ScheduleSnapshot $before): void
    {
        $after = $this->roster->scheduleForEmployee($before->employeeId, $before->contextFrom, $before->horizon);

        // Today's cutoff guards today's own schedule: a change dated today
        // that leaves today's hours, working day and place exactly as they
        // were (a change to another weekday) has nothing of today to guard.
        if ($before->from === $before->today && $this->changesToday($before, $after)) {
            $punched = DtrCalculator::rowPunches($before->logs, $before->schedule, $before->today) !== []
                || DtrCalculator::rowPunches($before->logs, $after, $before->today) !== [];
            $now = CarbonImmutable::now(ManilaClock::TZ);
            $reason = EffectiveDatePolicy::todayCutoff($before->schedule, $after, $before->today, ManilaClock::minutesOfDay($now), $punched);
            if ($reason !== null) {
                throw new TodayNotEligible($reason);
            }
        }

        if (self::records($before->logs, $after, $before->leaves, $before->holidays, $before->protected) !== $before->records) {
            throw new ScheduleChangeRefused(self::protectedSentence($before));
        }

        $new = array_values(array_diff(self::overlapKeys($after, PayrollPeriod::addCalendarDays($before->from, -1), $before->horizon), $before->overlaps));
        if ($new !== []) {
            [$date, $ends, $next, $starts] = explode('|', $new[0]);
            throw new ScheduleChangeRefused('The shift that starts on '.HrisFormat::date($date)." ends at {$ends} the next day, after the shift on ".HrisFormat::date($next)." starts at {$starts}. Adjust one of them so they do not overlap.");
        }
    }

    /**
     * Holds a FILL of past dates that had no shift (`FillUnscheduledDates`)
     * to the dates around it — the one change that reaches back, and only
     * onto the dates it names. Refuses a date already paid on a posted
     * payslip, locks the punches from five dates before the first through
     * four after the last (today at the latest), and records every date
     * from three before the first through three after the last that has
     * happened and is NOT being filled: the three before, the ones between,
     * the three after. `verifyFill()` holds those exactly as they were, so a
     * night shift given to a filled date never takes a punch from the next.
     *
     * @param  non-empty-list<string>  $dates  sorted, unique, each before today
     *
     * @throws ScheduleChangeRefused
     */
    public function beforeFill(string $employeeId, array $dates): ScheduleSnapshot
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Schedule changes are guarded inside their own transaction.');
        }
        $today = ManilaClock::todayKey();
        $first = $dates[0];
        $last = $dates[count($dates) - 1];
        $paid = array_values(array_intersect(app(PostedPayrollDates::class)->lockAndPaidDates($employeeId, $first, $last), $dates));
        if ($paid !== []) {
            throw new ScheduleChangeRefused(implode(', ', array_map(HrisFormat::date(...), $paid)).(count($paid) === 1 ? ' is' : ' are').' already paid on a posted payslip, so '.(count($paid) === 1 ? 'its' : 'their').' schedule can no longer change.');
        }

        $contextFrom = PayrollPeriod::addCalendarDays($first, -(self::PROTECTED_DAYS + 2));
        $horizon = PayrollPeriod::addCalendarDays($last, self::PROTECTED_DAYS + 1);
        $window = PayrollPeriod::attendanceInstants($contextFrom, min($horizon, $today));
        $logs = array_values(AttendanceLog::query()->where('employee_id', $employeeId)
            ->whereBetween('logged_at', [$window['gte'], $window['lte']])
            ->orderBy('logged_at')->orderBy('id')->lockForUpdate()->get(['id', 'log_type', 'logged_at'])
            ->map(static fn (AttendanceLog $row): DomainLog => DomainLog::fromArray(['id' => $row->id, 'employee_id' => $employeeId, 'log_type' => $row->log_type, 'logged_at' => $row->logged_at]))
            ->all());
        $leaves = [];
        foreach (LeaveTravelRequest::query()->with('cancellations:id,leave_travel_request_id,date')->where('employee_id', $employeeId)
            ->get(LeaveHalves::columns(['id', 'employee_id', 'request_type', 'day_type', 'start_date', 'end_date', 'status', 'is_paid', ...(LeavePayDates::ready() ? ['unpaid_dates'] : [])])) as $leave) {
            foreach (LeavePayDates::domain($leave, LeaveHalves::of($leave->half ?? null)) as $part) {
                $leaves[] = $part;
            }
        }
        $holidays = app(HolidayCalendar::class)->all();

        $filled = array_flip($dates);
        $protected = [];
        foreach (PayrollPeriod::eachDate(PayrollPeriod::addCalendarDays($first, -self::PROTECTED_DAYS), PayrollPeriod::addCalendarDays($last, self::PROTECTED_DAYS)) as $date) {
            if (! isset($filled[$date]) && $date <= $today) {
                $protected[] = $date;
            }
        }
        $schedule = $this->roster->scheduleForEmployee($employeeId, $contextFrom, $horizon);

        return new ScheduleSnapshot(
            $employeeId, $first, $last, $today, $contextFrom, $horizon, $logs, $leaves, $holidays, $schedule, $protected,
            self::recordsOn($logs, $schedule, $leaves, $holidays, $protected),
            self::overlapKeys($schedule, PayrollPeriod::addCalendarDays($first, -1), $horizon),
            '',
        );
    }

    /**
     * After a fill is written: the dates around it read exactly as before,
     * and no shift newly runs into the next one.
     *
     * @throws ScheduleChangeRefused
     */
    public function verifyFill(ScheduleSnapshot $before): void
    {
        $after = $this->roster->scheduleForEmployee($before->employeeId, $before->contextFrom, $before->horizon);
        if (self::recordsOn($before->logs, $after, $before->leaves, $before->holidays, $before->protected) !== $before->records) {
            throw new ScheduleChangeRefused('This schedule would also change the attendance of a date next to the ones picked — for example by moving a morning punch into a night shift that starts the day before. Pick a shift that ends before the next date begins, or fill that date too.');
        }
        $new = array_values(array_diff(self::overlapKeys($after, PayrollPeriod::addCalendarDays($before->from, -1), $before->horizon), $before->overlaps));
        if ($new !== []) {
            [$date, $ends, $next, $starts] = explode('|', $new[0]);
            throw new ScheduleChangeRefused('The shift that starts on '.HrisFormat::date($date)." ends at {$ends} the next day, after the shift on ".HrisFormat::date($next)." starts at {$starts}. Adjust one of them so they do not overlap.");
        }
    }

    /**
     * `records()` for dates that need not follow one another: the DTR over
     * their whole span, keeping only their rows and the punches each owns.
     *
     * @param  list<DomainLog>  $logs
     * @param  list<DomainLeave>  $leaves
     * @param  list<HolidaySetting>  $holidays
     * @param  list<string>  $dates  sorted
     */
    private static function recordsOn(array $logs, ?DomainShift $schedule, array $leaves, array $holidays, array $dates): string
    {
        if ($dates === []) {
            return '';
        }
        $keep = array_flip($dates);
        $rows = array_values(array_filter(
            DtrCalculator::compute($logs, $schedule, $leaves, $dates[0], $dates[count($dates) - 1], $holidays),
            static fn ($row): bool => isset($keep[$row->date]),
        ));
        $owned = [];
        foreach ($dates as $date) {
            $owned[$date] = array_map(static fn (DomainLog $log): string => (string) $log->id, DtrCalculator::rowPunches($logs, $schedule, $date));
        }

        return (string) json_encode(['rows' => $rows, 'owned' => $owned]);
    }

    private function changesToday(ScheduleSnapshot $before, ?DomainShift $after): bool
    {
        $was = $before->schedule ?? DomainShift::default();
        $is = $after ?? DomainShift::default();

        // Today's own hours only: a day with no entry of its own resolves to
        // the whole schedule object, whose other weekdays are not today's.
        $hours = static fn (DomainShift $schedule): array => array_diff_key($schedule->forDate($before->today)->toArray(), ['weekday_shifts' => true, 'date_shifts' => true, 'work_days' => true]);

        return $hours($was) !== $hours($is)
            || ShiftResolver::worksOnDate($before->today, $was) !== ShiftResolver::worksOnDate($before->today, $is)
            || $this->place($before->employeeId, $before->today) !== $before->todayPlace;
    }

    private function place(string $employeeId, string $date): string
    {
        $place = $this->roster->locationForDate($employeeId, $date);

        return $place === null ? '' : $place->kind.'|'.($place->siteId ?? '');
    }

    /**
     * The protected dates' attendance as the DTR computes it, with the
     * punches each date owns — the two things a schedule can move.
     *
     * @param  list<DomainLog>  $logs
     * @param  list<DomainLeave>  $leaves
     * @param  list<HolidaySetting>  $holidays
     * @param  list<string>  $dates
     */
    private static function records(array $logs, ?DomainShift $schedule, array $leaves, array $holidays, array $dates): string
    {
        if ($dates === []) {
            return '';
        }
        $rows = DtrCalculator::compute($logs, $schedule, $leaves, $dates[0], $dates[count($dates) - 1], $holidays);
        $owned = [];
        foreach ($dates as $date) {
            $owned[$date] = array_map(static fn (DomainLog $log): string => (string) $log->id, DtrCalculator::rowPunches($logs, $schedule, $date));
        }

        return (string) json_encode(['rows' => $rows, 'owned' => $owned]);
    }

    /** @return list<string> */
    private static function overlapKeys(?DomainShift $schedule, string $from, string $to): array
    {
        return array_map(
            static fn (array $overlap): string => implode('|', [$overlap['date'], $overlap['ends'], $overlap['next'], $overlap['starts']]),
            EffectiveDatePolicy::overlaps($schedule, $from, $to),
        );
    }

    private static function protectedSentence(ScheduleSnapshot $before): string
    {
        $last = $before->protected[count($before->protected) - 1] ?? $before->from;

        return 'This change would alter the attendance already recorded up to '.HrisFormat::date($last)
            .' — for example by moving a morning punch out of the night shift that started the day before. Choose a later date, or a shift that starts after that shift has ended.';
    }
}
