<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveEligibilities;
use App\Modules\Timekeeping\Application\LeavePay;
use App\Modules\Timekeeping\Contracts\Views\LeaveBalanceView;
use App\Modules\Timekeeping\Contracts\Views\LeaveTravelRequestView;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
use App\Support\Access;
use Illuminate\Support\Collection;

/**
 * What a leave request would be paid (LeavePay), for a screen: the filing
 * form's hint and the reviewer's dialog. The actor's own employee, or any
 * employee for a holder who reads all leave data — the reading
 * LeaveRuleRecords::filing allows; anyone else, official business and an
 * unreadable range are told nothing. And, for My Portal, the session's own
 * leave types with set days and the standing the leave application certifies.
 *
 * @phpstan-import-type LeavePayPlan from LeavePay
 */
final class LeavePayRecords
{
    public function __construct(private readonly LeavePay $pay, private readonly LeaveCreditRecords $credits) {}

    /**
     * The plan, the sentence that says what it leaves without pay and the
     * one that says what it pays below zero — addressed to the employee on
     * their own request ("Your …"), else about them ("The employee's …");
     * each null when there is nothing to say.
     *
     * @return array{plan: LeavePayPlan, notice: string|null, below_zero: string|null}|null
     */
    public function withNotice(Access $access, string $employeeId, string $type, ?string $dayType, string $start, string $end): ?array
    {
        $plan = $this->plan($access, $employeeId, $type, $dayType, $start, $end);
        if ($plan === null) {
            return null;
        }
        $own = $access->hasEmployeeLink() && $access->ownEmployeeId() === $employeeId;

        return ['plan' => $plan, 'notice' => LeavePay::notice($plan, $own), 'below_zero' => LeavePay::belowZero($plan, $own)];
    }

    /**
     * MY leave types with set days, for My Portal's credits card: each
     * one's set days and, for a yearly one, how many are left in `$year`
     * (the days approved with pay drawing on it). Pinned to the session's
     * own employee; nothing for an unlinked account. A type the employee may
     * not use (LeaveEligibilities) is left out.
     *
     * @return list<array{name: string, filing: string, allowance: float, used: float, left: float}>
     */
    public function ownAllowances(Access $access, int $year): array
    {
        if (! $access->hasEmployeeLink() || ! $access->hasModule(ModuleCode::PORTAL, ModuleCode::LEAVES)) {
            return [];
        }

        return $this->allowances($access->ownEmployeeId(), $year);
    }

    /**
     * An employee's leave types with set days. Unguarded: every caller has
     * already decided the actor may read this employee.
     *
     * @return list<array{name: string, filing: string, allowance: float, used: float, left: float}>
     */
    private function allowances(string $employeeId, int $year): array
    {
        $standing = LeaveEligibilities::forEmployees([$employeeId])[$employeeId] ?? [];
        $out = [];
        foreach (LeaveTypeCreditRecords::rules() as $token => $entry) {
            $settings = $entry['rules'];
            if (! $settings->hasAllowance() || $settings->allowanceDays === null || ! LeaveCreditPolicy::creditable($entry['name'])) {
                continue;
            }
            if (array_key_exists((string) $token, $standing) && $standing[(string) $token]['since'] === null) {
                continue;
            }
            $used = $settings->filing === LeaveTypeCredits::FILING_YEARLY ? LeaveBalances::allowanceUsed($employeeId, $year, (string) $token) : 0.0;
            $out[] = [
                'name' => $entry['name'],
                'filing' => $settings->filing,
                'allowance' => $settings->allowanceDays,
                'used' => $used,
                'left' => LeaveQuantity::normalize(max(0.0, $settings->allowanceDays - $used)),
            ];
        }

        return $out;
    }

