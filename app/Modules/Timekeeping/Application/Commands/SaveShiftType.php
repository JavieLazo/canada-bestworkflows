<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftShapePolicy;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\RosterDayOverride;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Application\ShiftPolicy;
use App\Modules\Timekeeping\Application\ShiftTypeDraft;
use App\Modules\Timekeeping\Domain\Roster\RosterSchedulePolicy;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\CodeSequence;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Illuminate\Database\Eloquent\Collection;

/**
 * Owns validation, persistence and audit for the Shifting Settings editor —
 * and, on an edit, the fan-out onto the roster.
 *
 * A pattern is not referenced by the roster, it is COPIED onto it: assigning
 * copies the pattern's hours onto a standing `shifts` row or weekday
 * `roster_shifts` or date-specific `roster_day_overrides` snapshot
 * (`AssignShift` → `ShiftPolicy::attributesFromPattern`). Editing the pattern
 * therefore re-copies the same derivation onto every linked copy that still
 * applies from today, inside the one transaction that saves the pattern, so
 * the roster, the eDTR and any open payroll draft measure today and later
 * against the hours Records shows.
 *
 * DELIBERATE DIVERGENCE from the reference `PATCH /api/records/shift-types/[id]`
 * (route.ts:10-40), which writes only `shift_types` and leaves every rostered
 * employee on the old hours until re-assigned by hand.
 *
 * FROM THE EFFECTIVE DATE ON, NEVER BACKWARDS: the edit applies from
 * `$effectiveFrom` — today when blank, never a past date — under
 * `DatedChange`, which also decides whether today is still possible and
 * holds the edit for review when it moves or reaches an open payroll
 * draft. A default-shift or weekday copy in effect on that date gets a
 * NEW row from it under one PATTERN_EDIT `roster_schedule_changes`
 * row per employee, so every earlier date keeps the copy it was judged by. A
 * change dated after it judged nothing yet and takes the new copy in place,
 * as does an assignment that has not begun; one already running keeps its
 * worked dates and goes on from that date as a revision with the new hours.
 * Finalized payroll is immune: `FinalizePayroll` captures each shift into
 * the run's inputs (`PayrollInputs`) and never re-reads the roster; an open
 * draft whose month includes a changed date goes stale on purpose.
 *
 * ORPHANED ROWS ARE OUT OF REACH: the FK is `nullOnDelete`, so a row whose
 * pattern was deleted carries `shift_type_id` NULL, keeps its last copy and
 * is never rewritten here. A row that drifted from its pattern (an older copy
 * this fan-out never reached, or one rewritten by `ShiftTypeSeeder`, which
 * bypasses this command) snaps back to the pattern on its next edit.
 *
 * THE ROSTER GATE: the pattern itself needs RECORDS all-data; touching
 * `shifts` is what `AssignShift` refuses without ROSTER all-data, so an edit
 * of a pattern a copy still reaches from the effective date is refused,
 * before any write, when the actor lacks `mayReadAllData(ROSTER)`. A pattern
 * only past dates used still needs RECORDS alone.
 *
 * ONE AUDIT ROW PER EDIT: `SHIFT_TYPE_UPDATED` carries the count of distinct
 * employees re-copied across standing, weekday and date rows. No per-employee
 * `SHIFT_ASSIGNED` rows (one action, one row;
 * N rows per edit would drown the trail), and `SHIFT_UPDATED` stays unemitted.
 */
final class SaveShiftType
{
    public function handle(Access $access, ?string $id, ShiftTypeDraft $input, ?string $effectiveFrom = null, ?string $confirmed = null): string
    {
        [$impact, $message] = $this->apply($access, $id, $input, $effectiveFrom, $confirmed, commit: true);

        return $message;
    }

    /**
     * What an edit would do to the employees on the pattern — the date each
     * copy takes effect and what it affects — with nothing saved. A new
     * pattern reaches nobody.
     */
    public function preview(Access $access, ?string $id, ShiftTypeDraft $input, ?string $effectiveFrom = null): ScheduleImpact
    {
        return $this->apply($access, $id, $input, $effectiveFrom, null, commit: false)[0];
    }

