<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Domain\Roster\RosterSchedulePolicy;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use RuntimeException;

/**
 * Clears an employee's shift: from the effective date they have NO
 * default shift and NO weekday hours — the state of someone never assigned
 * one, so the Shift Roster reads "No shift" and every date from then on is
 * measured against the engine's default (8:00 AM–5:00 PM, Monday–Friday) and
 * counted as unscheduled (`ScheduleBasis::NONE`), as it is for anyone never
 * rostered.
 *
 * DATED AND GUARDED, never a delete: clearing is a new default-shift row
 * marked `is_unassigned`, plus a "back to the default shift" row for each
 * weekday that had hours of its own, all under one `roster_schedule_changes`
 * row, so every earlier date keeps the shift it was judged by and the
 * change can be cancelled (`CancelScheduleChange`) until it takes effect.
 * It runs through `DatedChange` exactly as `AssignShift` does: today or
 * later only; with no date given, today until today's cutoff and the next
 * date after it; and never so that an earlier date's record, or yesterday's
 * night shift, changes.
 *
 * Weekday PLACES and temporary schedules are left as they are: a place says
 * where, not when, and a temporary schedule is its own dated choice, still
 * cancelled on its own. A change already dated after the effective date
 * still applies on its date.
 *
 * Lock order is AssignShift's: employee, standing shift, weekday shifts,
 * then the date overrides the validation reads.
 */
final class ClearShift
{
    /**
     * What a clearing row stores. The hour columns are NOT NULL, so it carries
     * the engine default's hours as filler; `RosterVersions` never reads them.
     *
     * @var array<string, mixed>
     */
    private const NO_SHIFT = [
        'is_unassigned' => true,
        'shift_type_id' => null,
        'mode' => DomainShift::MODE_FIXED,
        'required_hours' => null,
        'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00',
        'end_time' => '17:00:00',
        'grace_minutes' => 15,
        'break_start' => null,
        'break_end' => null,
        'break_minutes' => 0,
        'requires_break_punch' => false,
    ];

    public function __construct(private readonly EmployeeDirectory $employees, private readonly DatedChange $change) {}

    public function handle(Access $actor, string $employeeId, ?string $effectiveFrom = null, ?string $confirmed = null): string
    {
        [$impact, $name, $was] = $this->apply($actor, $employeeId, $effectiveFrom, $confirmed, commit: true);
        if (! $impact->changed()) {
            return "No changes: {$name} has no shift to clear.";
        }
        $from = $impact->effective[$employeeId];
        AuditService::record($actor, AuditEvent::SHIFT_CLEARED, 'shifts', $employeeId, "{$name} shift cleared · was ".implode(' · ', $was).RosterChanges::fromDate($from));

        return "{$name}'s shift cleared.".RosterChanges::takesEffect($from);
    }

    /** What clearing would do — its date and what it affects — with nothing saved. */
    public function preview(Access $actor, string $employeeId, ?string $effectiveFrom = null): ScheduleImpact
    {
        return $this->apply($actor, $employeeId, $effectiveFrom, null, commit: false)[0];
    }

    /** @return array{0: ScheduleImpact, 1: string, 2: list<string>} */
    private function apply(Access $actor, string $employeeId, ?string $effectiveFrom, ?string $confirmed, bool $commit): array
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $employee = $this->employees->forRoster($actor, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        if (! Shift::clearingReady()) {
            throw new RuntimeException('Clearing a shift needs this release’s database update. Ask your administrator to run it first.');
        }
        $was = [];
        $write = static function (string $employeeId, string $from) use ($actor, &$was): bool {
            $versions = app(RosterVersions::class);
            $standing = $versions->standingAsOf([$employeeId], $from, lock: true)[$employeeId] ?? null;
            $weekdays = $versions->weekdaysAsOf([$employeeId], $from, lock: true)[$employeeId] ?? [];
            if ($standing === null && $weekdays === []) {
                return false;
            }
            $change = RosterChanges::open($actor, $employeeId, $from);
            $was = [];
            if ($standing !== null) {
                Shift::query()->create(self::NO_SHIFT + ['employee_id' => $employeeId, 'effective_from' => $from, 'change_id' => $change->id]);
                $was[] = 'default '.self::hours(DomainShift::fromArray($standing->toArray()));
            }
            ksort($weekdays);
            foreach ($weekdays as $weekday => $row) {
                RosterShift::query()->create(['employee_id' => $employeeId, 'weekday' => $weekday, 'effective_from' => $from, 'change_id' => $change->id,
                    'is_rest_day' => false, 'shift_type_id' => null, 'shift_name' => null, 'shift_data' => null]);
                $was[] = HrisFormat::weekday($weekday).' '.($row->is_rest_day ? 'Rest day' : (string) $row->shift_name);
            }
            // Temporary schedules stay; the month they leave must still give
            // payroll a working day to divide by.
            $error = RosterSchedulePolicy::error(app(ShiftRoster::class)->scheduleForEmployee($employeeId, lock: true, asOf: $from));
            if ($error !== null) {
                throw new RuntimeException($error);
            }

            return true;
        };
        $impact = $commit
            ? $this->change->run($actor, [$employeeId], $effectiveFrom, null, $write, $confirmed)
            : $this->change->preview($actor, [$employeeId], $effectiveFrom, null, $write);

        return [$impact, EmployeeName::display($employee), $was];
    }

    /** "8:00 AM–5:00 PM", or "Flexible · 8 h per day". */
    private static function hours(DomainShift $shift): string
    {
        return $shift->isFlexible()
            ? 'Flexible · '.HrisFormat::hoursPerDay($shift->requiredHoursOrDefault())
            : HrisFormat::hm($shift->startTime).'–'.HrisFormat::hm($shift->endTime);
    }
}