    /**
     * MY standing for the leave application's Certification of Leave Credits
     * (section 7.A), one column per leave type with a balance to state:
     * what is available today, what the draft would draw if approved, and
     * what that leaves, so each column subtracts as the form prints it. The
     * draw follows the plan: this type's credits first, then its fallback,
     * and anything paid below zero stays on this type. A leave without a
     * balance (occasion or unpaid leave) has no column, nor has a type with
     * no credit entered that the draft does not draw on: a column of zeros
     * for every type HR set up would crowd out the ones held. Pinned to the
     * session's own employee; nothing for an unlinked account.
     *
     * @param  Collection<int, LeaveBalanceView>  $balances  MY credits for `$year`
     * @param  LeavePayPlan|null  $plan  the draft's plan; null while it costs nothing
     * @return list<array{name: string, unit: string, earned: float, less: float, balance: float}>
     */
    public function ownCertification(Access $access, Collection $balances, int $year, ?array $plan): array
    {
        if (! $access->hasEmployeeLink() || ! $access->hasModule(ModuleCode::PORTAL, ModuleCode::LEAVES)) {
            return [];
        }

        return $this->standing($access->ownEmployeeId(), $balances, $year, self::draw($plan), taken: false);
    }

    /**
     * What the plan would draw, in the shape `standing` reads.
     *
     * @param  LeavePayPlan|null  $plan
     * @return array{paid: float, own: float, fallback_available: float, key: string, fallback: string|null, filing: string, name: string}|null
     */
    private static function draw(?array $plan): ?array
    {
        return $plan === null ? null : [
            'paid' => $plan['refusal'] !== null ? 0.0 : $plan['paid_days'], 'own' => $plan['own'], 'fallback_available' => $plan['fallback_available'],
            'key' => $plan['key'], 'fallback' => $plan['fallback'], 'filing' => $plan['filing'], 'name' => $plan['name'],
        ];
    }

    /**
     * What a filed application states, for its read-only form: the working
     * days applied for, how many were approved with and without pay, and the
     * Certification of Leave Credits. A pending request reads like a draft.
     * An approved one has already left the balances, so its columns add its
     * cost back to show what it was taken from. Anything else took nothing.
     * Official business has no days to state. For the employee's own request,
     * or any request to an all-data LEAVES holder, the reading `plan` allows;
     * anyone else reads it as empty.
     *
     * @return array{needs: float|null, with_pay: float|null, without_pay: float|null, certification: list<array{name: string, unit: string, earned: float, less: float, balance: float}>}
     */
    public function filed(Access $access, LeaveTravelRequestView $request): array
    {
        $own = $access->hasEmployeeLink() && $request->employee_id === $access->ownEmployeeId();
        if (! $own && ! $access->mayReadAllData(ModuleCode::LEAVES)) {
            return ['needs' => null, 'with_pay' => null, 'without_pay' => null, 'certification' => []];
        }
        $employeeId = $request->employee_id;
        $start = $request->start_date->format('Y-m-d');
        $plan = $this->plan($access, $request->employee_id, $request->request_type, $request->day_type, $start, $request->end_date->format('Y-m-d'));
        $year = $request->credits_year ?? $plan['year'] ?? LeaveCreditPolicy::yearOf($start);
        $balances = $own ? $this->credits->own($access, $year) : collect($this->credits->balances($access, $employeeId, $year));
        $needs = $plan['needs'] ?? null;
        if ($request->status === 'PENDING') {
            return ['needs' => $needs, 'with_pay' => null, 'without_pay' => null, 'certification' => $this->standing($employeeId, $balances, $year, self::draw($plan), taken: false)];
        }
        if ($request->status !== 'APPROVED' || $request->is_paid === null || $plan === null) {
            return ['needs' => $needs, 'with_pay' => null, 'without_pay' => null, 'certification' => $this->standing($employeeId, $balances, $year, null, taken: false)];
        }
        if ($request->is_paid === false) {
            return ['needs' => $needs, 'with_pay' => 0.0, 'without_pay' => $needs, 'certification' => $this->standing($employeeId, $balances, $year, null, taken: false)];
        }
        $withPay = (float) ($request->credit_days ?? $needs ?? 0.0);
        $withoutPay = LeaveQuantity::normalize(count($request->unpaid_dates ?? []) * LeaveCreditPolicy::unit($request->day_type));
        $draw = ['paid' => $withPay, 'own' => $withPay, 'fallback_available' => 0.0, 'key' => $plan['key'], 'fallback' => null, 'filing' => $plan['filing'], 'name' => $plan['name']];

        return ['needs' => LeaveQuantity::normalize($withPay + $withoutPay), 'with_pay' => $withPay, 'without_pay' => $withoutPay, 'certification' => $this->standing($employeeId, $balances, $year, $draw, taken: true)];
    }

