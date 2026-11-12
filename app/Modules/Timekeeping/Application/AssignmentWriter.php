<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Attendance\ShiftShapePolicy;
use App\Models\RosterDayOverride;
use App\Models\ShiftType;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Timekeeping\Domain\Roster\CustomHours;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Domain\Roster\RosterSchedulePolicy;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Support\Access;
use App\Support\HrisFormat;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Writes temporary schedules — one date or a From–To period — for the
 * commands that create, change and cancel them (SaveRosterDate,
 * SaveRosterDays, SaveAssignment, EditAssignment, CancelAssignment). One
 * body, so the rules cannot drift between them:
 *
 *   - nothing is overwritten or deleted: an assignment that stops applying
 *     is cut at the last date it applied and cancelled with who and when,
 *     and what replaces it names it in `revision_of`;
 *   - a shift keeps rest days: its hours apply only on dates the weekly
 *     schedule works, unless work on rest days was chosen explicitly —
 *     as picking a date by name on the roster's month is;
 *   - an overlap with a saved assignment is a conflict to resolve, never a
 *     silent replacement (D1): only the assignments named in `$replace` give
 *     up the shared dates — or, for SaveRosterDays, the dates picked by
 *     name — and each keeps its own dates before and after;
 *   - an inactive site is refused for a place the dates do not already have.
 *
 * Every method assumes it runs inside `DatedChange`, which has locked the
 * employee and holds the change to the guard's rules.
 */
final class AssignmentWriter
{
    public const INACTIVE_SITE = 'That work site is inactive, so it cannot be chosen for a new assignment. Choose an active site, or make it active again in Site Settings.';

    /**
     * The place a choice names: DEFAULT (follow the weekly schedule), WFH or
     * a work_sites id.
     *
     * @return array{location_kind: string|null, site_id: string|null}
     */
    public static function place(string $choice): array
    {
        return match ($choice) {
            'DEFAULT', '' => ['location_kind' => null, 'site_id' => null],
            LocationKind::WFH => ['location_kind' => LocationKind::WFH, 'site_id' => null],
            default => app(OrganizationReferences::class)->siteExists($choice)
                ? ['location_kind' => LocationKind::ONSITE, 'site_id' => $choice]
                : throw new RuntimeException('That work site no longer exists.'),
        };
    }

    /**
     * A shift choice trimmed, or custom hours validated; refuses a blank one.
     *
     * @param  string|array<array-key, mixed>  $shiftChoice
     * @return string|array<array-key, mixed>
     */
    public static function choice(string|array $shiftChoice): string|array
    {
        if (is_array($shiftChoice)) {
            $error = CustomHours::error($shiftChoice);
            if ($error !== null) {
                throw new RuntimeException($error);
            }

            return $shiftChoice;
        }
        if (trim($shiftChoice) === '') {
            throw new RuntimeException('Pick a shift for these dates.');
        }

        return trim($shiftChoice);
    }

    /**
     * The pattern a shift choice names, read FOR UPDATE; null for DEFAULT,
     * REST, KEEP and custom hours.
     *
     * @param  string|array<array-key, mixed>  $shiftChoice
     */
    public static function lockPattern(string|array $shiftChoice): ?ShiftType
    {
        if (is_array($shiftChoice) || in_array($shiftChoice, ['DEFAULT', 'REST', 'KEEP'], true)) {
            return null;
        }
        $pattern = ShiftType::query()->whereKey($shiftChoice)->lockForUpdate()->first();
        if ($pattern === null) {
            throw new RuntimeException('That shift pattern no longer exists.');
        }

        return $pattern;
    }

