<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\RosterDayOverride;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Timekeeping\Application\AssignmentWriter;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One date's hours and place override the recurring week independently.
 * DEFAULT follows that weekly setting; both DEFAULT cancel the exception.
 * Copied hours survive pattern deletion and KEEP preserves that saved copy.
 * Locks follow pattern, employee, standing, weekday, date order used by pattern edits.
 *
 * A date's change is a one-day temporary assignment. Replacing or removing
 * it cancels the old row with who and when instead of overwriting or
 * deleting it; the dialog shows the saved change it replaces, so the
 * replacement is the person's own explicit choice. A date inside a
 * longer period is changed through that period (`EditAssignment`).
 *
 * DATED AND GUARDED: it runs through `DatedChange` with the date as both
 * ends, so a past date is refused, today is refused once today's cutoff has
 * passed, and no earlier date's record — nor yesterday's night shift — moves.
 *
 * A SHIFT CHANGE KEEPS REST DAYS: picking a shift for a date the weekly
 * schedule rests is refused, so an ordinary shift change never turns a rest
 * day into a working day. Scheduling work on a rest day is its own explicit
 * choice, `$workOnRestDay`, stored as `include_rest_days`. Attendance
 * already recorded on a rest day is never touched either way.
 */
final class SaveRosterDate
{
    public function __construct(private readonly EmployeeDirectory $employees, private readonly DatedChange $change) {}

    public function handle(Access $actor, string $employeeId, string $date, string $shiftChoice, string $locationChoice, bool $workOnRestDay = false, ?string $confirmed = null): string
    {
        [$prepare, $write] = $this->parts($actor, $employeeId, $date, $shiftChoice, $locationChoice, $workOnRestDay);
        $impact = $this->change->run($actor, [$employeeId], $date, $date, $write, $confirmed, $prepare);

        return $impact->changed() ? "Schedule saved for {$date}." : "No changes to the schedule for {$date}.";
    }

    /** What the save would do and affect, with nothing saved. */
    public function preview(Access $actor, string $employeeId, string $date, string $shiftChoice, string $locationChoice, bool $workOnRestDay = false): ScheduleImpact
    {
        [$prepare, $write] = $this->parts($actor, $employeeId, $date, $shiftChoice, $locationChoice, $workOnRestDay);

        return $this->change->preview($actor, [$employeeId], $date, $date, $write, $prepare);
    }

    /** @return array{0: Closure(): void, 1: Closure(string, string): bool} */
    private function parts(Access $actor, string $employeeId, string $date, string $shiftChoice, string $locationChoice, bool $workOnRestDay): array
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $employee = $this->employees->forRoster($actor, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $shiftChoice = trim($shiftChoice);
        if ($shiftChoice === '') {
            throw new RuntimeException('Pick a shift for this date.');
        }
        $place = AssignmentWriter::place(trim($locationChoice));
        $name = EmployeeName::display($employee);
        $pattern = null;

        $prepare = static function () use ($shiftChoice, &$pattern): void {
            $pattern = AssignmentWriter::lockPattern($shiftChoice);
        };
        $write = static function (string $employeeId, string $date) use ($actor, $shiftChoice, $place, $name, $workOnRestDay, &$pattern): bool {
            $versions = app(RosterVersions::class);
            $versions->standingAsOf([$employeeId], $date, lock: true);
            $versions->weekdaysAsOf([$employeeId], $date, lock: true, withDefaults: true);
            // The date's current assignment: the latest save still applying.
            // One that spans other dates is a period, which this one-date
            // change must not cut short or split.
            $row = AssignmentWriter::overlapping($employeeId, $date, $date)->last();
            if ($row !== null && ($row->date->format('Y-m-d') !== $date || RosterVersions::appliesThrough($row) !== $date)) {
                throw new RuntimeException('That date is part of a temporary schedule for several dates. Change that schedule instead.');
            }
            if ($shiftChoice === 'DEFAULT' && $place['location_kind'] === null) {
                if ($row === null) {
                    return false;
                }
                // Cancelled, not deleted: the history keeps who removed it.
                RosterChanges::cancelAssignment($actor, $row, CalendarDays::previous($date));
                AssignmentWriter::assertPayable($employeeId, $date);
                $description = "{$name} · {$date} · follows weekly schedule";
                DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::ROSTER_DATE_SET, 'roster_day_overrides', $employeeId, $description));

                return true;
            }
            $hours = AssignmentWriter::hours($employeeId, $date, $shiftChoice, $pattern, $row);
            // A place the date does not already have is a new assignment.
            if ($place['site_id'] !== null && ! app(OrganizationReferences::class)->siteAssignable($place['site_id'], $row?->site_id)) {
                throw new RuntimeException(AssignmentWriter::INACTIVE_SITE);
            }
            $includeRestDays = AssignmentWriter::includeRestDays($employeeId, $name, $date, $date, $hours, $workOnRestDay);
            if ($row instanceof RosterDayOverride && ! (clone $row)->fill($hours + $place + ['include_rest_days' => $includeRestDays])->isDirty()) {
                return false;
            }
            // A replaced date keeps the old assignment in the history,
            // cancelled with who and when, and the new one names it.
            if ($row !== null) {
                AssignmentWriter::release($actor, $row, $date, $date);
            }
            AssignmentWriter::create($actor, $employeeId, $date, $date, $hours, $place, $includeRestDays, $row?->id);
            AssignmentWriter::assertPayable($employeeId, $date);
            $description = AssignmentWriter::sentence($actor, $name, $date, $date, $hours, $place);
            DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::ROSTER_DATE_SET, 'roster_day_overrides', $employeeId, $description));

            return true;
        };

        return [$prepare, $write];
    }
}