    /** @return array{0: ScheduleImpact, 1: string} */
    private function apply(Access $access, ?string $id, ShiftTypeDraft $input, ?string $effectiveFrom, ?string $confirmed, bool $commit): array
    {
        if (trim($input->formName) === '') {
            throw new \RuntimeException('Name is required.');
        }
        // An empty week has no scheduled day to measure against and nothing to
        // divide the monthly rate by.
        if ($input->formWorkDays === []) {
            throw new \RuntimeException('Pick at least one working day.');
        }

        if (mb_strlen(trim($input->formName)) > 160) {
            throw new \RuntimeException('name: must be at most 160 characters.');
        }
        $flexible = $input->formMode === 'FLEXIBLE';
        // The client's payload mapping, verbatim (shift-types-page.tsx:170-186).
        // Bounds-checked because the property is client-writable: 0–6 only
        // (ShiftTypeInput, schemas.ts:124).
        $days = array_values(array_unique(array_filter(array_map(intval(...), $input->formWorkDays), static fn (int $d): bool => $d >= 0 && $d <= 6)));
        sort($days);
        if ($days === []) {
            throw new \RuntimeException('Pick at least one working day.');
        }
        $payload = ['name' => trim($input->formName), 'mode' => $flexible ? 'FLEXIBLE' : 'FIXED', 'work_days' => $days, 'start_time' => $flexible ? null : $input->formStartTime.':00', 'end_time' => $flexible ? null : $input->formEndTime.':00', 'grace_minutes' => $flexible ? 0 : (is_numeric($input->formGrace) ? (int) $input->formGrace : 0), 'required_hours' => $flexible ? is_numeric($input->formRequiredHours) ? (float) $input->formRequiredHours : 0.0 : null, 'break_start' => $input->formHasBreak && ! $flexible ? $input->formBreakStart.':00' : null, 'break_end' => $input->formHasBreak && ! $flexible ? $input->formBreakEnd.':00' : null, 'break_minutes' => $input->formHasBreak ? is_numeric($input->formBreakMinutes) ? (int) $input->formBreakMinutes : 0 : 0, 'requires_break_punch' => $input->formHasBreak && ! $flexible ? $input->formRequiresBreakPunch : false];
        // ShiftTypeInput's numeric caps (schemas.ts:118-134).
        if ($payload['grace_minutes'] < 0 || $payload['grace_minutes'] > 600) {
            throw new \RuntimeException('grace_minutes: must be between 0 and 600.');
        }
        if ($payload['break_minutes'] < 0 || $payload['break_minutes'] > 600) {
            throw new \RuntimeException('break_minutes: must be between 0 and 600.');
        }
        if ($payload['required_hours'] !== null && ($payload['required_hours'] < 0 || $payload['required_hours'] > 24)) {
            throw new \RuntimeException('required_hours: must be between 0 and 24.');
        }
        // The API's combination policy (api/records/shift-types/route.ts:19-25).
        $shapeError = ShiftShapePolicy::shapeError($payload);
        if ($shapeError !== null) {
            throw new \RuntimeException($shapeError);
        }
        // requireAllDataModule("RECORDS") — re-checked in the action itself.
        if (! $access->mayReadAllData(ModuleCode::RECORDS)) {
            throw new \RuntimeException('Your role cannot manage organization-wide data.');
        }
        $name = $payload['name'];
        if ($id === null) {
            if (! $commit) {
                return [new ScheduleImpact, ''];
            }
            $code = CodeSequence::next('SFT', ShiftType::query()->pluck('code'));
            $row = ShiftType::query()->create($payload + ['code' => $code]);
            AuditService::record($access, AuditEvent::SHIFT_TYPE_CREATED, 'shift_types', (string) $row->getKey(), "{$name} added as shift pattern {$code}");

            return [new ScheduleImpact, "{$name} added."];
        }

        // Everyone a copy of the pattern still reaches from the date asked
        // for. Read before any lock, only to name who to lock and to gate:
        // the write reads each employee's copies again under their lock,
        // and the list is read AGAIN under the pattern lock below.
        $versions = app(RosterVersions::class);
        $asOf = $effectiveFrom ?? ManilaClock::todayKey();
        $reach = self::reach($versions, $id, $asOf);
        if ($reach !== [] && ! $access->mayReadAllData(ModuleCode::ROSTER)) {
            throw new \RuntimeException('Editing a pattern that employees are rostered on needs Shift Roster access.');
        }

        $row = null;
        $copy = [];
        // The pattern and its fan-out commit together or not at all: a
        // refusal for any employee rolls the pattern back with every copy.
        $prepare = static function () use ($id, $payload, $versions, $asOf, $reach, &$row, &$copy): void {
            $row = ShiftType::query()->lockForUpdate()->find($id);
            if ($row === null) {
                throw new \RuntimeException('That shift pattern no longer exists.');
            }
            // Every assignment of a pattern locks that pattern first, so
            // under this lock the reach cannot move: one that differs from
            // the list read before the lock means someone was rostered on
            // (or off) the pattern meanwhile, and the copy would miss them.
            if (self::reach($versions, $id, $asOf) !== $reach) {
                throw new \RuntimeException('The roster changed while this pattern was being saved. Nothing was changed — try again.');
            }
            $row->fill($payload);
            // The exact derivation AssignShift stores, so a rostered row
            // reads the same whether it was assigned or re-copied. The
            // shape check is defence in depth: the payload check above
            // already refuses everything this can (the filler clock a
            // FLEXIBLE pattern gains is always a valid FIXED window).
            $copy = ShiftPolicy::attributesFromPattern($row);
            $copyError = ShiftShapePolicy::shapeError($copy);
            if ($copyError !== null) {
                throw new \RuntimeException($copyError);
            }
            $row->save();
        };
        $write = static function (string $employeeId, string $from) use ($access, $versions, &$row, &$copy): bool {
            assert($row instanceof ShiftType);

            return self::copyOnto($access, $versions, $row, $copy, $employeeId, $from);
        };
        $change = app(DatedChange::class);
        $impact = $commit
            ? $change->run($access, $reach, $effectiveFrom, null, $write, $confirmed, $prepare)
            : $change->preview($access, $reach, $effectiveFrom, null, $write, $prepare);
        if (! $commit) {
            return [$impact, ''];
        }
        assert($row instanceof ShiftType);
        $rostered = count($reach);
        $date = $impact->date();
        $later = $date !== null && $date !== ManilaClock::todayKey();
        AuditService::record($access, AuditEvent::SHIFT_TYPE_UPDATED, 'shift_types', (string) $row->getKey(),
            "Shift pattern {$row->code} updated — {$name}".($rostered > 0 ? ' · re-copied onto '.self::rosteredPhrase($rostered) : '').($later ? ' · from '.HrisFormat::date($date) : ''));

        // "This pattern", not "new hours": a rename-only edit re-copies too.
        return [$impact, "{$name} updated.".match (true) {
            $rostered === 1 => ' 1 rostered employee '.($later ? 'follows this pattern from '.HrisFormat::date($date).'.' : 'now follows this pattern.'),
            $rostered > 1 => ' '.self::rosteredPhrase($rostered).' '.($later ? 'follow this pattern from '.HrisFormat::date($date).'.' : 'now follow this pattern.'),
            default => '',
        }];
    }

