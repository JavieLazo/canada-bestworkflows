<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\RosterLocation;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Timekeeping\Application\DatedChange;
use App\Modules\Timekeeping\Application\RosterChanges;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Shift Roster's "Weekly locations" dialog — where one employee works on
 * each weekday (roster_locations, 2026_09_26_0001). No TypeScript
 * counterpart: the reference has one Assigned Worksite per employee and
 * nothing else.
 *
 * The input is the dialog's seven selects as they are: weekday 0–6 =>
 * DEFAULT ("Assigned worksite"), WFH, or a work_sites id (ONSITE at that
 * site). DATED AND GUARDED: each change is a new row from its effective date
 * under one `roster_schedule_changes` row per save — DEFAULT is a row with
 * no kind — so every earlier date keeps the place it had. It runs through
 * `DatedChange`: today or later, today only until today's cutoff, and never
 * disturbing an earlier date's record. The command re-validates all of it —
 * the weekday keys, the three forms, every site against Organization —
 * because the draft is a wire:model property the client can shape at will.
 * Weekdays the caller leaves out are left alone, so a partial map is a
 * partial edit, not a wipe.
 *
 * Gate, resolve, validate, write, audit — the AssignShift shape. The write is
 * one transaction over the employee's rows, read FOR UPDATE so two dialogs
 * saving the same week serialize rather than interleave (a no-op on sqlite;
 * MariaDB can show it). A save that changes nothing writes nothing and audits
 * nothing, like a same-pattern pick on the row menu. The audit is
 * best-effort AuditService after commit, the policy every roster write has;
 * widening RequiredAudit here would need its own decision.
 */
final class SaveRosterLocations
{
    public function __construct(
        private readonly EmployeeDirectory $employees,
        private readonly OrganizationReferences $sites,
    ) {}

    /**
     * @param  array<int|string, mixed>  $days  weekday 0–6 => LocationKind::DEFAULT | LocationKind::WFH | a work_sites id; typed wide because it is wire input, and anything else is refused
     * @return string the toast sentence
     */
    public function handle(Access $actor, string $employeeId, array $days, ?string $effectiveFrom = null, ?string $confirmed = null): string
    {
        [$write, $name] = $this->parts($actor, $employeeId, $days);
        $impact = app(DatedChange::class)->run($actor, [$employeeId], $effectiveFrom, null, $write, $confirmed);

        return $impact->changed()
            ? "Weekly locations saved for {$name}.".RosterChanges::takesEffect($impact->effective[$employeeId])
            : "No changes to {$name}'s weekly locations.";
    }

    /**
     * What the save would do — its date and what it affects — with nothing saved.
     *
     * @param  array<int|string, mixed>  $days
     */
    public function preview(Access $actor, string $employeeId, array $days, ?string $effectiveFrom = null): ScheduleImpact
    {
        return app(DatedChange::class)->preview($actor, [$employeeId], $effectiveFrom, null, $this->parts($actor, $employeeId, $days)[0]);
    }

    /**
     * The write, for `DatedChange` — here, or in `SaveRosterWeek` beside the
     * hours. Gated and validated before the closure exists.
     *
     * @param  array<int|string, mixed>  $days
     * @return array{0: Closure(string, string): bool, 1: string}
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

        $write = function (string $employeeId, string $from) use ($actor, $wanted, $name): bool {
            $rows = app(RosterVersions::class)->placesAsOf([$employeeId], $from, lock: true)[$employeeId] ?? [];

            $changed = false;
            $change = null;
            foreach ($wanted as $weekday => $entry) {
                $row = $rows[$weekday] ?? null;
                if ($entry === null && $row === null) {
                    continue;
                }
                if ($entry !== null && $row !== null && $row->kind === $entry['kind'] && $row->site_id === $entry['site_id']) {
                    continue;
                }
                // A changed weekday is a new assignment: a retired site is
                // withheld. A weekday left as it is never reaches this line.
                if ($entry !== null && $entry['site_id'] !== null && ! app(OrganizationReferences::class)->siteAssignable($entry['site_id'])) {
                    throw new RuntimeException('That work site is inactive, so it cannot be chosen for a new assignment. Choose an active site, or make it active again in Site Settings.');
                }
                $change ??= RosterChanges::open($actor, $employeeId, $from);
                RosterLocation::query()->create(['employee_id' => $employeeId, 'weekday' => $weekday, 'effective_from' => $from, 'change_id' => $change->id]
                    + ($entry ?? ['kind' => null, 'site_id' => null]));
                $changed = true;
            }
            if ($changed) {
                $description = $name.' · '.$this->describeWeek($actor, $employeeId, $from).RosterChanges::fromDate($from);
                DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::ROSTER_LOCATIONS_SET, 'roster_locations', $employeeId, $description));
            }

            return $changed;
        };

        return [$write, $name];
    }

    /**
     * The dialog's map as rows to write: weekday => ['kind', 'site_id'], or
     * null for DEFAULT (a row with no kind: back to the Assigned Worksite
     * from today). Throws on the first value that is not one of the three
     * forms.
     *
     * @param  array<int|string, mixed>  $days
     * @return array<int, array{kind: string, site_id: string|null}|null>
     */
    private function validated(array $days): array
    {
        $wanted = [];
        foreach ($days as $weekday => $value) {
            if (! is_int($weekday) || $weekday < 0 || $weekday > 6) {
                throw new RuntimeException('Weekday must be 0 (Sunday) to 6 (Saturday).');
            }
            if (! is_string($value)) {
                throw new RuntimeException('Pick a location for '.HrisFormat::weekday($weekday, short: false).'.');
            }
            $value = trim($value);
            if ($value === '' || $value === LocationKind::DEFAULT) {
                $wanted[$weekday] = null;
            } elseif ($value === LocationKind::WFH) {
                $wanted[$weekday] = ['kind' => LocationKind::WFH, 'site_id' => null];
            } elseif ($this->sites->siteExists($value)) {
                $wanted[$weekday] = ['kind' => LocationKind::ONSITE, 'site_id' => $value];
            } else {
                throw new RuntimeException('That work site no longer exists.');
            }
        }

        return $wanted;
    }

    /**
     * The audit sentence's week as it stands from `$from`, read from the
     * saved rows and recorded only after the outer commit: "Mon WFH · Tue Makati Head Office", or
     * "assigned worksite every day" once no row is left. Monday first, Sunday
     * last — how a week reads, not how it is numbered.
     */
    private function describeWeek(Access $actor, string $employeeId, string $from): string
    {
        $sites = app(OrganizationDirectory::class)->sites($actor)->keyBy('id');
        $rows = app(RosterVersions::class)->placesAsOf([$employeeId], $from)[$employeeId] ?? [];

        $parts = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $weekday) {
            $row = $rows[$weekday] ?? null;
            if ($row === null) {
                continue;
            }
            $place = $row->kind === LocationKind::WFH
                ? LocationKind::WFH
                : (string) ($sites->get((string) $row->site_id)->name ?? 'a deleted site');
            $parts[] = HrisFormat::weekday($weekday).' '.$place;
        }

        return $parts === [] ? 'assigned worksite every day' : implode(' · ', $parts);
    }
}
