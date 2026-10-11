<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Domain\Attendance;

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\Minutes;
use App\Support\ManilaClock;

/**
 * Ports of `copems/src/lib/attendance-policy.ts`, `pairingAnomaly` /
 * `openPunchExpiryMinutes` consumers from `copems/src/lib/dtr.ts:471-521`, and
 * `haversineMeters` from `copems/src/lib/hris.ts` — the pure rules the punch
 * station applies before a punch is stored. They live with the EDTR module
 * because no other screen writes punches; the DTR arithmetic itself stays in
 * App\Domain\Attendance.
 */
final class AttendancePolicy
{
    private function __construct() {}

    /**
     * TS: `blocksPunch(inBounds)` — a punch outside the geofence is refused,
     * whichever way it goes. Nothing is stored marked out-of-bounds: a punch
     * that was recorded was in bounds, and one that was not never existed.
     */
    public static function blocksPunch(bool $inBounds): bool
    {
        return ! $inBounds;
    }

    /** TS: `geofenceBlockMessage(input)`. */
    public static function geofenceBlockMessage(
        string $logType,
        string $siteName,
        ?float $distanceMeters,
        int $radiusMeters,
    ): string {
        $punch = $logType === AttendanceLog::TIME_IN ? 'Time In' : 'Time Out';

        if ($distanceMeters === null) {
            return "{$punch} is blocked: location could not be verified at {$siteName}.";
        }

        $metres = (int) Minutes::round($distanceMeters);

        return "{$punch} is blocked: punch location is {$metres} m from {$siteName} (radius {$radiusMeters} m).";
    }

    /**
     * TS: `haversineMeters(lat1, lon1, lat2, lon2)` — great-circle distance
     * between two coordinates, in meters.
     */
    public static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $toRad = static fn (float $d): float => $d * M_PI / 180.0;
        $dLat = $toRad($lat2 - $lat1);
        $dLon = $toRad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos($toRad($lat1)) * cos($toRad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(sqrt($a));
    }

    /**
     * TS: `pairingAnomaly(todaysLogs, logType, options)` — explains why a
     * proposed punch cannot pair with the latest one, or null.
     *
     * `$expireOpenAfterMinutes` is what stops a forgotten clock-out cascading
     * into the next day: past it the open punch no longer refuses a Time In,
     * so the next shift starts normally and the abandoned day waits for a
     * correction. Expiry bounds the Time In only — a Time Out still closes
     * whatever is open, however long it has been.
     *
     * @param  list<AttendanceLog>  $windowLogs
     */
    public static function pairingAnomaly(
        array $windowLogs,
        string $logType,
        ?string $now = null,
        ?float $expireOpenAfterMinutes = null,
    ): ?string {
        $sorted = $windowLogs;
        usort(
            $sorted,
            static fn (AttendanceLog $a, AttendanceLog $b): int => ManilaClock::ms(ManilaClock::instant($a->loggedAt))
                <=> ManilaClock::ms(ManilaClock::instant($b->loggedAt)),
        );

        $last = $sorted === [] ? null : $sorted[count($sorted) - 1];
        $openIn = $last !== null && $last->logType === AttendanceLog::TIME_IN ? $last : null;

        $stale = $openIn !== null
            && $expireOpenAfterMinutes !== null
            && $now !== null
            && (ManilaClock::ms(ManilaClock::instant($now)) - ManilaClock::ms(ManilaClock::instant($openIn->loggedAt))) / 60000
                > $expireOpenAfterMinutes;

        if ($logType === AttendanceLog::TIME_IN) {
            if ($openIn !== null && ! $stale) {
                return 'Duplicate TIME_IN — previous TIME_IN is still open.';
            }

            return null;
        }

        if ($openIn === null) {
            return 'TIME_OUT with no open TIME_IN.';
        }

        return null;
    }
}
