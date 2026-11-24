<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Modules\Timekeeping\Domain\Roster\ScheduleTimeline;
use App\Support\LocalClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The one reader of the dated schedule rows (2026_09_30_0003–0007): the
 * default shift (`shifts`), weekday hours (`roster_shifts`), weekday places
 * (`roster_locations`) and temporary assignments (`roster_day_overrides`).
 *
 * A row counts only while it is LIVE: it belongs to the baseline (NULL
 * `change_id`) or to a change that was not cancelled. "As of" a date means
 * the latest live row on or before it, NULL `effective_from` first, then by
 * date, then by row id (a time-ordered ULID), so the change saved last for a
 * date wins. A default-shift row that CLEARS the shift (`is_unassigned`)
 * reads as no shift from its date: no hours in the timeline, no row from
 * `standingAsOf`. `pendingStanding` still lists it, with no pattern, so a
 * caller matching a pattern never meets it. Nothing here filters by actor:
 * callers are trusted module code whose public queries apply Access first,
 * as `ShiftRoster` always was.
 */
final class RosterVersions
{
    /**
     * Every requested employee's history, empty when they have none.
     *
     * @param  list<string>  $employeeIds
     * @param  string|null  $through  rows taking effect after this date are left out
     * @param  bool  $hours  false when only places are needed (the punch and correction place)
     * @return array<string, ScheduleTimeline>
     */
    public function timelines(array $employeeIds, ?string $through = null, bool $lock = false, bool $places = false, bool $hours = true): array
    {
        $standing = $weekdays = $placeEntries = [];
        foreach ($hours ? $this->standingRows($employeeIds, $through, $lock) : [] as $row) {
            // A cleared shift is an entry with no shift: from its date nothing is
            // assigned, and its filler hours are never read.
            $standing[$row->employee_id][] = ['from' => $row->effective_from?->format('Y-m-d'), 'order' => (string) $row->id, 'shift' => $row->clearsShift() ? null : DomainShift::fromArray($row->toArray())];
        }
        foreach ($hours ? $this->weekdayRows($employeeIds, $through, $lock) : [] as $row) {
            $weekdays[$row->employee_id][] = ['from' => $row->effective_from?->format('Y-m-d'), 'order' => (string) $row->id, 'weekday' => $row->weekday, 'shift' => self::weekdayShift($row)];
        }
        if ($places) {
            foreach ($this->placeRows($employeeIds, $through, $lock) as $row) {
                $placeEntries[$row->employee_id][] = ['from' => $row->effective_from?->format('Y-m-d'), 'order' => (string) $row->id, 'weekday' => $row->weekday, 'kind' => $row->kind, 'site' => $row->site_id];
            }
        }
        $out = [];
        foreach ($employeeIds as $employeeId) {
            $out[$employeeId] = new ScheduleTimeline($standing[$employeeId] ?? [], $weekdays[$employeeId] ?? [], $placeEntries[$employeeId] ?? []);
        }

        return $out;
    }

    /**
     * The default-shift row in effect on `$date`, per employee that has one.
     * An employee whose shift was cleared by then has none, so the clearing
     * row itself is never returned.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, Shift>
     */
    public function standingAsOf(array $employeeIds, string $date, bool $lock = false): array
    {
        $out = [];
        foreach ($this->standingRows($employeeIds, $date, $lock) as $row) {
            $out[$row->employee_id] = $row;
        }

        return array_filter($out, static fn (Shift $row): bool => ! $row->clearsShift());
    }

    /**
     * The weekday-hours rows in effect on `$date`: employee => weekday =>
     * row. A weekday gone back to the default shift is left out unless
     * `$withDefaults` asks for the row that says so.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<int, RosterShift>>
     */
    public function weekdaysAsOf(array $employeeIds, string $date, bool $lock = false, bool $withDefaults = false): array
    {
        $out = [];
        foreach ($this->weekdayRows($employeeIds, $date, $lock) as $row) {
            $out[$row->employee_id][$row->weekday] = $row;
        }

        return $withDefaults ? $out : self::withoutDefaults($out, static fn (RosterShift $row): bool => $row->shift_data === null);
    }

    /**
     * The weekday-place rows in effect on `$date`: employee => weekday =>
     * row. A weekday gone back to the Assigned Worksite is left out unless
     * `$withDefaults` asks for the row that says so.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array<int, RosterLocation>>
     */
    public function placesAsOf(array $employeeIds, string $date, bool $lock = false, bool $withDefaults = false): array
    {
        $out = [];
        foreach ($this->placeRows($employeeIds, $date, $lock) as $row) {
            $out[$row->employee_id][$row->weekday] = $row;
        }

        return $withDefaults ? $out : self::withoutDefaults($out, static fn (RosterLocation $row): bool => $row->kind === null);
    }

    /**
     * Default-shift rows taking effect AFTER `$date` (changes not yet in
     * effect), oldest first.
     *
     * @param  list<string>  $employeeIds  empty for every employee
     * @return Collection<int, Shift>
     */
    public function pendingStanding(array $employeeIds, string $date, bool $lock = false): Collection
    {
        $query = self::live(Shift::query(), $employeeIds === [] ? null : $employeeIds);
        self::pending($query, $employeeIds, $date, $lock);

        return $query->get();
    }

    /**
     * Weekday-hours rows taking effect AFTER `$date`, oldest first.
     *
     * @param  list<string>  $employeeIds  empty for every employee
     * @return Collection<int, RosterShift>
     */
    public function pendingWeekdays(array $employeeIds, string $date, bool $lock = false): Collection
    {
        $query = self::live(RosterShift::query(), $employeeIds === [] ? null : $employeeIds);
        self::pending($query, $employeeIds, $date, $lock);

        return $query->get();
    }

