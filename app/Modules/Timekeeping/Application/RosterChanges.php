<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Models\RosterDayOverride;
use App\Models\RosterScheduleChange;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;

/**
 * How the roster commands record a change as dated history instead of
 * overwriting it: each command that writes opens one `roster_schedule_changes`
 * row that its new default-shift, weekday-hours or weekday-place rows belong
 * to — the Weekly schedule dialog runs two commands, so it can open one for
 * hours and one for places — and an assignment that stops applying is cut
 * short or cancelled with who and when, never deleted.
 *
 * The acting account always comes from Access, never from input.
 */
final class RosterChanges
{
    public static function open(Access $actor, string $employeeId, string $effectiveFrom, string $source = RosterScheduleChange::SOURCE_MANUAL): RosterScheduleChange
    {
        $change = new RosterScheduleChange([
            'employee_id' => $employeeId,
            'effective_from' => $effectiveFrom,
            'source' => $source,
            'status' => RosterScheduleChange::STATUS_ACTIVE,
        ]);
        $change->created_by = self::accountId($actor);
        $change->save();

        return $change;
    }

    /**
     * Stop an assignment after `$lastDate` — before its first date to cancel
     * it outright. Its dates up to `$lastDate` keep the hours and place they
     * had.
     */
    public static function cancelAssignment(Access $actor, RosterDayOverride $row, string $lastDate): void
    {
        $row->status = RosterDayOverride::STATUS_CANCELLED;
        // A calendar day stays a bare Y-m-d string (time-and-DTR guide).
        $row->forceFill(['cut_after' => $lastDate]);
        $row->cancelled_at = CarbonImmutable::now('UTC');
        $row->cancelled_by = self::accountId($actor);
        $row->save();
    }

    /** " It takes effect on Oct 12, 2026." for a later date; nothing for today. */
    public static function takesEffect(string $from): string
    {
        return $from === ManilaClock::todayKey() ? '' : ' It takes effect on '.HrisFormat::date($from).'.';
    }

    /** " · from Oct 12, 2026" on an audit sentence for a later date; nothing for today. */
    public static function fromDate(string $from): string
    {
        return $from === ManilaClock::todayKey() ? '' : ' · from '.HrisFormat::date($from);
    }

    public static function accountId(Access $actor): ?int
    {
        return $actor->userId === null ? null : (int) $actor->userId;
    }
}
