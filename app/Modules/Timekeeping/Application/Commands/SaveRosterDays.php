<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Models\RosterDayOverride;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Timekeeping\Application\AssignmentWriter;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The dates picked on an employee's month in the Shift Roster, given one
 * shift and one place together — dates that need not follow one another
 * (Nov 18, 20 and 28–29). Each run of consecutive dates becomes one
 * temporary schedule, so the weekly schedule beneath is never touched.
 *
 *   - The shift: a pattern, custom hours, a Rest day, KEEP — the hours a
 *     deleted pattern left on the dates, kept as they are — or DEFAULT,
 *     back to the weekly schedule. The place: a site, Work from home, or
 *     DEFAULT — the weekly location. Both DEFAULT takes the dates'
 *     temporary schedules away and saves nothing new.
 *   - EVERY DATE WAS PICKED BY NAME, so a shift picked for a date the weekly
 *     schedule rests works that date: picking it is the explicit choice to
 *     work it, which a From–To period has to make separately because its
 *     dates are not each chosen.
 *   - THE PICKED DATES ARE REPLACED, AND ONLY THEY: a temporary schedule
 *     sharing one of them gives up the shared dates, keeps its others, and
 *     is cancelled with who and when — the calendar showed what each date
 *     had when it was picked. A run whose dates already read exactly as
 *     this would make them is left as it is, and an inactive site is kept
 *     only on dates that already have it.
 *   - DATED AND GUARDED through ONE `DatedChange` from the first date to the
 *     last: today or later, today only until today's cutoff, the night
 *     before left as it was, a payroll draft or a date already paid named
 *     in the review — all the dates are saved together or none is.
 *
 * Gate, locks and audit as the other roster writes: all_data + ROSTER;
 * pattern, employee, schedule rows; one best-effort audit row after commit.
 */
final class SaveRosterDays
{
    /** At most two months of dates at once: more than one month's calendar holds. */
    public const MAX_DATES = 62;

    public function __construct(private readonly EmployeeDirectory $employees, private readonly DatedChange $change) {}

    /**
     * @param  list<mixed>  $dates  'Y-m-d' strings, in any order
     * @param  string|array<array-key, mixed>  $shiftChoice  DEFAULT, REST, a pattern id, or custom hours (`CustomHours`)
     */
    public function handle(Access $actor, string $employeeId, array $dates, string|array $shiftChoice, string $locationChoice, ?string $confirmed = null): string
    {
        [$prepare, $write, $name, $dates] = $this->parts($actor, $employeeId, $dates, $shiftChoice, $locationChoice);
        $impact = $this->change->run($actor, [$employeeId], $dates[0], $dates[count($dates) - 1], $write, $confirmed, $prepare);
        $count = count($dates);

        return $impact->changed()
            ? 'Saved: '.$count.' '.($count === 1 ? 'day' : 'days')." for {$name}. The eDTR and payroll use it on ".($count === 1 ? 'that date.' : 'those dates.')
            : 'No changes to the schedule for '.($count === 1 ? 'that date.' : 'those dates.');
    }

    /**
     * What the save would do and affect, with nothing saved.
     *
     * @param  list<mixed>  $dates
     * @param  string|array<array-key, mixed>  $shiftChoice
     */
    public function preview(Access $actor, string $employeeId, array $dates, string|array $shiftChoice, string $locationChoice): ScheduleImpact
    {
        [$prepare, $write, , $dates] = $this->parts($actor, $employeeId, $dates, $shiftChoice, $locationChoice);

        return $this->change->preview($actor, [$employeeId], $dates[0], $dates[count($dates) - 1], $write, $prepare);
    }

    /**
     * The runs of consecutive dates in a sorted list: [first, last] each.
     *
     * @param  list<string>  $dates  sorted, unique
     * @return list<array{0: string, 1: string}>
     */
    private static function runs(array $dates): array
    {
        $runs = [];
        $count = count($dates);
        for ($i = 0; $i < $count; $i++) {
            $from = $until = $dates[$i];
            while ($i + 1 < $count && CalendarDays::next($until) === $dates[$i + 1]) {
                $until = $dates[++$i];
            }
            $runs[] = [$from, $until];
        }

        return $runs;
    }

