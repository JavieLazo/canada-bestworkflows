<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Queries;

use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\ModuleCode;
use App\Models\RosterDayOverride;
use App\Models\RosterLocation;
use App\Models\RosterScheduleChange;
use App\Models\RosterShift;
use App\Models\Shift;
use App\Modules\IdentityAccess\Contracts\AccountNames;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\Views\WorkSiteView;
use App\Modules\Timekeeping\Contracts\Views\ScheduleChangeView;
use App\Modules\Timekeeping\Contracts\Views\TemporaryScheduleView;
use App\Modules\Timekeeping\Domain\Roster\CustomHours;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * An employee's schedule history for the Shift Roster: the dated changes
 * to the default shift and weekly schedule, and the temporary schedules —
 * upcoming, in effect, ended and cancelled — with who made and cancelled
 * each.
 *
 * Scoped like the roster list before anything is read: all_data + (ROSTER |
 * EDTR | PAYROLL) sees anyone's, otherwise only your own, an unlinked account
 * nothing. Detached views only; account names come from IdentityAccess and
 * site names from Organization.
 */
final class RosterHistory
{
    /** @return array{changes: list<ScheduleChangeView>, temporary: list<TemporaryScheduleView>, assumed_before: ?string, may_differ_through: ?string} */
    public function forEmployee(Access $actor, string $employeeId): array
    {
        return $this->histories($actor, [$employeeId])[$employeeId];
    }

    /**
     * The histories of several employees in one set of reads, however many
     * they are: changes, temporary schedules, their rows, sites and account
     * names each come from one query.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array{changes: list<ScheduleChangeView>, temporary: list<TemporaryScheduleView>, assumed_before: ?string, may_differ_through: ?string}>
     */
    private function histories(Access $actor, array $employeeIds): array
    {
        $today = LocalClock::todayKey();
        $allChanges = RosterScheduleChange::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->whereIn('employee_id', $employeeIds)->orderByDesc('effective_from')->orderByDesc('id')->get();
        $allTemporary = RosterDayOverride::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->whereIn('employee_id', $employeeIds)->orderByDesc('date')->orderByDesc('id')->get();

        $ids = $allChanges->pluck('id')->all();
        $standing = Shift::query()->whereIn('change_id', $ids)->orderBy('id')->get()->groupBy('change_id')->all();
        $weekdays = RosterShift::query()->whereIn('change_id', $ids)->orderBy('weekday')->orderBy('id')->get()->groupBy('change_id')->all();
        $places = RosterLocation::query()->whereIn('change_id', $ids)->orderBy('weekday')->orderBy('id')->get()->groupBy('change_id')->all();
        $sites = $places !== [] || $allTemporary->contains(static fn (RosterDayOverride $row): bool => $row->site_id !== null)
            ? app(OrganizationDirectory::class)->sites($actor)->keyBy('id') : collect();
        $accounts = $this->accounts($actor, [...$allChanges->pluck('created_by')->all(), ...$allChanges->pluck('cancelled_by')->all(),
            ...$allTemporary->pluck('created_by')->all(), ...$allTemporary->pluck('cancelled_by')->all()]);
        $canManage = $actor->mayReadAllData(ModuleCode::ROSTER);

        $out = [];
        foreach ($employeeIds as $employeeId) {
            $changes = $allChanges->where('employee_id', $employeeId);
            $temporary = $allTemporary->where('employee_id', $employeeId);
            $baseline = $changes->firstWhere('source', RosterScheduleChange::SOURCE_BASELINE);
            $out[$employeeId] = $this->history($changes, $temporary, $baseline, $today, $standing, $weekdays, $places, $sites, $accounts, $canManage);
        }

        return $out;
    }

