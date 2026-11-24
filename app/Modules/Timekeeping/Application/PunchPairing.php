<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;
use App\Modules\Timekeeping\Domain\Attendance\AttendancePolicy;
use App\Modules\Timekeeping\Domain\Attendance\PunchWorkDate;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;

/**
 * Whether a punch pressed now pairs with the punches before it — ONE answer
 * for `RecordPunch`, which refuses a punch that does not, and the punch
 * station's preview, which turns Time Out off on a date with no shift unless
 * there is a Time In open for it to close. Two copies could disagree about
 * which Time In is still open; this is the only one.
 */
final class PunchPairing
{
    /**
     * Why a punch of `$logType` at `$now` would not pair, or null when it
     * would ("TIME_OUT with no open TIME_IN.", a duplicate Time In).
     *
     * @param  list<DomainLog>  $windowLogs  the employee's punches from two dates before `$today` through now, oldest first
     */
    public static function anomaly(array $windowLogs, string $logType, CarbonImmutable $now, ?DomainShift $schedule, string $today): ?string
    {
        // A Time In left open past the shift plus its overtime ceiling
        // is somebody who forgot to clock out, not somebody still
        // working — so it stops blocking the next shift and the day
        // waits for a correction.
        $maxOtHours = app(OpenPunchLimit::class)->hours();
        $nowIso = DomainLog::isoFromUtc($now->utc());
        $openWorkDate = PunchWorkDate::forScheduledPunch($windowLogs, DomainLog::TIME_OUT, $now, $schedule);
        $expiryShift = $schedule?->forDate($openWorkDate);
        // Extra ownership context must not widen the established live
        // pairing window to accept a two-days-old open Time In.
        $pairingFrom = CalendarDays::previous($today);
        $pairingLogs = array_values(array_filter($windowLogs, static fn (DomainLog $log): bool => LocalClock::dayKey(LocalClock::instant($log->loggedAt)) >= $pairingFrom));

        return AttendancePolicy::pairingAnomaly($pairingLogs, $logType, $nowIso, ShiftResolver::openPunchExpiryMinutes($expiryShift, $maxOtHours));
    }
}
