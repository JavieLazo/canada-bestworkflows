<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\LeaveRule;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeavePolicy;
use App\Modules\Timekeeping\Domain\Credits\LeaveTypeRule;
use Carbon\CarbonImmutable;

/**
 * Leave rule versions written straight to `leave_rules` from any date — the
 * command saves only for the day it runs — for a test that needs the
 * earning job, payroll's charge read or filing to have terms to read and is
 * not about setting them (LeaveRulesTest drives the command and the
 * screen). The terms are given in the shape the rules add up to for
 * everybody — a LeavePolicy's params — but only the EARNING is stored, as
 * one version per earning leave type with no waiting period; every other
 * leave type gets a version that earns nothing, so the rules are in force
 * from `$from` even when nothing earns. The rest of the params (posting
 * day, days without pay, the charge switch and orders, the cash-out limit)
 * is not stored: the rules fix it (LeaveRuleBook::policyOn), and charging
 * follows the leave types' own credit rules — create the types first.
 */
final class LeaveRuleFixture
{
    /**
     * @param  array<string, mixed>  $params  LeavePolicy params, as they would be typed
     * @return string the first version written
     */
    public static function approved(string $from, array $params): string
    {
        $earning = LeavePolicy::fromParams($params)->earning();
        $tokens = array_map('strval', array_keys(LeaveTypeCreditRecords::rules()));
        $first = null;
        foreach (array_values(array_unique([...array_keys($earning), ...$tokens])) as $token) {
            $rule = isset($earning[$token])
                ? LeaveTypeRule::fromParams(['waiting_months' => null, 'waiting_extends' => false, 'on_acquire' => LeaveTypeRule::ZERO, 'earning' => $earning[$token], 'fallback' => null])
                : LeaveTypeRule::none();
            $id = self::row((string) $token, $from, $rule->toArray());
            $first ??= $id;
        }
        if ($first === null) {
            throw new \LogicException('Create the leave types before their rules.');
        }

        return $first;
    }

    /** @param  array<string, mixed>  $params */
    private static function row(string $scope, string $from, array $params): string
    {
        $row = LeaveRule::query()->where('scope', $scope)->where('effective_from', $from)->first() ?? new LeaveRule;
        $row->forceFill([
            'scope' => $scope, 'effective_from' => $from, 'params' => $params, 'status' => LeaveRule::APPROVED,
            'prepared_by_name' => 'Preparer', 'approved_by_name' => 'Approver', 'approved_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $row->id;
    }
}
