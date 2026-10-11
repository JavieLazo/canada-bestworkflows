<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftShapePolicy;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Domain\Roster\RosterSchedulePolicy;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use RuntimeException;

/**
 * Both roster assignment surfaces (the row menu's confirm and the Assign
 * shift dialog) share the same validation and write operation — the port of
 * PUT /api/shifts/[employeeId] with `shift_type_id` set (route.ts:46-93):
 * gate, resolve, re-derive from the pattern, shape-check, write a dated row
 * (none when the hours are already those), audit.
 *
 * DATED AND GUARDED: the assignment is a NEW default-shift row from its
 * effective date, under its own `roster_schedule_changes` row, never an
 * overwrite, so every earlier date keeps the shift it had. It runs through
 * `DatedChange`: today or later only; with no date given, today until
 * today's cutoff and the next date after it; and never so that an earlier
 * date's record, or yesterday's night shift, changes. `preview()` answers
 * what it would do without saving.
 *
 * The pattern is read FOR UPDATE inside the transaction that writes the row,
 * in the lock order `SaveShiftType` takes: pattern, employee, standing shift,
 * weekday shifts, then date overrides. An assignment and pattern edit serialize: the
 * copy stored here is the pattern as it stands at commit, never a snapshot
 * the edit has just rewritten under it. `lockForUpdate()` is a no-op on
 * sqlite, so the suite cannot show this; MariaDB can.
 */
final class AssignShift
{
    public function __construct(private readonly EmployeeDirectory $employees, private readonly DatedChange $change) {}

    public function handle(Access $actor, string $employeeId, string $patternId, ?string $effectiveFrom = null, ?string $confirmed = null): string
    {
        [$impact, $pattern, $attributes, $name] = $this->apply($actor, $employeeId, $patternId, $effectiveFrom, $confirmed, commit: true);
        if (! $impact->changed()) {
            return "No changes: {$name} is already on {$pattern->name}.";
        }
        $from = $impact->effective[$employeeId];
        $later = $from !== ManilaClock::todayKey();
        // Two reference sentences meet here. FIXED keeps the server's raw clock,
        // `${input.start_time}–${input.end_time}` (route.ts:90). FLEXIBLE is a
        // DELIBERATE DIVERGENCE from that same line, which logged the filler
        // 08:00:00–17:00:00 nobody set: it takes the client's sentence instead,
        // "{pattern} (N h per day)" (roster-page.tsx:150-154).
        $what = $pattern->mode === DomainShift::MODE_FLEXIBLE
            ? "{$pattern->name} (".HrisFormat::hoursPerDay($pattern->required_hours).')'
            : "{$attributes['start_time']}\u{2013}{$attributes['end_time']}";
        AuditService::record($actor, AuditEvent::SHIFT_ASSIGNED, 'shifts', $employeeId, "{$name} assigned {$what}".($later ? ' · from '.HrisFormat::date($from) : ''));

        return "{$pattern->name} assigned to {$name}.".($later ? ' It takes effect on '.HrisFormat::date($from).'.' : '');
    }

    /** What the assignment would do — its date and what it affects — with nothing saved. */
    public function preview(Access $actor, string $employeeId, string $patternId, ?string $effectiveFrom = null): ScheduleImpact
    {
        return $this->apply($actor, $employeeId, $patternId, $effectiveFrom, null, commit: false)[0];
    }

    /** @return array{0: ScheduleImpact, 1: ShiftType, 2: array<string, mixed>, 3: string} */
    private function apply(Access $actor, string $employeeId, string $patternId, ?string $effectiveFrom, ?string $confirmed, bool $commit): array
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $employee = $this->employees->forRoster($actor, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $pattern = null;
        $attributes = [];
        $prepare = static function () use ($patternId, &$pattern, &$attributes): void {
            $pattern = ShiftType::query()->lockForUpdate()->find($patternId);
            if ($pattern === null) {
                throw new RuntimeException('That shift pattern no longer exists.');
            }
            $attributes = ShiftPolicy::attributesFromPattern($pattern);
            $error = ShiftShapePolicy::shapeError($attributes);
            if ($error !== null) {
                throw new RuntimeException($error);
            }
        };
        $write = static function (string $employeeId, string $from) use ($actor, &$attributes): bool {
            $versions = app(RosterVersions::class);
            $current = $versions->standingAsOf([$employeeId], $from, lock: true)[$employeeId] ?? null;
            // The same hours again (the dialog can re-pick the current
            // pattern) write no dated row, so no open payroll draft goes stale.
            if ($current !== null && DomainShift::fromArray($current->toArray())->toArray() === DomainShift::fromArray($attributes)->toArray()) {
                return false;
            }
            $change = RosterChanges::open($actor, $employeeId, $from);
            Shift::query()->create($attributes + ['employee_id' => $employeeId, 'effective_from' => $from, 'change_id' => $change->id]);
            // A new standing week must not leave only REST overrides: payroll
            // needs a nonzero scheduled-day divisor for monthly salaries.
            $versions->weekdaysAsOf([$employeeId], $from, lock: true, withDefaults: true);
            $error = RosterSchedulePolicy::error(app(ShiftRoster::class)->scheduleForEmployee($employeeId, lock: true, asOf: $from));
            if ($error !== null) {
                throw new RuntimeException($error);
            }

            return true;
        };
        $impact = $commit
            ? $this->change->run($actor, [$employeeId], $effectiveFrom, null, $write, $confirmed, $prepare)
            : $this->change->preview($actor, [$employeeId], $effectiveFrom, null, $write, $prepare);
        assert($pattern instanceof ShiftType);

        return [$impact, $pattern, $attributes, EmployeeName::display($employee)];
    }
}
