<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveAttendanceCharge;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * THE ONE BALANCE RULE. Every reader of a leave credit — the Leave Credits
 * tab, the ledger, My Portal, filing, approval, the earning job, the
 * payroll charge plan and the cash-out commands — asks this class, so they
 * cannot disagree:
 *
 *  - A type's BALANCE for a year is its allocation (`leave_credits.credits`,
 *    which the ledger entries move) less the credits the year's approved,
 *    with-pay leave USED.
 *  - A request draws on the year `creditsYear()` names: the year frozen on
 *    the row when it was approved (`credits_year`), else its start year. A
 *    request approved after its start year closed was frozen on the live
 *    year, so a closed year never moves again (PostLeaveEarnings::closeYear
 *    carried its whole balance forward).
 *  - A request USES the working days frozen on it (`credit_days`), else — a
 *    row approved before the columns existed — every calendar day of its
 *    range less its cancelled dates (LeaveCreditPolicy::coveredDays).
 *  - What is AVAILABLE to file, approve or cash out is the balance less the
 *    days already spoken for by a pending or approved-but-unpaid cash-out
 *    (LeaveCashOuts::spokenFor).
 *
 * `cost()` is what a request WOULD cost: the scheduled working days of its
 * range by the employee's dated schedule and the holiday list, excluding
 * dates a posted payroll already charged to credits as an absence. The
 * commands call it under the credits lock; the screens call it for the hint.
 */
final class LeaveBalances
{
    public function __construct(
        private readonly ShiftRoster $roster,
        private readonly HolidayCalendar $holidays,
    ) {}

    /** Whether the request rows carry the frozen cost yet (`2026_10_31_0009`). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_travel_requests', 'credit_days'));
    }

    /** The year a request's credits belong to. */
    public static function creditsYear(LeaveTravelRequest $request): int
    {
        $frozen = self::ready() ? $request->credits_year : null;

        return $frozen ?? LeaveCreditPolicy::yearOf($request->start_date->format('Y-m-d'));
    }

    /** The credits a request uses, frozen or counted the calendar-day way. */
    public static function usedDays(LeaveTravelRequest $request): float
    {
        $frozen = self::ready() ? $request->credit_days : null;
        if ($frozen !== null) {
            return LeaveQuantity::normalize($frozen);
        }

        return LeaveQuantity::normalize(LeaveCreditPolicy::coveredDays(
            $request->day_type,
            $request->start_date->format('Y-m-d'),
            $request->end_date->format('Y-m-d'),
            $request->cancelledDates(),
        ));
    }

    /**
     * The dates a request's frozen cost counts, else its active calendar
     * dates for an older row.
     *
     * @return list<string>
     */
    public static function usedDates(LeaveTravelRequest $request): array
    {
        $frozen = self::ready() ? $request->credit_dates : null;
        if ($frozen !== null) {
            return array_map('strval', $frozen);
        }

        return LeaveCreditPolicy::activeDays($request->start_date->format('Y-m-d'), $request->end_date->format('Y-m-d'), $request->cancelledDates());
    }

    /**
     * Narrows a request query to the requests that draw on `$year`: frozen on
     * it, or starting in it with nothing frozen.
     *
     * @param  Builder<LeaveTravelRequest>  $query
     * @return Builder<LeaveTravelRequest>
     */
    public static function drawingOn(Builder $query, int $year): Builder
    {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        if (! self::ready()) {
            return $query->whereBetween('start_date', [$from, $to]);
        }

        return $query->where(static function (Builder $q) use ($year, $from, $to): void {
            $q->where('credits_year', $year)
                ->orWhere(static fn (Builder $w) => $w->whereNull('credits_year')->whereBetween('start_date', [$from, $to]));
        });
    }

    /**
     * The credits used per key out of a loaded request collection, for one
     * year: APPROVED with-pay leave, each request charged to the ONE key it
     * resolves to.
     *
     * @param  Collection<int, LeaveTravelRequest>  $requests
     * @param  list<string>  $keys
     * @return array<string, float> key => days used
     */
    public static function usage(Collection $requests, array $keys, int $year): array
    {
        $used = array_fill_keys($keys, 0.0);
        foreach ($requests as $request) {
            if (self::creditsYear($request) !== $year
                || ! LeaveCreditPolicy::consumes($request->status, $request->request_type, $request->is_paid)) {
                continue;
            }
            $key = LeaveCreditPolicy::resolveKey($request->request_type, $keys);
            if ($key === null) {
                continue;
            }
            $used[$key] = LeaveQuantity::normalize($used[$key] + self::usedDays($request));
        }

        return $used;
    }

