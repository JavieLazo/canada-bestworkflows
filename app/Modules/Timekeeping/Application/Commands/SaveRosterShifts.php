<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\RosterShift;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\RosterDayChoice;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Domain\Roster\CustomHours;
use App\Modules\Timekeeping\Domain\Roster\RosterSchedulePolicy;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns weekday hours independently of weekly places. DEFAULT sends the
 * weekday back to the default shift, REST explicitly rests, and a pattern
 * copies its hours while making the chosen weekday a working day — as do
 * custom hours typed in instead of a pattern (`CustomHours`, no pattern
 * behind them). KEEP retains a deleted pattern's snapshot only. Omitted
 * weekdays remain unchanged.
 *
 * DATED AND GUARDED: each change is a new row from its effective date under
 * one `roster_schedule_changes` row per save, DEFAULT included (a row with
 * no hours), so every earlier date keeps the hours it was judged by. It runs
 * through `DatedChange`: today or later, today only until today's cutoff,
 * and never disturbing an earlier date's record.
 *
 * Locks: pattern, then the employee (`DatedChange`), then standing, weekday
 * and date rows. Every roster write holds the employee lock before any
 * schedule row, so two writes for one employee serialize there.
 * Changes commit together; best-effort roster auditing waits for the outer
 * transaction so a combined location save cannot report an aborted change.
 */
final class SaveRosterShifts
{
    public function __construct(private readonly EmployeeDirectory $employees, private readonly DatedChange $change) {}

    /** @param array<int|string, mixed> $days */
    public function handle(Access $actor, string $employeeId, array $days, ?string $effectiveFrom = null, ?string $confirmed = null): string
    {
        [$prepare, $write, $name] = $this->parts($actor, $employeeId, $days);
        $impact = $this->change->run($actor, [$employeeId], $effectiveFrom, null, $write, $confirmed, $prepare);

        return $impact->changed()
            ? "Weekly shifts saved for {$name}.".RosterChanges::takesEffect($impact->effective[$employeeId])
            : "No changes to {$name}'s weekly shifts.";
    }

    /**
     * What the save would do — its date and what it affects — with nothing saved.
     *
     * @param  array<int|string, mixed>  $days
     */
    public function preview(Access $actor, string $employeeId, array $days, ?string $effectiveFrom = null): ScheduleImpact
    {
        [$prepare, $write] = $this->parts($actor, $employeeId, $days);

        return $this->change->preview($actor, [$employeeId], $effectiveFrom, null, $write, $prepare);
    }

    /**
     * The locks and the write, for `DatedChange` — here, or in
     * `SaveRosterWeek` beside the places. Gated and validated before either
     * closure exists.
     *
     * @param  array<int|string, mixed>  $days
     * @return array{0: Closure(): void, 1: Closure(string, string): bool, 2: string}
     */
    public function parts(Access $actor, string $employeeId, array $days): array
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $employee = $this->employees->forRoster($actor, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $wanted = $this->validated($days);
        $name = EmployeeName::display($employee);
        $patterns = null;

        $prepare = static function () use ($wanted, &$patterns): void {
            $patterns = RosterDayChoice::lockPatterns(array_values($wanted));
        };

        $write = static function (string $employeeId, string $from) use ($actor, $wanted, $name, &$patterns): bool {
            $versions = app(RosterVersions::class);
            $standingRow = $versions->standingAsOf([$employeeId], $from, lock: true)[$employeeId] ?? null;
            $standing = $standingRow === null ? DomainShift::default() : DomainShift::fromArray($standingRow->toArray());
            $rows = collect($versions->weekdaysAsOf([$employeeId], $from, lock: true)[$employeeId] ?? []);
            $changed = false;
            $change = null;
            // A change is a new row from its date, never an edit or delete of
            // the row an earlier date was judged by.
            $add = static function (int $weekday, array $entry) use ($actor, $employeeId, $from, &$change): RosterShift {
                $change ??= RosterChanges::open($actor, $employeeId, $from);

                return RosterShift::query()->create(['employee_id' => $employeeId, 'weekday' => $weekday, 'effective_from' => $from, 'change_id' => $change->id] + $entry);
            };
            foreach ($wanted as $weekday => $value) {
                /** @var RosterShift|null $row */
                $row = $rows->get($weekday);
                if ($value === 'KEEP') {
                    if ($row === null || $row->is_rest_day || $row->shift_type_id !== null) {
                        throw new RuntimeException('That saved shift is no longer available. Pick a shift again.');
                    }

                    continue;
                }
                if ($value === 'DEFAULT') {
                    if ($row !== null) {
                        $add($weekday, ['is_rest_day' => false, 'shift_type_id' => null, 'shift_name' => null, 'shift_data' => null]);
                        $rows->forget($weekday);
                        $changed = true;
                    }

                    continue;
                }
                if ($value === 'REST' && $row?->is_rest_day) {
                    // Keep an existing rest snapshot stable on repeated saves.
                    continue;
                }
                $entry = RosterDayChoice::entry($value, $weekday, $standing, $patterns);
                if ($row !== null && ! (clone $row)->fill($entry)->isDirty()) {
                    continue;
                }
                $rows->put($weekday, $add($weekday, $entry));
                $changed = true;
            }
            $error = RosterSchedulePolicy::error(app(ShiftRoster::class)->scheduleForEmployee($employeeId, lock: true, asOf: $from));
            if ($error !== null) {
                throw new RuntimeException($error);
            }
            if ($changed) {
                $parts = [];
                foreach ($rows->sortKeys() as $weekday => $row) {
                    $parts[] = HrisFormat::weekday($weekday).' '.$row->shift_name;
                }
                $description = $name.' · '.($parts === [] ? 'default shift every day' : implode(' · ', $parts)).RosterChanges::fromDate($from);
                DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::ROSTER_SHIFTS_SET, 'roster_shifts', $employeeId, $description));
            }

            return $changed;
        };

        return [$prepare, $write, $name];
    }

    /**
     * Each weekday's choice: DEFAULT, REST, KEEP, a pattern id, or custom
     * hours as the dialog's draft array (`CustomHours`).
     *
     * @param  array<int|string, mixed>  $days
     * @return array<int, string|array<array-key, mixed>>
     */
    private function validated(array $days): array
    {
        $wanted = [];
        foreach ($days as $weekday => $value) {
            if (! is_int($weekday) || $weekday < 0 || $weekday > 6) {
                throw new RuntimeException('Weekday must be 0 (Sunday) to 6 (Saturday).');
            }
            $wanted[$weekday] = RosterDayChoice::read($value, HrisFormat::weekday($weekday, short: false));
        }
        ksort($wanted);

        return $wanted;
    }
}