    /**
     * Temporary assignments applying on at least one date in [`$from`,
     * `$to`] (either end open), oldest save first so a later one wins a date
     * both cover.
     *
     * @param  list<string>|null  $employeeIds  null for every employee
     * @return Collection<int, RosterDayOverride>
     */
    public function assignments(?array $employeeIds, ?string $from, ?string $to, bool $lock = false): Collection
    {
        return RosterDayOverride::query()
            ->when($employeeIds !== null, static fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->whereRaw('COALESCE(cut_after, end_date, date) >= date')
            ->when($to !== null, static fn ($q) => $q->where('date', '<=', $to))
            ->when($from !== null, static fn ($q) => $q->whereRaw('COALESCE(cut_after, end_date, date) >= ?', [$from]))
            ->orderBy('employee_id')->orderBy('id')
            ->when($lock, static fn ($q) => $q->lockForUpdate())->get();
    }

    /** `$date` when it is a real calendar day, else today in local time: the "as of" every roster read defaults to. */
    public static function dateOrToday(?string $date): string
    {
        return PayrollPeriod::isPayrollDate($date) ? (string) $date : LocalClock::todayKey();
    }

    /** The last date an assignment applies: where it was cut short, else its end, else its one day. */
    public static function appliesThrough(RosterDayOverride $row): string
    {
        return ($row->cut_after ?? $row->end_date ?? $row->date)->format('Y-m-d');
    }

    /**
     * A weekday row as the shift that works (or rests) on that weekday
     * alone; null for a row that sends the weekday back to the default shift.
     */
    public static function weekdayShift(RosterShift $row): ?DomainShift
    {
        if ($row->shift_data === null) {
            return null;
        }

        return DomainShift::fromArray(array_replace($row->shift_data, [
            'shift_type_id' => $row->shift_type_id,
            'work_days' => $row->is_rest_day ? [-1] : [$row->weekday],
        ]));
    }

    /**
     * Restrict to rows of the baseline or of a change that was not cancelled.
     * The cancelled ids are read first, in their own plain query, so a
     * locking read of schedule rows never scans (or locks) the change table.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>|null  $employeeIds  narrows the cancelled-change lookup; null for every employee
     * @return Builder<TModel>
     */
    public static function live(Builder $query, ?array $employeeIds = null): Builder
    {
        $cancelled = RosterScheduleChange::query()->where('status', RosterScheduleChange::STATUS_CANCELLED)
            ->when($employeeIds !== null, static fn ($q) => $q->whereIn('employee_id', $employeeIds === [] ? [''] : $employeeIds))
            ->pluck('id')->all();

        return $cancelled === [] ? $query : $query->where(static fn ($q) => $q->whereNull('change_id')->orWhereNotIn('change_id', $cancelled));
    }

    /**
     * @param  list<string>  $employeeIds
     * @return Collection<int, Shift>
     */
    private function standingRows(array $employeeIds, ?string $through, bool $lock): Collection
    {
        $query = self::live(Shift::query(), $employeeIds);
        self::inEffect($query, $employeeIds, $through, $lock);

        return $query->orderBy('employee_id')->orderBy('effective_from')->orderBy('id')->get();
    }

    /**
     * @param  list<string>  $employeeIds
     * @return Collection<int, RosterShift>
     */
    private function weekdayRows(array $employeeIds, ?string $through, bool $lock): Collection
    {
        $query = self::live(RosterShift::query(), $employeeIds);
        self::inEffect($query, $employeeIds, $through, $lock);

        return $query->orderBy('employee_id')->orderBy('weekday')->orderBy('effective_from')->orderBy('id')->get();
    }

    /**
     * @param  list<string>  $employeeIds
     * @return Collection<int, RosterLocation>
     */
    private function placeRows(array $employeeIds, ?string $through, bool $lock): Collection
    {
        $query = self::live(RosterLocation::query(), $employeeIds);
        self::inEffect($query, $employeeIds, $through, $lock);

        return $query->orderBy('employee_id')->orderBy('weekday')->orderBy('effective_from')->orderBy('id')->get();
    }

    /**
     * Narrow to the employees' rows on or before `$through`. The callers
     * order them so the last row per employee (and weekday) is the one in
     * effect.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $employeeIds
     */
    private static function inEffect(Builder $query, array $employeeIds, ?string $through, bool $lock): void
    {
        $query->whereIn('employee_id', $employeeIds === [] ? [''] : $employeeIds);
        if ($through !== null) {
            $query->where(static fn ($w) => $w->whereNull('effective_from')->orWhere('effective_from', '<=', $through));
        }
        if ($lock) {
            $query->lockForUpdate();
        }
    }

    /**
     * Narrow to rows taking effect after `$date`, oldest first.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $employeeIds  empty for every employee
     */
    private static function pending(Builder $query, array $employeeIds, string $date, bool $lock): void
    {
        $query->where('effective_from', '>', $date);
        if ($employeeIds !== []) {
            $query->whereIn('employee_id', $employeeIds);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $query->orderBy('employee_id')->orderBy('effective_from')->orderBy('id');
    }

    /**
     * @template TRow
     *
     * @param  array<string, array<int, TRow>>  $rows
     * @param  callable(TRow): bool  $isDefault
     * @return array<string, array<int, TRow>>
     */
    private static function withoutDefaults(array $rows, callable $isDefault): array
    {
        foreach ($rows as $employeeId => $days) {
            $rows[$employeeId] = array_filter($days, static fn (mixed $row): bool => ! $isDefault($row));
            if ($rows[$employeeId] === []) {
                unset($rows[$employeeId]);
            }
        }

        return $rows;
    }
}
