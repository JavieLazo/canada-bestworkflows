<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Notifications\Notifier;
use App\Modules\Timekeeping\Domain\Roster\DateRuns;
use App\Support\Access;
use App\Support\HrisFormat;

/**
 * Tells an employee their schedule changed, so they learn it from their
 * inbox and My Portal's My schedule rather than from the punch station
 * refusing a Time In.
 *
 * One notice per employee and change, sent by `DatedChange::run()` for every
 * dated roster write that changed something and by `FillUnscheduledDates`;
 * a preview, a refusal or a change held for review sends nothing. Every
 * active account of the employee is told except the actor's own, as every
 * event notification is. The notice names the dates and links to My
 * schedule; it never names who changed it or why.
 */
final class ScheduleChangeNotice
{
    public const KIND = 'SCHEDULE_CHANGED';

    public const HREF = '/portal?tab=schedule';

    /** `$to` null: from `$from` on, for good. */
    public static function tell(Access $actor, string $employeeId, string $from, ?string $to): void
    {
        $title = match (true) {
            $to === null => 'Your schedule changes from '.HrisFormat::date($from),
            $to === $from => 'Your schedule for '.HrisFormat::date($from).' changed',
            default => 'Your schedule from '.HrisFormat::date($from).' to '.HrisFormat::date($to).' changed',
        };

        self::send($actor, $employeeId, $title);
    }

    /**
     * The dates themselves, when they need not follow one another: "Your
     * schedule for Nov 3, 7, 2026 changed".
     *
     * @param  non-empty-list<string>  $dates
     */
    public static function tellDates(Access $actor, string $employeeId, array $dates): void
    {
        self::send($actor, $employeeId, 'Your schedule for '.DateRuns::describe($dates).' changed');
    }

    private static function send(Access $actor, string $employeeId, string $title): void
    {
        Notifier::employees([$employeeId], [$actor->userId], self::KIND, $title, 'See My schedule in My Portal.', self::HREF);
    }
}
