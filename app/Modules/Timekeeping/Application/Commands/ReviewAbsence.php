<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveTravelRequest;
use App\Modules\Timekeeping\Application\LeaveApprovalNote;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Application\LeaveDisapproval;
use App\Modules\Timekeeping\Application\LeaveEligibilities;
use App\Modules\Timekeeping\Application\LeaveFormFreeze;
use App\Modules\Timekeeping\Application\LeavePay;
use App\Modules\Timekeeping\Application\LeavePayDates;
use App\Modules\Timekeeping\Application\LeaveRules;
use App\Modules\Timekeeping\Application\LeaveScheduleHold;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
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
 * AN APPROVAL WITH PAY PAYS AS FAR AS THE LEAVE IS COVERED (LeavePay): by
 * the employee's available credits — the type's own, then its fallback's,
 * then as far below zero as the type's limit allows (0 unless HR set one) —
 * or by the leave type's set days, the counted dates are paid in date order
 * and the rest is WITHOUT PAY (`unpaid_dates`; the DTR and payroll read the
 * request as a paid part and a part without pay, LeavePayDates). A part
 * paid below zero is named in the decision, the audit line and the
 * employee's notice. Nothing covering it at all approves the whole request
 * without pay. A type filed without pay is always approved without pay,
 * and the reviewer may still approve any leave without pay whole
 * (`$isPaid` false).
 *
 * AN APPROVAL WITH PAY FREEZES ITS COST ON THE REQUEST (LeaveBalances): the
 * year the credits come from — the start year moved past every year the
 * earning job has closed, so a December request approved in January draws
 * on the new year, where the old one's balance already went; a type with
 * set days draws on its calendar year — and the paid dates, less any date
 * a posted payroll already charged to credits as an absence: those credits
 * were spent on the day, so the leave is approved but that date costs
 * nothing more.
 *
 * A DISAPPROVAL SAYS WHY (LeaveDisapproval): the reason is required, kept
 * on the request and sent to the employee, who reads it in My Portal. The
 * stored status is `REJECTED`; every sentence this writes says
 * "disapproved", as the screens do.
 *
 * AN APPROVAL NEEDS A SHIFT ON EVERY DATE OF THE REQUEST (LeaveScheduleHold),
 * with pay or without and official business alike: a date with no shift is
 * read against the engine's default week, which is nobody's schedule. The
 * dates are read after payroll's lock, the lock every schedule change takes
 * first. A disapproval needs none.
 *
 * AN APPROVAL FREEZES THE APPLICATION FOR LEAVE (LeaveFormFreeze), in this
 * same transaction: from then on the form states what it stated at this
 * moment, whatever later changes.
 *
 * @phpstan-import-type LeavePayPlan from LeavePay
 */
final class ReviewAbsence
{
    /** How many paid dates a sentence names before it counts the rest. */
    private const int DATES_NAMED = 3;

    public function __construct(private readonly LeavePay $pay) {}