    /**
     * Copy the saved pattern onto ONE employee's schedule from `$from`, and
     * answer whether any hours changed (a new name alone changes in place
     * and needs no guarding).
     *
     * @param  array<string, mixed>  $copy
     */
    private static function copyOnto(Access $access, RosterVersions $versions, ShiftType $row, array $copy, string $employeeId, string $from): bool
    {
        $id = (string) $row->getKey();
        [$standingNow, $weekdaysNow, $pending, $dateRows] = self::linkedCopies($versions, $id, $from, [$employeeId], true);
        $weekdayCopy = static fn (int $weekday): array => ['shift_name' => $row->name, 'shift_data' => DomainShift::fromArray(array_replace($copy, ['work_days' => [$weekday]]))->toArray()];
        // Hours compared as the engine reads them, so a re-save or a
        // rename writes no new dated row and no open payroll draft
        // goes stale over nothing. A weekday or date copy's own
        // working days are never read — the roster works (or rests)
        // it on its day — so they are left out of that comparison.
        $sameHours = static function (?array $stored, array $wanted, bool $ownDays = true): bool {
            if ($stored === null) {
                return false;
            }
            $days = $ownDays ? [] : ['work_days' => null];

            return DomainShift::fromArray(array_replace($stored, $days))->toArray() === DomainShift::fromArray(array_replace($wanted, $days))->toArray();
        };
        $changed = false;
        // Rows in effect on the date: new rows from it under one
        // PATTERN_EDIT change, so every earlier date keeps its copy.
        $change = null;
        $open = static function () use ($access, $employeeId, $from, &$change): string {
            return $change ??= RosterChanges::open($access, $employeeId, $from, RosterScheduleChange::SOURCE_PATTERN_EDIT)->id;
        };
        foreach ($standingNow as $standing) {
            if (! $sameHours($standing->toArray(), $copy)) {
                Shift::query()->create($copy + ['employee_id' => $employeeId, 'effective_from' => $from, 'change_id' => $open()]);
                $changed = true;
            }
        }
        foreach ($weekdaysNow[$employeeId] ?? [] as $weekday => $weekdayRow) {
            $wanted = $weekdayCopy($weekday);
            if ($sameHours($weekdayRow->shift_data, $wanted['shift_data'], false)) {
                // A new name only: the label is display, never judged, so
                // it changes in place.
                $weekdayRow->fill(['shift_name' => $wanted['shift_name']])->save();

                continue;
            }
            RosterShift::query()->create(['employee_id' => $employeeId, 'weekday' => $weekday, 'shift_type_id' => $id, 'is_rest_day' => false,
                'effective_from' => $from, 'change_id' => $open()] + $wanted);
            $changed = true;
        }
        // Changes dated after it judged no day yet, so they take the new
        // copy in place.
        foreach ($pending as $pendingRow) {
            if ($pendingRow instanceof Shift) {
                $changed = $changed || ! $sameHours($pendingRow->toArray(), $copy);
                $pendingRow->fill($copy)->save();
            } else {
                $wanted = $weekdayCopy($pendingRow->weekday);
                $changed = $changed || ! $sameHours($pendingRow->shift_data, $wanted['shift_data'], false);
                $pendingRow->fill($wanted)->save();
            }
        }
        // Temporary assignments: one not yet begun takes the copy in
        // place; one already running keeps its worked dates and
        // continues from the date as a revision with the new hours — or,
        // when only the name changed, is renamed in place.
        foreach ($dateRows as $dateRow) {
            $first = $dateRow->date->format('Y-m-d');
            $dayCopy = static fn (string $date): array => ['shift_name' => $row->name, 'shift_data' => DomainShift::fromArray(array_replace($copy, ['work_days' => [CalendarDays::dayOfWeek($date)]]))->toArray()];
            $same = $sameHours($dateRow->shift_data, $dayCopy($first)['shift_data'], false);
            if ($first >= $from || $same) {
                $dateRow->fill($first >= $from ? $dayCopy($first) : ['shift_name' => $row->name])->save();
                $changed = $changed || ! $same;

                continue;
            }
            $revision = $dateRow->replicate(['status', 'cut_after', 'cancelled_by', 'cancelled_at', 'created_by', 'created_at', 'updated_at']);
            $revision->fill(['date' => $from, 'end_date' => RosterVersions::appliesThrough($dateRow), 'revision_of' => $dateRow->id] + $dayCopy($from));
            $revision->created_by = RosterChanges::accountId($access);
            // A calendar day stays a bare Y-m-d string (time-and-DTR).
            $dateRow->forceFill(['cut_after' => CalendarDays::previous($from)])->save();
            $revision->save();
            $changed = true;
        }
        $error = RosterSchedulePolicy::error(app(ShiftRoster::class)->scheduleForEmployee($employeeId, lock: true, asOf: $from));
        if ($error !== null) {
            throw new \RuntimeException($error);
        }

        return $changed;
    }

