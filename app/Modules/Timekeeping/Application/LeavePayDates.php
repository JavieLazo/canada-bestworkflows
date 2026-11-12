<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Models\LeaveTravelRequest;
use Illuminate\Support\Facades\Schema;

/**
 * A LEAVE APPROVED PARTLY WITH PAY, as the Daily Time Record reads it. The
 * approval pays the earliest counted dates and leaves the rest without pay
 * (LeavePaySplit), naming the latter in `unpaid_dates`; this turns the
 * request into what the DTR engine already understands — a paid leave up to
 * the day before the first date without pay, and a leave without pay from
 * that date on — so the engine, payroll and their evidence need nothing
 * new. A request with no date without pay is handed over as it is.
 *
 * Every reader that hands requests to the engine (AttendanceInputQuery for
 * the DTR, payroll and the earning job; ScheduleGuard for schedule changes)
 * goes through `domain()`, so they cannot read a request differently.
 */
final class LeavePayDates
{
    private function __construct() {}

    /** Whether the request rows carry `unpaid_dates` yet (`2026_11_07_0002`). */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasColumn('leave_travel_requests', 'unpaid_dates'));
    }

    /**
     * The dates an approval left without pay; empty for a request paid
     * whole, without pay whole, official business or not yet approved.
     *
     * @return list<string>
     */
    public static function unpaid(LeaveTravelRequest $request): array
    {
        if (! self::ready() || $request->is_paid !== true) {
            return [];
        }
        $dates = $request->getAttribute('unpaid_dates');
        if (! is_array($dates)) {
            return [];
        }
        $out = array_values(array_filter(array_map(static fn (mixed $date): string => is_string($date) ? $date : '', $dates), static fn (string $date): bool => $date !== ''));
        sort($out, SORT_STRING);

        return $out;
    }

    /** Whether the approval left some of the request's days without pay. */
    public static function partly(LeaveTravelRequest $request): bool
    {
        return self::unpaid($request) !== [];
    }

    /**
     * The request as the DTR engine takes it: one leave, or — when some
     * dates were left without pay — a paid leave ending the day before the
     * first of them and a leave without pay from it to the end. Both keep
     * the request's id, type, day type, half and cancelled dates.
     *
     * @return list<DomainLeave>
     */
    public static function domain(LeaveTravelRequest $request, ?string $half): array
    {
        $start = $request->start_date->format('Y-m-d');
        $end = $request->end_date->format('Y-m-d');
        $row = [
            'id' => $request->id, 'employee_id' => $request->employee_id,
            'request_type' => $request->request_type, 'day_type' => $request->day_type,
            'start_date' => $start, 'end_date' => $end,
            'status' => $request->status, 'is_paid' => $request->is_paid,
            'cancelled_dates' => $request->cancelledDates(),
            'half' => $half,
        ];
        $unpaid = self::unpaid($request);
        $from = $unpaid[0] ?? null;
        if ($from === null || $from < $start || $from > $end) {
            return [DomainLeave::fromArray($row)];
        }
        $out = [];
        if ($from > $start) {
            $out[] = DomainLeave::fromArray([...$row, 'end_date' => CalendarDays::previous($from)]);
        }
        $out[] = DomainLeave::fromArray([...$row, 'start_date' => $from, 'is_paid' => false]);

        return $out;
    }
}
