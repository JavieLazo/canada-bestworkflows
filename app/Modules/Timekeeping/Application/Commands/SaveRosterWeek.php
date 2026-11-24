<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Domain\Roster\WorkWeek;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Support\Access;
use App\Support\HrisFormat;
use Closure;
use RuntimeException;

/**
 * The weekly dialog saves hours and places as one indivisible user action:
 * one `DatedChange`, so both take effect on the same date, under the same
 * safeguards, or neither is saved.
 *
 * EVERY DAY HAS A SHIFT OR A REST DAY: for an employee with no shift
 * assigned before, a weekday left on no shift would refuse every Time In on
 * it and read absent in the DTR and payroll, week after week, so the save is
 * refused until each day has one. An employee with an assigned shift keeps
 * it on the days left to it.
 */
final class SaveRosterWeek
{
    public function __construct(private readonly SaveRosterShifts $shifts, private readonly SaveRosterLocations $locations, private readonly DatedChange $change) {}

    /**
     * @param  array<int|string, mixed>  $shifts
     * @param  array<int|string, mixed>  $locations
     */
    public function handle(Access $actor, string $employeeId, array $shifts, array $locations, ?string $effectiveFrom = null, ?string $confirmed = null): string
    {
        [$prepare, $write] = $this->parts($actor, $employeeId, $shifts, $locations);
        $impact = $this->change->run($actor, [$employeeId], $effectiveFrom, null, $write, $confirmed, $prepare);

        return $impact->changed()
            ? 'Weekly schedule saved.'.RosterChanges::takesEffect($impact->effective[$employeeId])
            : 'No changes to the weekly schedule.';
    }

    /**
     * What the save would do — its date and what it affects — with nothing saved.
     *
     * @param  array<int|string, mixed>  $shifts
     * @param  array<int|string, mixed>  $locations
     */
    public function preview(Access $actor, string $employeeId, array $shifts, array $locations, ?string $effectiveFrom = null): ScheduleImpact
    {
        [$prepare, $write] = $this->parts($actor, $employeeId, $shifts, $locations);

        return $this->change->preview($actor, [$employeeId], $effectiveFrom, null, $write, $prepare);
    }

    /**
     * @param  array<int|string, mixed>  $shifts
     * @param  array<int|string, mixed>  $locations
     * @return array{0: Closure(): void, 1: Closure(string, string): bool}
     */
    private function parts(Access $actor, string $employeeId, array $shifts, array $locations): array
    {
        [$prepare, $hours] = $this->shifts->parts($actor, $employeeId, $shifts);
        [$places] = $this->locations->parts($actor, $employeeId, $locations);

        return [$prepare, static function (string $employeeId, string $from) use ($hours, $places): bool {
            // Both run: a save of hours alone must not skip the places.
            $changedHours = $hours($employeeId, $from);
            $changedPlaces = $places($employeeId, $from);
            self::assertEveryDayScheduled($employeeId, $from);

            return $changedHours || $changedPlaces;
        }];
    }

    /**
     * Refuses a week that leaves a day with no shift from `$from` on: no
     * hours or Rest day of its own, and no shift assigned to fall back on.
     */
    private static function assertEveryDayScheduled(string $employeeId, string $from): void
    {
        $versions = app(RosterVersions::class);
        if (($versions->standingAsOf([$employeeId], $from)[$employeeId] ?? null) !== null) {
            return;
        }
        $rows = $versions->weekdaysAsOf([$employeeId], $from)[$employeeId] ?? [];
        $missing = [];
        foreach (WorkWeek::weekdays() as $weekday) {
            if (! isset($rows[$weekday])) {
                $missing[] = HrisFormat::weekday($weekday, short: false);
            }
        }
        if ($missing !== []) {
            $last = array_pop($missing);
            $days = $missing === [] ? $last : implode(', ', $missing).' and '.$last;
            throw new RuntimeException("Choose a shift or Rest day for {$days}. A day with no shift records no Time In and reads absent.");
        }
    }
}