    /**
     * Everything a copy of pattern `$id` still reaches from `$today`:
     * default-shift and weekday rows in effect today, changes dated later,
     * and assignments applying today or later — then the employees they
     * belong to.
     *
     * @param  list<string>  $employeeIds
     * @return array{0: array<string, Shift>, 1: array<string, array<int, RosterShift>>, 2: list<Shift|RosterShift>, 3: Collection<int, RosterDayOverride>, 4: list<string>}
     */
    private static function linkedCopies(RosterVersions $versions, string $id, string $today, array $employeeIds, bool $lock): array
    {
        $standing = array_filter($versions->standingAsOf($employeeIds, $today, $lock), static fn (Shift $shift): bool => $shift->shift_type_id === $id);
        $weekdays = [];
        foreach ($versions->weekdaysAsOf($employeeIds, $today, $lock) as $employeeId => $days) {
            foreach ($days as $weekday => $weekdayRow) {
                if ($weekdayRow->shift_type_id === $id) {
                    $weekdays[$employeeId][$weekday] = $weekdayRow;
                }
            }
        }
        $pending = $employeeIds === [] ? [] : [
            ...$versions->pendingStanding($employeeIds, $today, $lock)->where('shift_type_id', $id)->all(),
            ...$versions->pendingWeekdays($employeeIds, $today, $lock)->where('shift_type_id', $id)->all(),
        ];
        $dated = $versions->assignments($employeeIds, $today, null, $lock)->where('shift_type_id', $id);
        $employees = array_values(array_unique(array_merge(array_keys($standing), array_keys($weekdays),
            array_map(static fn (Shift|RosterShift $pendingRow): string => $pendingRow->employee_id, $pending), $dated->pluck('employee_id')->all())));
        sort($employees);

        return [$standing, $weekdays, $pending, $dated, $employees];
    }

    /**
     * The employees a copy of pattern `$id` reaches from `$asOf`, sorted —
     * nobody for a date that is not one.
     *
     * @return list<string>
     */
    private static function reach(RosterVersions $versions, string $id, string $asOf): array
    {
        if (! PayrollPeriod::isPayrollDate($asOf)) {
            return [];
        }
        $candidates = array_values(array_unique(array_merge(
            RosterVersions::live(Shift::query())->where('shift_type_id', $id)->pluck('employee_id')->all(),
            RosterVersions::live(RosterShift::query())->where('shift_type_id', $id)->pluck('employee_id')->all(),
            RosterDayOverride::query()->where('shift_type_id', $id)->pluck('employee_id')->all(),
        )));

        return self::linkedCopies($versions, $id, $asOf, $candidates, false)[4];
    }

    /** "1 rostered employee" / "N rostered employees" — the toast and the audit sentence share it. */
    private static function rosteredPhrase(int $count): string
    {
        return $count.' rostered '.($count === 1 ? 'employee' : 'employees');
    }
}
