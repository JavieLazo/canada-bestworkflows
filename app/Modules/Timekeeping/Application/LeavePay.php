<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveCredit;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Absence\LeavePaySplit;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Support\HrisFormat;

/**
 * WHAT A LEAVE REQUEST WOULD BE PAID, by how its leave type is filed
 * (LeaveTypeCredits): the days it counts — the employee's scheduled working
 * days or every calendar day — and how far what covers them reaches:
 *
 *  - EARNED CREDITS: the type's available credits in the year the request
 *    draws on (LeaveBalances: its start year moved past every year the
 *    earning job has closed), plus the fallback type's when the leave rule
 *    names one (LeaveRules::filing), plus how far BELOW ZERO the type may
 *    still go (below);
 *  - SET DAYS EACH YEAR: the set days less those already approved with pay
 *    in the request's calendar year;
 *  - SET DAYS EACH TIME: the set days;
 *  - WITHOUT PAY: nothing.
 *
 * The counted dates are paid in date order as far as that reaches, and the
 * rest is WITHOUT PAY (LeavePaySplit). A short balance never refuses a
 * filing: the refusals are LeaveRules::filing's — a type the employee may
 * not use (LeaveEligibilities), and a type that waits, before the employee
 * acquired it.
 *
 * BELOW ZERO IS AN OPTION PER LEAVE TYPE, OFF UNTIL HR SETS IT: the type's
 * below zero limit (LeaveTypeCredits), the same floor a payroll's charge
 * stops at (AttendanceChargePlan). With a limit of 0 a request is paid only
 * as far as credits held reach. With a limit, what the type's own credits
 * and its fallback's do not cover is paid by taking the type below zero,
 * down to minus the limit — less any debt the balance already carries —
 * and only what lies past that is without pay. Real credits are always
 * spent first: ReviewAbsence draws the fallback before the type goes below
 * zero. The fallback type itself never goes below zero for another type's
 * leave. Only a credit entered for the year goes below zero, and never
 * while a cash-out holds days of it (mayGoBelowZero). The debt carries
 * into later years and later earnings fill it (LeaveEarningRule::yearEnd).
 *
 * ONE READING for the filing form's hint, FileAbsence's message, the
 * reviewer's dialog and ReviewAbsence, so they cannot disagree. It reads
 * without scoping or locks: the commands call it under the request and
 * credit locks they hold, and a screen reaches it only through
 * Queries\LeavePayRecords, which checks whose request it is.
 *
 * @phpstan-type LeavePayPlan array{
 *     filing: string, name: string, year: int, day_count: string,
 *     dates: list<string>, needs: float, excluded: list<string>,
 *     key: string, own: float, allowance: float|null, used: float,
 *     fallback: string|null, fallback_name: string|null, fallback_available: float,
 *     available: float|null, below_zero_room: float, paid: list<string>, unpaid: list<string>,
 *     paid_days: float, unpaid_days: float, below_zero_days: float, below_zero_to: float|null,
 *     refusal: string|null
 * }
 */
final class LeavePay
{
    public function __construct(private readonly LeaveBalances $balances) {}

    /** @return LeavePayPlan */
    public function plan(string $employeeId, string $type, ?string $dayType, string $start, string $end): array
    {
        $settings = LeaveTypeCreditRecords::of($type);
        $name = LeaveTypeCreditRecords::rules()[$type]['name'] ?? HrisFormat::requestDescription($type);
        $cost = $this->balances->costFor($settings, $employeeId, $dayType, $start, $end);
        $rules = LeaveRules::filing($employeeId, $type, $start);

        $year = LeaveCreditPolicy::yearOf($start);
        $key = $type;
        $own = 0.0;
        $ownBalance = 0.0;
        $room = 0.0;
        $used = 0.0;
        $fallback = null;
        $fallbackName = null;
        $fallbackAvailable = 0.0;
        $available = match ($settings->filing) {
            LeaveTypeCredits::FILING_UNPAID => 0.0,
            LeaveTypeCredits::FILING_OCCASION => (float) $settings->allowanceDays,
            default => null,
        };
        if ($settings->filing === LeaveTypeCredits::FILING_YEARLY) {
            $used = LeaveBalances::allowanceUsed($employeeId, $year, $type);
            $available = LeaveQuantity::normalize(max(0.0, (float) $settings->allowanceDays - $used));
        } elseif ($settings->filedAgainstCredits()) {
            $year = LeaveBalances::liveYear($employeeId, $start);
            $key = LeaveBalances::creditKey($employeeId, $year, $type);
            $ownBalance = LeaveQuantity::normalize(LeaveBalances::available($employeeId, $year, $key));
            $own = LeaveQuantity::normalize(max(0.0, $ownBalance));
            $available = $own;
            if ($rules['fallback'] !== null) {
                $fallback = $rules['fallback'];
                $fallbackName = $rules['fallback_name'];
                $fallbackAvailable = LeaveQuantity::normalize(max(0.0, LeaveBalances::available($employeeId, $year, $fallback)));
                $available = LeaveQuantity::normalize($own + $fallbackAvailable);
            }
            // What is left of the type's below zero limit once any debt the
            // balance already carries is counted: 0 for a type without one.
            if ($settings->belowZeroLimit > 0.0 && self::mayGoBelowZero($employeeId, $year, $key)) {
                $room = LeaveQuantity::normalize(max(0.0, $settings->belowZeroLimit + min(0.0, $ownBalance)));
            }
        }
        $split = LeavePaySplit::split($cost['dates'], LeaveCreditPolicy::unit($dayType), $available === null ? null : LeaveQuantity::normalize($available + $room));
        // The paid days the credits held do not cover are paid below zero.
        $belowZero = $room > 0.0 ? LeaveQuantity::normalize(max(0.0, $split['paid_days'] - (float) $available)) : 0.0;

        return [
            'filing' => $settings->filing,
            'name' => $name,
            'year' => $year,
            'day_count' => $settings->dayCount,
            'dates' => $cost['dates'],
            'needs' => $cost['days'],
            'excluded' => $cost['excluded'],
            'key' => $key,
            'own' => $own,
            'allowance' => $settings->allowanceDays,
            'used' => $used,
            'fallback' => $fallback,
            'fallback_name' => $fallbackName,
            'fallback_available' => $fallbackAvailable,
            'available' => $available,
            'below_zero_room' => $room,
            'paid' => $split['paid'],
            'unpaid' => $split['unpaid'],
            'paid_days' => $split['paid_days'],
            'unpaid_days' => $split['unpaid_days'],
            'below_zero_days' => $belowZero,
            // Where the type's own balance would stand once approved: the
            // fallback is drawn first, so what is left is this type's debt.
            'below_zero_to' => $belowZero > 0.0 ? LeaveQuantity::normalize(min(0.0, $ownBalance) - $belowZero) : null,
            'refusal' => $rules['refusal'],
        ];
    }

