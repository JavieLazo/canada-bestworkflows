<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use Illuminate\Support\Facades\DB;

/**
 * EVERY DAY IS A WORKING DAY unless the employee's own schedule rests it: a
 * shift pattern carries hours only, and a rest day is a Rest day set on the
 * employee. This moves the rest days a pattern's work week used to give
 * (Monday–Friday leaves Saturday and Sunday) onto each employee, so nobody's
 * schedule changes on any date:
 *
 *   - the default shift in effect on `$from` gets a new dated row working all
 *     seven days, and each weekday it rested gets an explicit Rest day row
 *     (unless the weekday already has hours or a rest of its own), all under
 *     one REST_DAYS change dated `$from`;
 *   - a default shift dated later works all seven days in place and gains
 *     the same Rest day rows from its own date, under its own change — it
 *     has judged no day yet;
 *   - a weekday dated later to go "back to the default shift" on a day that
 *     default shift rests becomes a Rest day row in place;
 *   - then every pattern's work week becomes all seven days.
 *
 * One transaction per employee, so a killed run resumes: an employee whose
 * default shift already works every day is skipped. Run from the migration
 * with no actor; nothing is audited and no account is named.
 */
final class RestDayConversion
{
    public const ALL_DAYS = [0, 1, 2, 3, 4, 5, 6];

    /** Converts every employee on a default shift, then the patterns; answers how many employees changed. */
    public static function run(string $from): int
    {
        $converted = 0;
        foreach (RosterVersions::live(Shift::query())->distinct()->orderBy('employee_id')->pluck('employee_id')->all() as $employeeId) {
            $converted += DB::transaction(static fn (): int => self::employee((string) $employeeId, $from) ? 1 : 0);
        }
        foreach (ShiftType::query()->orderBy('id')->get() as $pattern) {
            if (self::restOf($pattern->work_days) !== []) {
                $pattern->forceFill(['work_days' => self::ALL_DAYS])->save();
            }
        }

        return $converted;
    }

    /**
     * The weekdays a work week rests (empty or missing reads Monday–Friday,
     * as the engine reads it).
     *
     * @return list<int>
     */
    public static function restOf(mixed $workDays): array
    {
        $days = is_array($workDays) ? array_values(array_map(intval(...), $workDays)) : null;

        return array_values(array_diff(self::ALL_DAYS, ShiftResolver::workDaysOrDefault($days)));
    }

    /**
     * One employee, read whole before anything is written: the default-shift
     * periods from `$from` on (the one in effect, then each dated later,
     * cleared ones included) decide which weekdays rest in each, and each
     * period's weekdays with no hours of their own get an explicit Rest day
     * row where they rest — and go back to the default shift where a later
     * period works them, or clears the shift, so no Rest day outlives it.
     */
    private static function employee(string $employeeId, string $from): bool
    {
        $versions = app(RosterVersions::class);
        $now = $versions->standingAsOf([$employeeId], $from, lock: true)[$employeeId] ?? null;
        $segments = [$from => $now];
        foreach ($versions->pendingStanding([$employeeId], $from, lock: true) as $row) {
            // Oldest first, so the last saved for a date is the one kept.
            $segments[$row->effective_from?->format('Y-m-d') ?? $from] = $row;
        }
        // The weekdays each period rests: a cleared shift rests none, as nothing is rostered.
        $restIn = static fn (?Shift $row): array => $row === null || $row->clearsShift() ? [] : self::restOf($row->work_days);
        ksort($segments);
        $own = [];
        foreach (array_keys($segments) as $date) {
            $own[$date] = $versions->weekdaysAsOf([$employeeId], $date)[$employeeId] ?? [];
        }
        $restAt = static function (string $date) use ($segments, $restIn): array {
            $rest = [];
            foreach ($segments as $start => $row) {
                if ($start <= $date) {
                    $rest = $restIn($row);
                }
            }

            return $rest;
        };
        $backToDefault = $versions->pendingWeekdays([$employeeId], $from, lock: true)->filter(static fn (RosterShift $row): bool => $row->shift_data === null
            && in_array($row->weekday, $restAt($row->effective_from?->format('Y-m-d') ?? $from), true))->values();
        $anyRest = array_filter($segments, static fn (?Shift $row): bool => $restIn($row) !== []) !== [];
        if (! $anyRest && $backToDefault->isEmpty()) {
            return false;
        }

        $change = null;
        $resting = [];
        foreach ($segments as $date => $row) {
            $rest = $restIn($row);
            $changeId = null;
            if ($row !== null && $rest !== []) {
                if ($date === $from) {
                    $change ??= self::change($employeeId, $from);
                    $changeId = $change->id;
                    $copy = $row->replicate();
                    $copy->forceFill(['work_days' => self::ALL_DAYS, 'effective_from' => $from, 'change_id' => $changeId])->save();
                } else {
                    $row->forceFill(['work_days' => self::ALL_DAYS])->save();
                }
            }
            $hours = $row === null || $rest === [] ? DomainShift::default() : DomainShift::fromArray($row->toArray());
            foreach (self::ALL_DAYS as $weekday) {
                if (isset($own[$date][$weekday])) {
                    // The weekday's own hours or rest decide it from here.
                    unset($resting[$weekday]);

                    continue;
                }
                $rests = in_array($weekday, $rest, true);
                if ($rests === isset($resting[$weekday])) {
                    continue;
                }
                $changeId ??= $date === $from ? ($change ??= self::change($employeeId, $from))->id : $row?->change_id;
                RosterShift::query()->create(['employee_id' => $employeeId, 'weekday' => $weekday, 'effective_from' => $date, 'change_id' => $changeId]
                    + ($rests ? RosterDayChoice::entry('REST', $weekday, $hours, null) : ['is_rest_day' => false, 'shift_type_id' => null, 'shift_name' => null, 'shift_data' => null]));
                if ($rests) {
                    $resting[$weekday] = true;
                } else {
                    unset($resting[$weekday]);
                }
            }
        }
        foreach ($backToDefault as $row) {
            $row->fill(RosterDayChoice::entry('REST', $row->weekday, DomainShift::default(), null))->save();
        }

        return true;
    }

    private static function change(string $employeeId, string $from): RosterScheduleChange
    {
        $change = new RosterScheduleChange(['employee_id' => $employeeId, 'effective_from' => $from,
            'source' => RosterScheduleChange::SOURCE_REST_DAYS, 'status' => RosterScheduleChange::STATUS_ACTIVE]);
        $change->save();

        return $change;
    }
}