    /**
     * The hours a choice gives the dates from `$from` through `$to`.
     *
     * @param  string|array<array-key, mixed>  $shiftChoice  custom hours arrive as `CustomHours`' draft
     * @param  RosterDayOverride|null  $kept  the assignment whose saved copy KEEP retains, or whose rest copy REST repeats
     * @return array{shift_type_id: string|null, shift_name: string|null, is_rest_day: bool, shift_data: array<string, mixed>|null}
     */
    public static function hours(string $employeeId, string $from, string|array $shiftChoice, ?ShiftType $pattern, ?RosterDayOverride $kept): array
    {
        if (is_array($shiftChoice)) {
            $error = CustomHours::error($shiftChoice);
            if ($error !== null) {
                throw new RuntimeException($error);
            }

            return ['shift_type_id' => null, 'shift_name' => CustomHours::NAME, 'is_rest_day' => false,
                'shift_data' => DomainShift::fromArray(array_replace(CustomHours::attributes($shiftChoice), ['work_days' => [CalendarDays::dayOfWeek($from)]]))->toArray()];
        }
        if ($shiftChoice === 'KEEP') {
            if ($kept === null || $kept->shift_data === null || $kept->is_rest_day || $kept->shift_type_id !== null) {
                throw new RuntimeException('That saved shift is no longer available. Pick a shift again.');
            }

            return ['shift_type_id' => null, 'shift_name' => $kept->shift_name, 'is_rest_day' => false, 'shift_data' => $kept->shift_data];
        }
        if ($shiftChoice === 'DEFAULT') {
            return ['shift_type_id' => null, 'shift_name' => null, 'is_rest_day' => false, 'shift_data' => null];
        }
        if ($shiftChoice === 'REST') {
            $effective = app(ShiftRoster::class)->forDate($employeeId, $from) ?? DomainShift::default();

            return ['shift_type_id' => null, 'shift_name' => 'Rest day', 'is_rest_day' => true,
                'shift_data' => $kept?->is_rest_day ? $kept->shift_data : array_replace(DomainShift::restDay($effective)->toArray(), ['shift_type_id' => null])];
        }
        assert($pattern instanceof ShiftType);
        $copy = array_replace(ShiftPolicy::attributesFromPattern($pattern), ['work_days' => [CalendarDays::dayOfWeek($from)]]);
        $error = ShiftShapePolicy::shapeError($copy);
        if ($error !== null) {
            throw new RuntimeException($error);
        }

        return ['shift_type_id' => $pattern->id, 'shift_name' => $pattern->name, 'is_rest_day' => false, 'shift_data' => DomainShift::fromArray($copy)->toArray()];
    }

    /**
     * Whether the hours may apply, and on which dates: FALSE keeps rest days,
     * TRUE is the explicit choice to work them. Refuses hours that would
     * apply on no date at all.
     *
     * @param  array{shift_data: array<string, mixed>|null, is_rest_day: bool}  $hours
     */
    public static function includeRestDays(string $employeeId, string $name, string $from, string $to, array $hours, bool $workOnRestDays): bool
    {
        if ($hours['shift_data'] === null || $hours['is_rest_day']) {
            return false;
        }
        $timeline = app(RosterVersions::class)->timelines([$employeeId], $to)[$employeeId];
        $working = 0;
        $resting = 0;
        foreach (CalendarDays::each($from, $to) as $date) {
            ShiftResolver::worksOnDate($date, $timeline->scheduleAt($date) ?? DomainShift::default()) ? $working++ : $resting++;
        }
        if ($working === 0 && ! $workOnRestDays) {
            throw new RuntimeException($from === $to
                ? HrisFormat::date($from)." is a rest day on {$name}'s schedule, and a shift change keeps rest days. To schedule work on it, choose \"Schedule work on this rest day\"."
                : 'Every date from '.HrisFormat::date($from).' to '.HrisFormat::date($to)." is a rest day on {$name}'s schedule, and a shift change keeps rest days. To schedule work on them, choose \"Schedule work on rest days\".");
        }

        return $workOnRestDays && $resting > 0;
    }

    /**
     * The saved assignments that share a date with [`$from`, `$to`], locked,
     * oldest save first — leaving out `$except`, the one being changed.
     *
     * @return Collection<int, RosterDayOverride>
     */
    public static function overlapping(string $employeeId, string $from, string $to, ?string $except = null): Collection
    {
        return app(RosterVersions::class)->assignments([$employeeId], $from, $to, lock: true)
            ->reject(static fn (RosterDayOverride $row): bool => $row->id === $except)->values();
    }

