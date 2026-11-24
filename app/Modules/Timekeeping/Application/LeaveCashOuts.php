<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\LeaveCashOut;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Support\LocalClock;
use Illuminate\Support\Facades\Schema;

/**
 * What the rest of Timekeeping needs to know about leave credit cash-outs
 * (`leave_cash_outs`) without reading the table itself: whether it exists
 * yet, and how many days of a type an employee has spoken for — pending,
 * or approved and not yet paid — which every balance a cash-out is judged
 * against must leave out — how many days and requests of a type count
 * against the type's yearly limits, and what a request is for (its
 * PURPOSE): MONETIZATION, an employee's own request within the type's
 * limits (RequestLeaveCashOut), or TERMINAL, the balance HR pays out when
 * the employee leaves (FileTerminalLeave), which no limit reads.
 */
final class LeaveCashOuts
{
    public const string MONETIZATION = 'MONETIZATION';

    public const string TERMINAL = 'TERMINAL';

    /** @var array<string, string> what a cash conversion is for, as the screens name it */
    public const array PURPOSES = [self::MONETIZATION => 'Monetization of Leave Credits', self::TERMINAL => 'Terminal Leave'];

    private function __construct() {}

    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('leave_cash_outs'));
    }

    /** Whether a request carries whether it was large yet (the window before the cron migrates 2026_11_09_0002). */
    public static function largeReady(): bool
    {
        return once(static fn (): bool => self::ready() && Schema::hasColumn('leave_cash_outs', 'large_request'));
    }

    /** Whether a request carries its purpose yet (the window before the cron migrates 2026_11_08_0005). */
    public static function purposeReady(): bool
    {
        return once(static fn (): bool => self::ready() && Schema::hasColumn('leave_cash_outs', 'purpose'));
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
     * What an employee asked to cash out of one leave type in a calendar
     * year, neither refused nor withdrawn — what counts against the type's
     * yearly limits: the days of the requests that were not LARGE (a large
     * one may go past the yearly maximum, so it does not use it up) and the
     * number of requests, large ones included. Terminal leave counts toward
     * neither.
     *
     * @return array{days: float, requests: int}
     */
    public static function inYear(string $employeeId, string $token, int $year): array
    {
        if (! self::ready()) {
            return ['days' => 0.0, 'requests' => 0];
        }
        // The local year, as a UTC span: the clock's instant of its first
        // midnight up to the next year's.
        $from = LocalClock::instant(sprintf('%04d-01-01T00:00:00', $year))->utc();
        $to = LocalClock::instant(sprintf('%04d-01-01T00:00:00', $year + 1))->utc();
        $query = LeaveCashOut::query()->where('employee_id', $employeeId)->where('request_type', $token)
            ->whereIn('status', [LeaveCashOut::PENDING, LeaveCashOut::APPROVED, LeaveCashOut::PAID])
            ->where('requested_at', '>=', $from->format('Y-m-d H:i:s.v'))
            ->where('requested_at', '<', $to->format('Y-m-d H:i:s.v'));
        if (self::purposeReady()) {
            $query->where(static fn ($q) => $q->whereNull('purpose')->orWhere('purpose', '!=', self::TERMINAL));
        }
        $large = self::largeReady();
        $rows = $query->get($large ? ['days', 'large_request'] : ['days']);
        $days = 0.0;
        foreach ($rows as $row) {
            if (! $large || ! $row->large_request) {
                $days += $row->days;
            }
        }

        return ['days' => LeaveQuantity::normalize($days), 'requests' => $rows->count()];
    }
}