    /**
     * The saved schedule that decides each date of the run — its hours, or
     * its place — as the roster resolves it: the latest save covering the
     * date that sets it. Null for a date none decides.
     *
     * @param  Collection<int, RosterDayOverride>  $saved  oldest save first
     * @return array<string, RosterDayOverride|null>
     */
    private static function deciders(Collection $saved, string $from, string $until, bool $place): array
    {
        $out = [];
        foreach (CalendarDays::each($from, $until) as $date) {
            $out[$date] = null;
            foreach ($saved as $row) {
                $sets = $place ? $row->location_kind !== null : $row->shift_data !== null;
                if ($sets && $row->date->format('Y-m-d') <= $date && RosterVersions::appliesThrough($row) >= $date) {
                    $out[$date] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * The one saved schedule deciding every date, or null when the dates
     * have different ones or none.
     *
     * @param  array<string, RosterDayOverride|null>  $deciders
     */
    private static function same(array $deciders): ?RosterDayOverride
    {
        $first = reset($deciders);
        if (! $first instanceof RosterDayOverride) {
            return null;
        }
        foreach ($deciders as $row) {
            if ($row !== $first) {
                return null;
            }
        }

        return $first;
    }

    /**
     * What each date of the run reads as — whether it works, its hours and
     * its place — the facts a change must alter to count as one. The copy's
     * weekday bookkeeping is left out: a pattern's copy for one date and the
     * weekly schedule's copy of the same pattern read the same.
     *
     * @return array<string, array{0: bool, 1: array<string, mixed>|null, 2: array{kind: string, site: ?string}|null}>
     */
    private static function resolved(string $employeeId, string $from, string $until): array
    {
        $out = [];
        foreach (app(ShiftRoster::class)->calendarFor([$employeeId], $from, $until)[$employeeId] ?? [] as $date => $day) {
            $shift = $day['works'] && $day['shift'] !== null
                ? array_diff_key($day['shift']->toArray(), array_flip(['work_days', 'weekday_shifts', 'date_shifts']))
                : null;
            $out[$date] = [$day['works'], $shift, $day['place']];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $dates
     * @param  string|array<array-key, mixed>  $shiftChoice
     * @return array{0: Closure(): void, 1: Closure(string, string): bool, 2: string, 3: non-empty-list<string>}
     */
    private function parts(Access $actor, string $employeeId, array $dates, string|array $shiftChoice, string $locationChoice): array
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $employee = $this->employees->forRoster($actor, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $picked = [];
        foreach ($dates as $date) {
            if (! is_string($date) || ! PayrollPeriod::isPayrollDate($date)) {
                throw new RuntimeException('Choose a valid calendar date.');
            }
            $picked[$date] = true;
        }
        $picked = array_keys($picked);
        sort($picked);
        if ($picked === []) {
            throw new RuntimeException('Pick at least one date.');
        }
        if (count($picked) > self::MAX_DATES) {
            throw new RuntimeException('Change at most '.self::MAX_DATES.' dates at once.');
        }
        if (count(CalendarDays::each($picked[0], $picked[count($picked) - 1])) > SaveAssignment::MAX_DAYS) {
            throw new RuntimeException('The dates must fall within one year.');
        }
        $shiftChoice = AssignmentWriter::choice($shiftChoice);
        $place = AssignmentWriter::place(trim($locationChoice));
        $name = EmployeeName::display($employee);
        $pattern = null;

        $prepare = static function () use ($shiftChoice, &$pattern): void {
            $pattern = AssignmentWriter::lockPattern($shiftChoice);
        };
        $write = static function (string $employeeId, string $start) use ($actor, $picked, $shiftChoice, $place, $name, &$pattern): bool {
            $dates = array_values(array_filter($picked, static fn (string $date): bool => $date >= $start));
            if ($dates === []) {
                return false;
            }
            $to = $dates[count($dates) - 1];
            $versions = app(RosterVersions::class);
            $versions->standingAsOf([$employeeId], $to, lock: true);
            $versions->weekdaysAsOf([$employeeId], $to, lock: true, withDefaults: true);
            $backToWeekly = $shiftChoice === 'DEFAULT' && $place['location_kind'] === null;
            $hours = null;
            $changed = false;
            foreach (self::runs($dates) as [$from, $until]) {
                $saved = AssignmentWriter::overlapping($employeeId, $from, $until);
                if ($backToWeekly) {
                    foreach ($saved as $row) {
                        AssignmentWriter::release($actor, $row, $from, $until);
                        $changed = true;
                    }

                    continue;
                }
                $hoursFrom = self::deciders($saved, $from, $until, place: false);
                $kept = self::same($hoursFrom);
                // KEEP repeats the copy a deleted pattern left, which only the
                // one saved schedule deciding every date of the run can give.
                if ($shiftChoice === 'KEEP' && $kept === null) {
                    throw new RuntimeException('That saved shift is no longer available. Pick a shift again.');
                }
                $hours = AssignmentWriter::hours($employeeId, $from, $shiftChoice, $pattern, $kept ?? $saved->last());
                // An inactive site stays only where every date already has it.
                if ($place['site_id'] !== null) {
                    $placedAt = self::deciders($saved, $from, $until, place: true);
                    $already = ! in_array(false, array_map(static fn (?RosterDayOverride $row): bool => $row?->site_id === $place['site_id'], $placedAt), true);
                    if (! app(OrganizationReferences::class)->siteAssignable($place['site_id'], $already ? $place['site_id'] : null)) {
                        throw new RuntimeException(AssignmentWriter::INACTIVE_SITE);
                    }
                }
                $includeRestDays = AssignmentWriter::includeRestDays($employeeId, $name, $from, $until, $hours, workOnRestDays: true);
                // Written in a savepoint and kept only when a date reads
                // differently: a run whose dates already have exactly this
                // shift and place is left as it is, however its saved
                // schedules are cut.
                $before = self::resolved($employeeId, $from, $until);
                DB::beginTransaction();
                try {
                    foreach ($saved as $row) {
                        AssignmentWriter::release($actor, $row, $from, $until);
                    }
                    AssignmentWriter::create($actor, $employeeId, $from, $until, $hours, $place, $includeRestDays, $saved->count() === 1 ? $saved->first()?->id : null);
                    $same = self::resolved($employeeId, $from, $until) === $before;
                } catch (Throwable $e) {
                    DB::rollBack();

                    throw $e;
                }
                if ($same) {
                    DB::rollBack();

                    continue;
                }
                DB::commit();
                $changed = true;
            }
            if (! $changed) {
                return false;
            }
            AssignmentWriter::assertPayable($employeeId, $start);
            $description = self::sentence($actor, $name, $dates, $backToWeekly ? null : $hours, $place);
            $event = count($dates) === 1 ? AuditEvent::ROSTER_DATE_SET : AuditEvent::ROSTER_PERIOD_SET;
            DB::afterCommit(static fn () => AuditService::record($actor, $event, 'roster_day_overrides', $employeeId, $description));

            return true;
        };

        return [$prepare, $write, $name, $picked];
    }

    /**
     * The sentence the audit trail keeps: "Ana Reyes · 2026-11-18,
     * 2026-11-20 to 2026-11-22 · Night · Work from home", or "… · follows
     * weekly schedule" when the dates went back to it.
     *
     * @param  list<string>  $dates
     * @param  array{shift_name: string|null, is_rest_day: bool}|null  $hours
     * @param  array{location_kind: string|null, site_id: string|null}  $place
     */
    private static function sentence(Access $actor, string $name, array $dates, ?array $hours, array $place): string
    {
        $spans = implode(', ', array_map(static fn (array $run): string => $run[0] === $run[1] ? $run[0] : "{$run[0]} to {$run[1]}", self::runs($dates)));
        if ($hours === null) {
            return "{$name} · {$spans} · follows weekly schedule";
        }
        $siteName = $place['site_id'] === null ? null : app(OrganizationDirectory::class)->sites($actor)->firstWhere('id', $place['site_id'])?->name;
        $where = $place['location_kind'] === null ? 'weekly location' : LocationKind::label($place['location_kind'], $siteName);

        return "{$name} · {$spans} · ".($hours['shift_name'] ?? 'weekly shift').' · '.$where;
    }
}
