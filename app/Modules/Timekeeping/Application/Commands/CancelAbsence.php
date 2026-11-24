<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\LeaveCredit;
use App\Models\LeaveRequestCancellation;
use App\Models\LeaveTravelRequest;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveCharges;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Platform\Notifications\Notifier;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cancels only selected future leave dates and retains the approved request.
 * Management may cancel their own leave. Original approval, pay choice and
 * range remain evidence; dated exclusions give the credits back (the frozen
 * cost shrinks while its year is open; an ADJUSTMENT in the live year once
 * that year has closed — LeaveBalances). Payroll's membership lock is FIRST,
 * then the request row, then the credits lock, so a concurrent finalize and
 * this operation cannot disagree about whether the dates are already posted.
 */
final class CancelAbsence
{
    /** @param array<array-key, mixed> $dates */
    public function handle(Access $actor, string $id, array $dates, string $reason): void
    {
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if ($dates === [] || ! array_is_list($dates)) {
            throw new RuntimeException('Select at least one leave date to cancel.');
        }
        $selected = [];
        foreach ($dates as $date) {
            if (! is_string($date) || ! PayrollPeriod::isPayrollDate($date)) {
                throw new RuntimeException('Choose valid leave dates to cancel.');
            }
            $selected[] = $date;
        }
        if (count(array_unique($selected)) !== count($selected)) {
            throw new RuntimeException('Select each leave date only once.');
        }
        $dates = $selected;
        sort($dates);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new RuntimeException('Enter a cancellation reason of 1 to 1000 characters.');
        }

        DB::transaction(static function () use ($actor, $id, $dates, $reason): void {
            $posted = app(PostedPayrollDates::class);
            $posted->lock();

            $request = LeaveTravelRequest::query()->whereKey($id)->lockForUpdate()->first();
            if ($request === null) {
                throw new RuntimeException('That request no longer exists.');
            }
            if ($request->status !== 'APPROVED' || $request->request_type === AbsencePolicy::OFFICIAL_BUSINESS) {
                throw new RuntimeException('Only approved leave can be cancelled.');
            }

            $today = LocalClock::todayKey();
            $start = $request->start_date->format('Y-m-d');
            $end = $request->end_date->format('Y-m-d');
            $request->load('cancellations:id,leave_travel_request_id,date');
            $cancelled = $request->cancelledDates();
            foreach ($dates as $date) {
                if ($date <= $today) {
                    throw new RuntimeException('Only future leave dates can be cancelled; today and past dates cannot change.');
                }
                if ($date < $start || $date > $end) {
                    throw new RuntimeException('Select dates within the approved leave period.');
                }
                if (in_array($date, $cancelled, true)) {
                    throw new RuntimeException('A selected leave date has already been cancelled. Refresh the request.');
                }
                if ($posted->lockAndCovers($request->employee_id, $date)) {
                    throw new RuntimeException('A selected leave date is already on a posted payslip and cannot be cancelled.');
                }
            }

            $at = CarbonImmutable::now(LocalClock::zone())->utc();
            foreach ($dates as $date) {
                $row = new LeaveRequestCancellation;
                $row->fill(['leave_travel_request_id' => $id, 'date' => $date, 'reason' => $reason]);
                $row->cancelled_by = (int) $actor->userId;
                $row->cancelled_at = $at;
                $row->save();
            }

            // THE CREDITS COME BACK. A frozen cost (credit_dates) gives back
            // the cancelled dates it counted: while the year it draws on is
            // still open, the frozen cost shrinks; once that year is closed
            // — its whole balance already carried forward — the frozen cost
            // stays as the closing balance read it, and the days return as an
            // ADJUSTMENT entry in the live year instead. A leave type with set
            // days holds no credits and no year closes on it: its frozen cost
            // always shrinks. An older row with no frozen cost keeps the
            // calendar-day rule, whose read already leaves cancelled dates
            // out. A cancelled date left WITHOUT PAY gives nothing back: it
            // cost nothing.
            $returned = '';
            if (LeaveBalances::ready() && $request->credit_dates !== null && LeaveCreditPolicy::wouldConsume($request->request_type, $request->is_paid)) {
                $counted = LeaveBalances::usedDates($request);
                $given = array_values(array_intersect($counted, $dates));
                $kept = array_values(array_diff($counted, $dates));
                $days = LeaveCreditPolicy::selectedDays($request->day_type, $given);
                $creditsYear = LeaveBalances::creditsYear($request);
                $liveYear = LeaveCharges::liveYear($request->employee_id, $creditsYear);
                if ($liveYear === $creditsYear || ! LeaveTypeCreditRecords::of($request->request_type)->filedAgainstCredits()) {
                    $request->credit_dates = $kept;
                    $request->credit_days = LeaveCreditPolicy::selectedDays($request->day_type, $kept);
                } elseif ($days > 0) {
                    LeaveCreditWriter::lock();
                    $token = LeaveCreditPolicy::resolveKey($request->request_type, array_values(LeaveCredit::query()
                        ->where('employee_id', $request->employee_id)->where('year', $liveYear)->pluck('request_type')->map('strval')->all())) ?? $request->request_type;
                    LeaveCreditWriter::move($actor, $request->employee_id, $liveYear, $token, 'ADJUSTMENT', $days,
                        "Returned from request {$request->code}: ".implode(', ', $given)." cancelled after {$creditsYear} closed");
                    $returned = '; '.LeaveQuantity::format($days).' returned to '.$liveYear;
                }
            }

            $originalDays = (int) $request->start_date->diffInDays($request->end_date) + 1;
            if (count($cancelled) + count($dates) === $originalDays) {
                $request->status = 'CANCELLED';
            }
            // Partial cancellation must invalidate request and payroll reads too.
            $request->updated_at = $at;
            $request->save();

            $dateList = implode(', ', $dates);
            AuditService::record($actor, AuditEvent::REQUEST_CANCELLED, 'leave_travel_requests', $id,
                "Request {$request->code} cancelled for {$dateList} · {$reason}{$returned}");

            $employeeId = $request->employee_id;
            $code = $request->code;
            DB::afterCommit(static function () use ($employeeId, $code, $dateList, $reason): void {
                Notifier::employee($employeeId, 'LEAVE_CANCELLED', "Leave {$code} cancelled for selected dates",
                    "{$dateList} · {$reason}", '/portal');
            });
        });
    }
}