    /** A type's balance for a year: the allocation less the leave used. */
    public static function balance(string $employeeId, int $year, string $token): float
    {
        $credited = (float) (LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $year)->where('request_type', $token)->value('credits') ?? 0.0);
        $requests = self::drawingOn(LeaveTravelRequest::query()->with('cancellations:id,leave_travel_request_id,date'), $year)
            ->where('employee_id', $employeeId)->where('status', 'APPROVED')
            ->get(self::columns());

        return LeaveQuantity::normalize($credited - (self::usage($requests, [$token], $year)[$token] ?? 0.0));
    }

    /**
     * What may still be filed, approved or cashed out of a type in a year:
     * the balance less the days spoken for by cash-outs not yet paid.
     */
    public static function available(string $employeeId, int $year, string $token): float
    {
        return LeaveQuantity::normalize(self::balance($employeeId, $year, $token) - (LeaveCashOuts::spokenFor($employeeId)[$token] ?? 0.0));
    }

    /**
     * The year an employee's credits of a leave type live in today: the
     * year of `$date` moved past every year the earning job has closed.
     */
    public static function liveYear(string $employeeId, string $date): int
    {
        return LeaveCharges::liveYear($employeeId, LeaveCreditPolicy::yearOf($date));
    }

    /**
     * What a request over `$start`..`$end` costs: the dates it is charged
     * for and their credit units. Working days of the employee's schedule
     * (the dated roster over the range), holidays out, and out too the dates
     * a posted payroll already charged to credits as an absence — those
     * credits were spent on the day already (`$excluded` on return names
     * them, so the approval can say so).
     *
     * @param  list<string>  $cancelled
     * @return array{days: float, dates: list<string>, excluded: list<string>}
     */
    public function cost(string $employeeId, ?string $dayType, string $start, string $end, array $cancelled = []): array
    {
        if ($start > $end) {
            return ['days' => 0.0, 'dates' => [], 'excluded' => []];
        }
        $schedule = $this->roster->scheduleForEmployees([$employeeId], $start, $end)[$employeeId] ?? null;
        $charged = self::chargedAbsenceDates($employeeId, $start, $end);
        $dates = LeaveCreditPolicy::workingDates($schedule, $this->holidays->all(), $start, $end, [...$cancelled, ...$charged]);
        $excluded = array_values(array_intersect(
            LeaveCreditPolicy::workingDates($schedule, $this->holidays->all(), $start, $end, $cancelled),
            $charged,
        ));

        return ['days' => LeaveCreditPolicy::selectedDays($dayType, $dates), 'dates' => $dates, 'excluded' => $excluded];
    }

    /**
     * The dates in a range a posted payroll charged to the employee's credits
     * as an absence and that credits covered (at least part of the day came
     * out of the allocation). A charge row that covered nothing left the day
     * to pay, and leave approved over it is the paid-days notice's business.
     *
     * @return list<string>
     */
    public static function chargedAbsenceDates(string $employeeId, string $start, string $end): array
    {
        if (! LeaveCharges::ready()) {
            return [];
        }
        $out = [];
        $rows = LeaveAttendanceCharge::query()->where('employee_id', $employeeId)
            ->where('kind', 'ABSENCE')->where('date', '>=', $start)->where('date', '<=', $end)
            ->orderBy('date')->get(['date', 'days', 'uncovered_days']);
        foreach ($rows as $row) {
            if ((float) $row->uncovered_days < (float) $row->days) {
                $out[] = $row->date->format('Y-m-d');
            }
        }

        return $out;
    }

    /**
     * The columns a usage read needs; the frozen ones only once they exist.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        $columns = ['id', 'employee_id', 'request_type', 'day_type', 'start_date', 'end_date', 'status', 'is_paid'];

        return self::ready() ? [...$columns, 'credits_year', 'credit_days', 'credit_dates'] : $columns;
    }
}
