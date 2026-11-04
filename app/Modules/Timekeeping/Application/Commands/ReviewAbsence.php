<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveTravelRequest;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The absence review boundary, with stale-decision protection. An all-data
 * LEAVES holder may review their own request: one administrator must be
 * able to run the whole flow alone.
 *
 * AN APPROVAL ONTO DAYS ALREADY PAID still lands — a sick leave is often
 * filed after the payroll that paid the day as an absence, and refusing it
 * would leave the credit unspent and the day unpaid for good. But the
 * posted payslip never changes, so the approval says which dates it reaches
 * that a posted payslip covers: in the audit sentence, in what it returns,
 * and to everyone holding Payroll, who settle the difference on the next
 * payroll. The dates are read under payroll's membership lock, taken FIRST
 * in the transaction (PostedPayrollDates), so a payroll finalizing at the
 * same moment is either seen or sees the approval.
 *
 * AN APPROVAL WITH PAY FREEZES ITS COST ON THE REQUEST (LeaveBalances): the
 * year the credits come from — the start year moved past every year the
 * earning job has closed, so a December request approved in January draws
 * on the new year, where the old one's balance already went — and the
 * scheduled working days it covers, less any date a posted payroll already
 * charged to credits as an absence: those credits were spent on the day, so
 * the leave is approved but that date costs nothing more.
 */
final class ReviewAbsence
{
    /** How many paid dates a sentence names before it counts the rest. */
    private const int DATES_NAMED = 3;

    public function __construct(private readonly LeaveBalances $balances) {}

    public function handle(Access $actor, string $id, string $status, ?bool $isPaid = null): string
    {
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! in_array($status, ['APPROVED', 'REJECTED'], true)) {
            throw new RuntimeException('Choose a valid review decision.');
        }
        $existing = LeaveTravelRequest::query()->whereKey($id)->first();
        if ($existing === null) {
            throw new RuntimeException('That request no longer exists.');
        }
        if ($existing->status !== 'PENDING') {
            throw new RuntimeException('That request has already been reviewed.');
        }
        $isOb = $existing->request_type === AbsencePolicy::OFFICIAL_BUSINESS;
        if ($status === 'APPROVED' && ! $isOb && $isPaid === null) {
            throw new RuntimeException('Choose whether approved leave is with or without pay.');
        }
        $normalizedPaid = ($status === 'REJECTED' || $isOb) ? null : $isPaid;
        $consumes = $status === 'APPROVED' && LeaveCreditPolicy::wouldConsume($existing->request_type, $normalizedPaid);
        $start = $existing->start_date->format('Y-m-d');
        $end = $existing->end_date->format('Y-m-d');

        [$paid, $charged] = DB::transaction(function () use ($actor, $id, $status, $normalizedPaid, $existing, $consumes, $start, $end): array {
            // FIRST, for an approval: payroll's membership lock, then which of
            // its dates a posted payslip already covers. A rejection pays
            // nothing either way and takes no lock.
            $paid = $status === 'APPROVED'
                ? app(PostedPayrollDates::class)->lockAndPaidDates($existing->employee_id, $start, $end)
                : [];
            $patch = [
                'status' => $status,
                'is_paid' => $normalizedPaid,
                'reviewed_by' => (int) $actor->userId,
                'reviewed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
            ];
            $charged = [];
            if ($consumes && LeaveBalances::ready()) {
                // The request row, then the credits lock (after payroll's,
                // the order every credit writer keeps): what this approval
                // costs is frozen here — the year it draws on and the
                // scheduled working days it covers, less the dates a posted
                // payroll already charged to credits as an absence, which
                // are not charged a second time.
                LeaveTravelRequest::query()->whereKey($id)->lockForUpdate()->first();
                LeaveCreditWriter::lock();
                $cost = $this->balances->cost($existing->employee_id, $existing->day_type, $start, $end);
                $charged = $cost['excluded'];
                $patch['credits_year'] = LeaveBalances::liveYear($existing->employee_id, $start);
                $patch['credit_days'] = $cost['days'];
                $patch['credit_dates'] = json_encode($cost['dates'], JSON_THROW_ON_ERROR);
            }
            $won = LeaveTravelRequest::query()->whereKey($id)->where('status', 'PENDING')->update($patch);
            if ($won === 0) {
                throw new RuntimeException('That request has already been reviewed.');
            }
            AuditService::record($actor, $status === 'APPROVED' ? AuditEvent::REQUEST_APPROVED : AuditEvent::REQUEST_REJECTED,
                'leave_travel_requests', $id, 'Request '.$existing->code.' '.strtolower($status)
                .($paid === [] ? '' : '; already on a posted payslip: '.self::dates($paid))
                .($charged === [] ? '' : '; already charged to leave credits by payroll, not charged again: '.self::dates($charged)));

            return [$paid, $charged];
        });

        DB::afterCommit(static function () use ($existing, $status, $isOb, $normalizedPaid, $paid, $actor): void {
            Notifier::employee($existing->employee_id, 'LEAVE_REVIEWED', 'Request '.$existing->code.' '.strtolower($status),
                $status !== 'APPROVED' || $isOb ? null : ($normalizedPaid ? 'Approved with pay' : 'Approved without pay'), '/portal');
            if ($paid !== []) {
                $employee = app(OperationalDirectory::class)->find($actor, $existing->employee_id);
                $who = $employee !== null ? EmployeeName::display($employee) : 'An employee';
                // It goes to every PAYROLL holder, who may not hold LEAVES:
                // it opens the payroll list, where the difference is settled.
                Notifier::moduleHolders(ModuleCode::PAYROLL, $actor->userId, 'LEAVE_APPROVED_ON_PAID_DAYS',
                    "Request {$existing->code} approved for days already paid",
                    "{$who}: ".self::dates($paid).' already on a posted payslip. Settle any difference on the next payroll.',
                    '/payroll');
            }
        });

        $outcome = $status === 'REJECTED' ? 'rejected'
            : ($isOb ? 'approved as official business' : ($normalizedPaid ? 'approved with pay' : 'approved without pay'));
        if ($paid !== []) {
            $outcome .= '. '.self::dates($paid).(count($paid) === 1 ? ' is' : ' are').' already on a posted payslip, so Payroll has been notified to settle it';
        }
        if ($charged !== []) {
            $outcome .= '. '.self::dates($charged).(count($charged) === 1 ? ' was' : ' were').' already charged to leave credits by payroll and '.(count($charged) === 1 ? 'is' : 'are').' not charged again';
        }

        return $outcome;
    }

    /**
     * "Sep 08, 2026, Sep 09, 2026 and 4 more" — the first few dates by name.
     *
     * @param  list<string>  $dates
     */
    private static function dates(array $dates): string
    {
        $named = array_map(HrisFormat::date(...), array_slice($dates, 0, self::DATES_NAMED));
        $rest = count($dates) - count($named);

        return implode(', ', $named).($rest > 0 ? " and {$rest} more" : '');
    }
}