    /**
     * The sentence that tells whoever files what the request will be paid:
     * null when it is paid whole. "Your Vacation Leave credits cover 2 of
     * the 5 days; the other 3 will be without pay if approved."
     *
     * @param  LeavePayPlan  $plan
     */
    public static function notice(array $plan, bool $own = true): ?string
    {
        if ($plan['unpaid_days'] <= 0.0) {
            return null;
        }
        $your = $own ? 'Your' : 'The employee\'s';
        $needs = self::days($plan['needs']);
        $unpaid = self::days($plan['unpaid_days']);
        if ($plan['filing'] === LeaveTypeCredits::FILING_UNPAID) {
            return "{$plan['name']} is unpaid leave: the {$needs} will not be paid.";
        }
        $cover = match ($plan['filing']) {
            LeaveTypeCredits::FILING_YEARLY => "{$your} {$plan['name']} days left this year (".LeaveQuantity::format((float) $plan['available']).' of '.LeaveQuantity::format((float) $plan['allowance']).')',
            LeaveTypeCredits::FILING_OCCASION => "{$plan['name']}'s ".LeaveQuantity::format((float) $plan['allowance']).' days per request',
            default => "{$your} available {$plan['name']} credits (".LeaveQuantity::credits((float) $plan['available']).')'
                .($plan['below_zero_room'] > 0.0 ? ', and a negative balance of up to '.LeaveQuantity::credits($plan['below_zero_room']).',' : ''),
        };
        if ($plan['paid_days'] <= 0.0) {
            $none = $own ? 'You have no' : 'The employee has no';

            return ($plan['filing'] === LeaveTypeCredits::FILING_CREDITS ? "{$none} {$plan['name']} credits available to cover this request" : "{$cover} cover none of it")
                .": the whole request ({$needs}) will be unpaid if approved.";
        }

        return "{$cover} cover ".self::days($plan['paid_days'])." of the {$needs}; the other {$unpaid} will be unpaid if approved.";
    }

    /**
     * Whether leave may take this credit below zero at all, whatever the
     * type's limit: ONLY A CREDIT ENTERED FOR THE YEAR — the year's close
     * carries the rows it finds (PostLeaveEarnings::closeYear), so a debt
     * on a credit never entered would be dropped with the year — and NEVER
     * WHILE A CASH-OUT HOLDS DAYS OF IT: the payroll that pays the cash-out
     * needs those days still in the balance (LeaveCashOutQuery::post), and
     * any day paid below zero would take them.
     */
    private static function mayGoBelowZero(string $employeeId, int $year, string $key): bool
    {
        if ((LeaveCashOuts::spokenFor($employeeId)[$key] ?? 0.0) > 0.0) {
            return false;
        }

        return LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $year)->where('request_type', $key)->exists();
    }

    /**
     * The sentence that says approving would take the type to a NEGATIVE
     * BALANCE, as its limit allows: null when it would not. "2 days would be
     * paid by taking your Vacation Leave to a negative balance of -2.000, if
     * approved." Shown beside `notice`, never in its place.
     *
     * @param  LeavePayPlan  $plan
     */
    public static function belowZero(array $plan, bool $own = true): ?string
    {
        if ($plan['below_zero_days'] <= 0.0 || $plan['below_zero_to'] === null) {
            return null;
        }
        $your = $own ? 'your' : 'the employee\'s';

        return self::days($plan['below_zero_days'])." would be paid by taking {$your} {$plan['name']} to a negative balance of "
            .LeaveQuantity::credits($plan['below_zero_to']).', if approved.';
    }

    /** "1 day", "2.5 days". */
    private static function days(float $days): string
    {
        return LeaveQuantity::format($days).' '.($days == 1.0 ? 'day' : 'days');
    }
}
