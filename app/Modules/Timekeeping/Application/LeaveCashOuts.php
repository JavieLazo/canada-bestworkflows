<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveCashOut;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\Schema;

/**
 * What the rest of Timekeeping needs to know about leave credit cash-outs
 * (`leave_cash_outs`) without reading the table itself: whether it exists
 * yet, and how many days of a type an employee has spoken for — pending,
 * or approved and not yet paid — which every balance a cash-out is judged
 * against must leave out.
 */
final class LeaveCashOuts
{
    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('leave_cash_outs'));
    }

    /**
     * The days of each type an employee has asked for that no payroll has
     * paid yet, token => days.
     *
     * @return array<string, float>
     */
    public static function spokenFor(string $employeeId): array
    {
        return self::spokenForMany([$employeeId])[$employeeId] ?? [];
    }

    /**
     * The same for a page of employees, in one query.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, float>> employee id => token => days
     */
    public static function spokenForMany(array $employeeIds): array
    {
        if ($employeeIds === [] || ! self::ready()) {
            return [];
        }
        $out = [];
        $rows = LeaveCashOut::query()->whereIn('employee_id', $employeeIds)
            ->whereIn('status', [LeaveCashOut::PENDING, LeaveCashOut::APPROVED])
            ->get(['employee_id', 'request_type', 'days']);
        foreach ($rows as $row) {
            $out[$row->employee_id][$row->request_type] = LeaveQuantity::normalize(($out[$row->employee_id][$row->request_type] ?? 0.0) + $row->days);
        }

        return $out;
    }

    /**
     * The days an employee asked to cash out in a calendar year that were
     * neither refused nor withdrawn — what counts against the policy's
     * yearly maximum.
     */
    public static function askedInYear(string $employeeId, int $year): float
    {
        if (! self::ready()) {
            return 0.0;
        }
        // The Manila year, as a UTC span: the clock's instant of its first
        // midnight up to the next year's.
        $from = ManilaClock::instant(sprintf('%04d-01-01T00:00:00', $year))->utc();
        $to = ManilaClock::instant(sprintf('%04d-01-01T00:00:00', $year + 1))->utc();
        $total = LeaveCashOut::query()->where('employee_id', $employeeId)
            ->whereIn('status', [LeaveCashOut::PENDING, LeaveCashOut::APPROVED, LeaveCashOut::PAID])
            ->where('requested_at', '>=', $from->format('Y-m-d H:i:s.v'))
            ->where('requested_at', '<', $to->format('Y-m-d H:i:s.v'))
            ->sum('days');

        return LeaveQuantity::normalize((float) $total);
    }
}