    /**
     * @param  Collection<int, RosterDayOverride>  $rows
     * @return list<array{id: string, from: string, to: string, shift: string, place: string, overlap_from: string, overlap_to: string}>
     */
    public static function describe(Access $actor, Collection $rows, string $from, string $to): array
    {
        $sites = $rows->contains(static fn (RosterDayOverride $row): bool => $row->site_id !== null)
            ? app(OrganizationDirectory::class)->sites($actor)->keyBy('id') : collect();

        return array_values($rows->map(static function (RosterDayOverride $row) use ($from, $to, $sites): array {
            $first = $row->date->format('Y-m-d');
            $last = RosterVersions::appliesThrough($row);

            return [
                'id' => $row->id, 'from' => $first, 'to' => $last,
                'shift' => $row->is_rest_day ? 'Rest day' : ($row->shift_name ?? 'Weekly shift'),
                'place' => $row->location_kind === null ? 'Weekly location' : LocationKind::label($row->location_kind, $row->site_id === null ? null : $sites->get($row->site_id)?->name),
                'overlap_from' => max($first, $from), 'overlap_to' => min($last, $to),
            ];
        })->all());
    }

    /**
     * Take [`$from`, `$to`] out of a saved assignment: it keeps the dates it
     * has before `$from`, is cancelled with who and when, and the dates it
     * has after `$to` continue as a new row naming it.
     */
    public static function release(Access $actor, RosterDayOverride $row, string $from, string $to): void
    {
        $last = RosterVersions::appliesThrough($row);
        if ($last > $to) {
            $tail = $row->replicate(['status', 'cut_after', 'cancelled_by', 'cancelled_at', 'created_by', 'created_at', 'updated_at']);
            $tail->fill(['date' => CalendarDays::next($to), 'end_date' => $last, 'revision_of' => $row->id]);
            $tail->created_by = RosterChanges::accountId($actor);
            $tail->save();
        }
        RosterChanges::cancelAssignment($actor, $row, CalendarDays::previous($from));
    }

    /**
     * @param  array{shift_type_id: string|null, shift_name: string|null, is_rest_day: bool, shift_data: array<string, mixed>|null}  $hours
     * @param  array{location_kind: string|null, site_id: string|null}  $place
     */
    public static function create(Access $actor, string $employeeId, string $from, string $to, array $hours, array $place, bool $includeRestDays, ?string $revisionOf = null): RosterDayOverride
    {
        $row = new RosterDayOverride(['employee_id' => $employeeId, 'date' => $from, 'end_date' => $to === $from ? null : $to,
            'include_rest_days' => $includeRestDays, 'revision_of' => $revisionOf] + $hours + $place);
        $row->created_by = RosterChanges::accountId($actor);
        $row->save();

        return $row;
    }

    /** "Oct 05, 2026", or "Oct 05, 2026 to Oct 16, 2026". */
    public static function span(string $from, string $to): string
    {
        return $from === $to ? HrisFormat::date($from) : HrisFormat::date($from).' to '.HrisFormat::date($to);
    }

    /** The roster as it will stand from `$from` must still have a working day each month payroll divides by. */
    public static function assertPayable(string $employeeId, string $from): void
    {
        $error = RosterSchedulePolicy::error(app(ShiftRoster::class)->scheduleForEmployee($employeeId, lock: true, asOf: $from));
        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }

    /**
     * The sentence the audit trail keeps: "Ana Reyes · Oct 5, 2026 to Oct 16,
     * 2026 · Night · Work from home".
     *
     * @param  array{shift_name: string|null, is_rest_day: bool}  $hours
     * @param  array{location_kind: string|null, site_id: string|null}  $place
     */
    public static function sentence(Access $actor, string $name, string $from, string $to, array $hours, array $place): string
    {
        $siteName = $place['site_id'] === null ? null : app(OrganizationDirectory::class)->sites($actor)->firstWhere('id', $place['site_id'])?->name;
        $where = $place['location_kind'] === null ? 'weekly location' : LocationKind::label($place['location_kind'], $siteName);

        return "{$name} · ".($from === $to ? $from : "{$from} to {$to}").' · '.($hours['shift_name'] ?? 'weekly shift').' · '.$where;
    }
}
