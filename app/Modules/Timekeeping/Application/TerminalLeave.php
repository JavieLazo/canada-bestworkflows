<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveCashOut;
use App\Models\LeaveCredit;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Support\ManilaClock;

/**
 * TERMINAL LEAVE: when an employee leaves, HR pays out the whole remaining
 * balance of every leave type set to be paid out on separation (Leave
 * Settings), one cash conversion per type with something left — what
 * FileTerminalLeave files and what the Cash conversions tab shows before
 * HR files it. The balance is the live one less the days already spoken
 * for, as every cash conversion reads it; nothing here applies the limits
 * an employee's own request is held to. No authorization: the callers
 * check theirs first.
 */
final class TerminalLeave
{
    private function __construct() {}

    /** Whether terminal leave can be filed yet (the windows before the cron migrates 2026_11_08_0005 and 2026_11_09_0001). */
    public static function ready(): bool
    {
        return LeaveCashOuts::ready() && LeaveCashOuts::purposeReady() && LeaveTypeCreditRecords::cashOutLimitsReady();
    }

    /**
     * What terminal leave would pay out for the employee today: each type
     * paid out on separation with credits above zero, in name order.
     *
     * @return list<array{token: string, name: string, days: float}>
     */
    public static function plan(string $employeeId): array
    {
        $year = LeaveCharges::liveYear($employeeId, (int) substr(ManilaClock::todayKey(), 0, 4));
        $rules = LeaveTypeCreditRecords::rules();
        $lines = [];
        foreach (LeaveTypeCreditRecords::paidOnSeparation() as $token) {
            if (! LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $year)->where('request_type', $token)->exists()) {
                continue;
            }
            $days = LeaveBalances::available($employeeId, $year, $token);
            if ($days > 0.0) {
                $lines[] = ['token' => $token, 'name' => $rules[$token]['name'] ?? $token, 'days' => LeaveQuantity::normalize($days)];
            }
        }

        return $lines;
    }

    /**
     * Why terminal leave cannot be filed for the employee now, or null: a
     * cash conversion still waiting for a decision (the payout must be the
     * whole balance, so that one is decided first), or terminal leave
     * already filed and not refused or withdrawn.
     */
    public static function blocker(string $employeeId, string $name): ?string
    {
        $open = LeaveCashOut::query()->where('employee_id', $employeeId)
            ->whereIn('status', [LeaveCashOut::PENDING, LeaveCashOut::APPROVED, LeaveCashOut::PAID])
            ->get(['status', 'purpose']);
        if ($open->contains(static fn (LeaveCashOut $row): bool => $row->purpose === LeaveCashOuts::TERMINAL)) {
            return "Terminal leave was already filed for {$name}.";
        }
        if ($open->contains(static fn (LeaveCashOut $row): bool => $row->status === LeaveCashOut::PENDING)) {
            return "{$name} has a cash conversion waiting for a decision. Approve or reject it first.";
        }

        return null;
    }

    /**
     * "Vacation Leave 12.5 days · Sick Leave 30 days"
     *
     * @param  list<array{token: string, name: string, days: float}>  $lines
     */
    public static function text(array $lines): string
    {
        return implode(' · ', array_map(static fn (array $line): string => $line['name'].' '.LeaveQuantity::format($line['days']).' '.($line['days'] === 1.0 ? 'day' : 'days'), $lines));
    }
}
