<?php

declare(strict_types=1);

namespace App\Livewire\Edtr\Support;

use App\Domain\Attendance\AttendanceLog;

/** Compatibility adapter; implementation belongs to \App\Modules\Timekeeping\Domain\Attendance\AttendancePolicy. */
final class AttendancePolicy
{
    /**
     * TS: `blocksPunch(inBounds)` — a punch outside the geofence is refused,
     * whichever way it goes. Nothing is stored marked out-of-bounds: a punch
     * that was recorded was in bounds, and one that was not never existed.
     */
    public static function blocksPunch(bool $inBounds): bool
    {
        return \App\Modules\Timekeeping\Domain\Attendance\AttendancePolicy::blocksPunch($inBounds);
    }

    /** TS: `geofenceBlockMessage(input)`. */
    public static function geofenceBlockMessage(string $logType, string $siteName, ?float $distanceMeters, int $radiusMeters): string
    {
        return \App\Modules\Timekeeping\Domain\Attendance\AttendancePolicy::geofenceBlockMessage($logType, $siteName, $distanceMeters, $radiusMeters);
    }

    /**
     * TS: `haversineMeters(lat1, lon1, lat2, lon2)` — great-circle distance
     * between two coordinates, in meters.
     */
    public static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return \App\Modules\Timekeeping\Domain\Attendance\AttendancePolicy::haversineMeters($lat1, $lon1, $lat2, $lon2);
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
    public static function pairingAnomaly(array $windowLogs, string $logType, ?string $now = null, ?float $expireOpenAfterMinutes = null): ?string
    {
        return \App\Modules\Timekeeping\Domain\Attendance\AttendancePolicy::pairingAnomaly($windowLogs, $logType, $now, $expireOpenAfterMinutes);
    }
}