    /**
     * The certification's columns. `$taken` says the draw has already left
     * the balances, so each column adds it back instead of subtracting it.
     *
     * @param  Collection<int, LeaveBalanceView>  $balances
     * @param  array{paid: float, own: float, fallback_available: float, key: string, fallback: string|null, filing: string, name: string}|null  $draw
     * @return list<array{name: string, unit: string, earned: float, less: float, balance: float}>
     */
    private function standing(string $employeeId, Collection $balances, int $year, ?array $draw, bool $taken): array
    {
        $paid = $draw['paid'] ?? 0.0;
        $fromFallback = $draw === null ? 0.0 : min($draw['fallback_available'], max(0.0, $paid - $draw['own']));

        $columns = [];
        foreach ($balances as $balance) {
            $less = match (true) {
                $draw === null => 0.0,
                $balance->request_type === $draw['key'] => $paid - $fromFallback,
                $balance->request_type === $draw['fallback'] => $fromFallback,
                default => 0.0,
            };
            if (! $balance->entered() && $less <= 0.0) {
                continue;
            }
            $columns[] = self::column($balance->label, LeaveTypeCreditRecords::of($balance->request_type)->unit, $balance->available ?? 0.0 - $balance->used, $less, $taken);
        }
        foreach ($this->allowances($employeeId, $year) as $allowance) {
            if ($allowance['filing'] === LeaveTypeCredits::FILING_YEARLY) {
                $drawn = $draw !== null && $draw['filing'] === LeaveTypeCredits::FILING_YEARLY && $draw['name'] === $allowance['name'];
                $columns[] = self::column($allowance['name'], LeaveTypeCredits::UNIT_DAYS, $allowance['left'], $drawn ? $paid : 0.0, $taken);
            }
        }

        return $columns;
    }

    /** @return array{name: string, unit: string, earned: float, less: float, balance: float} */
    private static function column(string $name, string $unit, float $available, float $less, bool $taken): array
    {
        $less = LeaveQuantity::normalize($less);

        return $taken
            ? ['name' => $name, 'unit' => $unit, 'earned' => LeaveQuantity::normalize($available + $less), 'less' => $less, 'balance' => $available]
            : ['name' => $name, 'unit' => $unit, 'earned' => $available, 'less' => $less, 'balance' => LeaveQuantity::normalize($available - $less)];
    }

    /** @return LeavePayPlan|null */
    public function plan(Access $access, string $employeeId, string $type, ?string $dayType, string $start, string $end): ?array
    {
        $allowed = match ($access->rowVisibility(ModuleCode::LEAVES, ModuleCode::PORTAL)) {
            Access::ROWS_ALL => $access->mayReadAllData(ModuleCode::LEAVES) || ($access->hasEmployeeLink() && $access->ownEmployeeId() === $employeeId),
            Access::ROWS_OWN => $access->ownEmployeeId() === $employeeId,
            Access::ROWS_NONE => false,
        };
        $type = trim($type);
        if (! $allowed || $employeeId === '' || $type === '' || $type === AbsencePolicy::OFFICIAL_BUSINESS
            || ! PayrollPeriod::isPayrollDate($start) || ! PayrollPeriod::isPayrollDate($end) || $start > $end) {
            return null;
        }

        return $this->pay->plan($employeeId, $type, $dayType, $start, $end);
    }
}