    /**
     * @param  string|null  $reason  why a disapproval (`REJECTED`) was made — required for one, ignored on an approval
     * @param  string|null  $approvalNote  the application's "others (specify)": optional on approving a leave, ignored otherwise; recorded only
     */
    public function handle(Access $actor, string $id, string $status, ?bool $isPaid = null, ?string $reason = null, ?string $approvalNote = null): string
    {
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if (! in_array($status, ['APPROVED', 'REJECTED'], true)) {
            throw new RuntimeException('Choose a valid review decision.');
        }
        $reason = $status === 'REJECTED' ? trim((string) $reason) : '';
        if ($status === 'REJECTED' && ($refusal = LeaveDisapproval::error($reason)) !== null) {
            throw new RuntimeException($refusal);
        }
        $approvalNote = $status === 'APPROVED' ? trim((string) $approvalNote) : '';
        if (($refusal = LeaveApprovalNote::error($approvalNote)) !== null) {
            throw new RuntimeException($refusal);
        }
        $word = $status === 'REJECTED' ? 'disapproved' : 'approved';
        $existing = LeaveTravelRequest::query()->whereKey($id)->first();
        if ($existing === null) {
            throw new RuntimeException('That request no longer exists.');
        }
        if ($existing->status !== 'PENDING') {
            throw new RuntimeException('That request has already been reviewed.');
        }
        $isOb = $existing->request_type === AbsencePolicy::OFFICIAL_BUSINESS;
        if ($status === 'APPROVED' && ! $isOb && $isPaid === null) {
            throw new RuntimeException('Choose whether approved leave is paid or unpaid.');
        }
        // A leave type filed without pay is approved without pay, whatever was asked.
        $unpaidType = ! $isOb && LeaveTypeCreditRecords::of($existing->request_type)->filing === LeaveTypeCredits::FILING_UNPAID;
        $normalizedPaid = ($status === 'REJECTED' || $isOb) ? null : ($unpaidType ? false : $isPaid);
        $consumes = $status === 'APPROVED' && LeaveCreditPolicy::wouldConsume($existing->request_type, $normalizedPaid);
        $start = $existing->start_date->format('Y-m-d');
        $end = $existing->end_date->format('Y-m-d');
        // A leave type the employee may not use (LeaveEligibilities) — their
        // status or sex changed since they filed, or the type's settings did —
        // is never approved with pay; it may still be approved as unpaid
        // leave or disapproved.
        if ($consumes && ($refusal = LeaveEligibilities::refusal($existing->employee_id, $existing->request_type, $start)) !== null) {
            throw new RuntimeException($refusal.' Approve it as unpaid leave or disapprove it.');
        }

        [$paid, $charged, $drawn, $pay] = DB::transaction(function () use ($actor, $id, $status, $normalizedPaid, $existing, $consumes, $start, $end, $reason, $word, $approvalNote, $isOb): array {
            // FIRST, for an approval: payroll's membership lock, then which of
            // its dates a posted payslip already covers. A rejection pays
            // nothing either way and takes no lock.
            $paid = $status === 'APPROVED'
                ? app(PostedPayrollDates::class)->lockAndPaidDates($existing->employee_id, $start, $end)
                : [];
            // Under that lock — which every schedule change takes first too
            // (DatedChange) — no date of an approved request may be without
            // a shift (LeaveScheduleHold).
            if ($status === 'APPROVED' && ($noShift = LeaveScheduleHold::dates($existing->employee_id, $start, $end)) !== []) {
                $employee = app(OperationalDirectory::class)->find($actor, $existing->employee_id);

                throw new RuntimeException(LeaveScheduleHold::sentence($employee !== null ? EmployeeName::display($employee) : 'The employee', $noShift));
            }
            $patch = [
                'status' => $status,
                'is_paid' => $normalizedPaid,
                'reviewed_by' => (int) $actor->userId,
                'reviewed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
            ];
            if ($status === 'REJECTED' && LeaveDisapproval::ready()) {
                $patch['disapproval_reason'] = $reason;
            }
            if ($status === 'APPROVED' && ! $isOb && LeaveApprovalNote::ready()) {
                $patch['approval_note'] = $approvalNote === '' ? null : $approvalNote;
            }
            $charged = [];
            $drawn = null;
            $pay = null;
            if ($consumes && LeaveBalances::ready()) {
                // The request row, then the credits lock (after payroll's,
                // the order every credit writer keeps): what this approval
                // pays and costs is worked out here, against the credits as
                // they stand under the lock (LeavePay) — the year it draws
                // on, the dates paid in date order as far as the credits or
                // the set days reach, the rest without pay — less the dates
                // a posted payroll already charged to credits as an absence,
                // which are not charged a second time.
                LeaveTravelRequest::query()->whereKey($id)->lockForUpdate()->first();
                LeaveCreditWriter::lock();
                $plan = $this->pay->plan($existing->employee_id, $existing->request_type, $existing->day_type, $start, $end);
                $charged = $plan['excluded'];
                if (! LeavePayDates::ready()) {
                    // The window before the cron migrates 2026_11_07_0002:
                    // nowhere to name the days without pay, so the request
                    // is paid whole, as every approval was before.
                    $plan = [...$plan, 'paid' => $plan['dates'], 'unpaid' => [], 'paid_days' => $plan['needs'], 'unpaid_days' => 0.0, 'below_zero_days' => 0.0, 'below_zero_to' => null];
                }
                $pay = $plan;
                if ($plan['paid_days'] <= 0.0 && $plan['unpaid'] !== []) {
                    // Nothing covers it: the whole request is without pay.
                    $patch['is_paid'] = false;
                } else {
                    $patch['credits_year'] = $plan['year'];
                    $patch['credit_days'] = $plan['paid_days'];
                    $patch['credit_dates'] = json_encode($plan['paid'], JSON_THROW_ON_ERROR);
                    if (LeavePayDates::ready()) {
                        $patch['unpaid_dates'] = $plan['unpaid'] === [] ? null : json_encode($plan['unpaid'], JSON_THROW_ON_ERROR);
                    }
                    if ($plan['filing'] === LeaveTypeCredits::FILING_CREDITS) {
                        $drawn = $this->drawOnFallback($actor, $existing, $plan['fallback'], $plan['year'], $plan['paid_days']);
                    }
                }
            }
            $won = LeaveTravelRequest::query()->whereKey($id)->where('status', 'PENDING')->update($patch);
            if ($won === 0) {
                throw new RuntimeException('That request has already been reviewed.');
            }
            if ($status === 'APPROVED') {
                app(LeaveFormFreeze::class)->capture($actor, $id);
            }
            AuditService::record($actor, $status === 'APPROVED' ? AuditEvent::REQUEST_APPROVED : AuditEvent::REQUEST_REJECTED,
                'leave_travel_requests', $id, 'Request '.$existing->code.' '.$word
                .($paid === [] ? '' : '; already on a posted payslip: '.self::dates($paid))
                .($charged === [] ? '' : '; already charged to leave credits by payroll, not charged again: '.self::dates($charged))
                .($drawn === null ? '' : '; '.LeaveQuantity::format($drawn['days']).' drawn from '.$drawn['name'])
                .($pay === null || $pay['unpaid'] === [] ? '' : '; '.self::short($pay, audit: true).': '.self::dates($pay['unpaid']).' without pay')
                .($pay === null || self::belowZero($pay, audit: true) === null ? '' : '; '.self::belowZero($pay, audit: true)));

            return [$paid, $charged, $drawn, $pay];
        });

        // What the employee is told and the reviewer reads: why it was
        // disapproved; paid, unpaid, or which part is which when the
        // balance ran short, and how far into a negative balance a paid
        // part took it.
        $belowZero = $pay === null ? null : self::belowZero($pay);
        $decision = match (true) {
            $status === 'REJECTED' => 'Disapproved due to: '.$reason,
            $isOb => null,
            $pay !== null && $pay['unpaid'] !== [] && $pay['paid_days'] > 0.0 => 'Approved: '.self::days($pay['paid_days']).' paid, '.self::days($pay['unpaid_days']).' unpaid ('.self::short($pay).')'
                .($belowZero === null ? '' : '; '.$belowZero),
            $pay !== null && $pay['unpaid'] !== [] => 'Approved as unpaid leave ('.self::short($pay).')',
            $normalizedPaid === true => 'Approved as paid leave'.($belowZero === null ? '' : '; '.$belowZero),
            default => 'Approved as unpaid leave',
        };
        DB::afterCommit(static function () use ($existing, $word, $decision, $paid, $actor): void {
            Notifier::employee($existing->employee_id, 'LEAVE_REVIEWED', 'Request '.$existing->code.' '.$word,
                $decision, '/portal');
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

        $outcome = $status === 'REJECTED' ? 'disapproved'
            : ($isOb ? 'approved as official business' : lcfirst((string) $decision));
        if ($paid !== []) {
            $outcome .= '. '.self::dates($paid).(count($paid) === 1 ? ' is' : ' are').' already on a posted payslip, so Payroll has been notified to settle it';
        }
        if ($charged !== []) {
            $outcome .= '. '.self::dates($charged).(count($charged) === 1 ? ' was' : ' were').' already deducted from leave credits by payroll and '.(count($charged) === 1 ? 'is' : 'are').' not deducted again';
        }

        if ($drawn !== null) {
            $outcome .= '. '.LeaveQuantity::credits($drawn['days']).' '.($drawn['days'] == 1.0 ? 'credit was' : 'credits were').' drawn from '.$drawn['name'];
        }

        return $outcome;
    }

    /**
     * Under the leave rules a type may FALL BACK on another when its own
     * credits run out (LeaveTypeRule::fallback — sick leave on vacation
     * leave): what this approval costs beyond the type's own available
     * balance is moved from the fallback type, as far as that one reaches,
     * as a pair of ADJUSTMENT entries in the year the request draws on. The
     * request then costs its own type as any other does. Called under the
     * leave-credits lock, before the request turns APPROVED, so the balance
     * read does not yet count it.
     *
     * THE FALLBACK IS THE ONE THE PLAN PAID BY (`$fallback`, from
     * LeaveRules::filing): a fallback type not yet open on the request's
     * start is not one, so nothing is drawn from it — what the plan paid
     * beyond the type's own credits is then the type's below zero part.
     *
     * @return array{days: float, name: string}|null what was moved and from which type
     */
    private function drawOnFallback(Access $actor, LeaveTravelRequest $request, ?string $fallback, int $year, float $days): ?array
    {
        if ($fallback === null) {
            return null;
        }
        $token = (string) $request->request_type;
        $names = LeaveRules::book()->names();
        if (! isset($names[$token], $names[$fallback])) {
            return null;
        }
        $short = $days - max(0.0, LeaveBalances::available($request->employee_id, $year, $token));
        $move = LeaveQuantity::normalize(min($short, max(0.0, LeaveBalances::available($request->employee_id, $year, $fallback))));
        if ($move <= 0.0) {
            return null;
        }
        LeaveCreditWriter::move($actor, $request->employee_id, $year, $fallback, 'ADJUSTMENT', -$move, "Moved to {$names[$token]} for request {$request->code}");
        LeaveCreditWriter::move($actor, $request->employee_id, $year, $token, 'ADJUSTMENT', $move, "Drawn from {$names[$fallback]} for request {$request->code}");

        return ['days' => $move, 'name' => $names[$fallback]];
    }

    /**
     * Why part of an approval is unpaid: "not enough Vacation Leave
     * credits", "Special Privilege Leave's annual allowance for 2026 used
     * up". The audit trail (`$audit`) has its own wording, so every one of
     * its sentences reads alike.
     *
     * @param  LeavePayPlan  $pay
     */
    private static function short(array $pay, bool $audit = false): string
    {
        return match (true) {
            $pay['filing'] === LeaveTypeCredits::FILING_YEARLY => $audit ? "{$pay['name']} days for {$pay['year']} used up" : "{$pay['name']}'s annual allowance for {$pay['year']} used up",
            $pay['filing'] === LeaveTypeCredits::FILING_OCCASION => $audit ? "beyond {$pay['name']}'s set days" : "beyond {$pay['name']}'s allowance per request",
            $pay['below_zero_room'] > 0.0 => $audit ? "beyond how far {$pay['name']} may go below zero" : "beyond {$pay['name']}'s maximum negative balance",
            default => "not enough {$pay['name']} credits",
        };
    }

    /**
     * "Vacation Leave goes to a negative balance of -2.000" when the paid
     * part took the type below zero (its limit allows it); null otherwise.
     * The audit trail (`$audit`) keeps "credits go below zero, to -2.000".
     *
     * @param  LeavePayPlan  $pay
     */
    private static function belowZero(array $pay, bool $audit = false): ?string
    {
        if ($pay['below_zero_days'] <= 0.0 || $pay['below_zero_to'] === null) {
            return null;
        }

        return $audit
            ? "{$pay['name']} credits go below zero, to ".LeaveQuantity::credits($pay['below_zero_to'])
            : "{$pay['name']} goes to a negative balance of ".LeaveQuantity::credits($pay['below_zero_to']);
    }

    /** "1 day", "2.5 days". */
    private static function days(float $days): string
    {
        return LeaveQuantity::format($days).' '.($days == 1.0 ? 'day' : 'days');
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