    /**
     * One employee's history from rows already read.
     *
     * @param  EloquentCollection<int, RosterScheduleChange>  $changes
     * @param  EloquentCollection<int, RosterDayOverride>  $temporary
     * @param  array<int|string, EloquentCollection<int, Shift>>  $standing  by change id
     * @param  array<int|string, EloquentCollection<int, RosterShift>>  $weekdays  by change id
     * @param  array<int|string, EloquentCollection<int, RosterLocation>>  $places  by change id
     * @param  Collection<string, WorkSiteView>  $sites
     * @param  Collection<int, string>  $accounts
     * @return array{changes: list<ScheduleChangeView>, temporary: list<TemporaryScheduleView>, assumed_before: ?string, may_differ_through: ?string}
     */
    private function history(EloquentCollection $changes, EloquentCollection $temporary, ?RosterScheduleChange $baseline, string $today, array $standing, array $weekdays, array $places, Collection $sites, Collection $accounts, bool $canManage): array
    {
        $changeViews = [];
        foreach ($changes as $change) {
            if ($change->effective_from === null) {
                continue;
            }
            $from = $change->effective_from->format('Y-m-d');
            $cancelled = $change->status === RosterScheduleChange::STATUS_CANCELLED;
            $sets = [];
            foreach ($standing[$change->id] ?? [] as $row) {
                // A cleared shift's hours are filler, never shown.
                $sets[] = 'Default shift: '.($row->clearsShift() ? 'none (cleared)' : self::hours(DomainShift::fromArray($row->toArray())));
            }
            foreach ($weekdays[$change->id] ?? [] as $row) {
                $sets[] = HrisFormat::weekday($row->weekday).': '.($row->shift_data === null ? 'default shift' : ($row->is_rest_day ? 'Rest day' : $row->shift_name.' · '.self::hours(DomainShift::fromArray($row->shift_data))));
            }
            foreach ($places[$change->id] ?? [] as $row) {
                $sets[] = HrisFormat::weekday($row->weekday).': '.($row->kind === null ? 'assigned worksite' : LocationKind::label($row->kind, $row->site_id === null ? null : $sites->get($row->site_id)?->name));
            }
            $changeViews[] = new ScheduleChangeView(
                id: $change->id, employee_id: $change->employee_id, effective_from: $from, source: $change->source,
                state: $cancelled ? 'CANCELLED' : ($from > $today ? 'UPCOMING' : 'IN_EFFECT'),
                sets: $sets,
                made_by: $change->created_by === null ? null : $accounts->get($change->created_by),
                made_at: self::stamp($change->created_at),
                cancelled_by: $change->cancelled_by === null ? null : $accounts->get($change->cancelled_by),
                cancelled_at: self::stamp($change->cancelled_at),
                can_cancel: $canManage && ! $cancelled && $from >= $today && ! in_array($change->source, [RosterScheduleChange::SOURCE_PATTERN_EDIT, RosterScheduleChange::SOURCE_REST_DAYS], true),
            );
        }

        $temporaryViews = [];
        foreach ($temporary as $row) {
            $from = $row->date->format('Y-m-d');
            $through = RosterVersions::appliesThrough($row);
            $state = match (true) {
                $through < $from => 'CANCELLED',
                $through < $today => 'ENDED',
                $from > $today => 'UPCOMING',
                default => 'ACTIVE',
            };
            $temporaryViews[] = new TemporaryScheduleView(
                id: $row->id, employee_id: $row->employee_id, from: $from, to: ($row->end_date ?? $row->date)->format('Y-m-d'),
                applies_through: $through, state: $state,
                shift: $row->is_rest_day ? 'Rest day' : $row->shift_name,
                hours: $row->shift_data === null || $row->is_rest_day ? null : self::hours(DomainShift::fromArray($row->shift_data)),
                place: $row->location_kind === null ? null : LocationKind::label($row->location_kind, $row->site_id === null ? null : $sites->get($row->site_id)?->name),
                works_rest_days: $row->include_rest_days && $row->shift_data !== null && ! $row->is_rest_day,
                shift_choice: match (true) {
                    $row->is_rest_day => 'REST',
                    $row->shift_data === null => 'DEFAULT',
                    CustomHours::isCustom($row->shift_type_id, $row->shift_name, $row->shift_data) => 'CUSTOM',
                    default => $row->shift_type_id ?? 'KEEP',
                },
                location_choice: $row->location_kind === LocationKind::WFH ? LocationKind::WFH : ($row->site_id ?? 'DEFAULT'),
                made_by: $row->created_by === null ? null : $accounts->get($row->created_by),
                made_at: self::stamp($row->created_at),
                cancelled_by: $row->cancelled_by === null ? null : $accounts->get($row->cancelled_by),
                cancelled_at: self::stamp($row->cancelled_at),
                revision_of: $row->revision_of,
                can_change: $canManage && in_array($state, ['UPCOMING', 'ACTIVE'], true),
                custom_hours: ! $row->is_rest_day && $row->shift_data !== null && CustomHours::isCustom($row->shift_type_id, $row->shift_name, $row->shift_data)
                    ? CustomHours::input($row->shift_data) : null,
            );
        }

        return [
            'changes' => $changeViews,
            'temporary' => $temporaryViews,
            'assumed_before' => $baseline?->assumed_before?->format('Y-m-d'),
            'may_differ_through' => $baseline?->may_differ_through?->format('Y-m-d'),
        ];
    }

    /**
     * How many scheduled changes and temporary schedules are still ahead of
     * each employee — the roster row's "upcoming" line.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, int>
     */
    public function upcomingCounts(Access $actor, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $today = LocalClock::todayKey();
        $counts = [];
        $changes = RosterScheduleChange::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->whereIn('employee_id', $employeeIds)->where('status', RosterScheduleChange::STATUS_ACTIVE)->where('effective_from', '>', $today)
            ->get(['employee_id']);
        foreach ($changes as $change) {
            $counts[$change->employee_id] = ($counts[$change->employee_id] ?? 0) + 1;
        }
        $visible = RosterDayOverride::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->whereIn('employee_id', $employeeIds)->distinct()->pluck('employee_id')->all();
        foreach (app(RosterVersions::class)->assignments(array_values($visible), $today, null) as $row) {
            $counts[$row->employee_id] = ($counts[$row->employee_id] ?? 0) + 1;
        }

        return $counts;
    }

    /** "Flexible · 8 h per day", or "10:00 PM–6:00 AM". */
    private static function hours(DomainShift $shift): string
    {
        return $shift->isFlexible()
            ? 'Flexible · '.HrisFormat::hoursPerDay($shift->requiredHoursOrDefault())
            : HrisFormat::hm($shift->startTime).'–'.HrisFormat::hm($shift->endTime);
    }

    private static function stamp(?CarbonImmutable $at): ?string
    {
        return $at?->setTimezone(LocalClock::zone())->format('M d, Y, g:i A');
    }

    /**
     * @param  array<mixed>  $ids  account ids, nulls skipped
     * @return Collection<int, string> account id => name
     */
    private function accounts(Access $actor, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map(intval(...), array_filter($ids, static fn (mixed $id): bool => $id !== null))));

        return $ids === [] ? collect() : app(AccountNames::class)->list($actor, $ids)->mapWithKeys(static fn ($account): array => [(int) $account->id => (string) $account->name]);
    }
}
