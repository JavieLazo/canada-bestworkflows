<?php

declare(strict_types=1);

namespace App\Livewire\Roster;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Concerns\WithSorting;
use App\Livewire\Concerns\WithTableFilters;
use App\Livewire\Roster\Support\RosterRows;
use App\Livewire\Roster\Support\ScheduleReview;
use App\Models\Position;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Timekeeping\Application\AssignmentConflict;
use App\Modules\Timekeeping\Application\Commands\AssignShift;
use App\Modules\Timekeeping\Application\Commands\CancelAssignment;
use App\Modules\Timekeeping\Application\Commands\CancelScheduleChange;
use App\Modules\Timekeeping\Application\Commands\EditAssignment;
use App\Modules\Timekeeping\Application\Commands\SaveAssignment;
use App\Modules\Timekeeping\Application\Commands\SaveRosterWeek;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Application\Queries\RosterDates;
use App\Modules\Timekeeping\Application\Queries\RosterHistory;
use App\Modules\Timekeeping\Application\Queries\RosterLocations;
use App\Modules\Timekeeping\Application\Queries\RosterShifts;
use App\Modules\Timekeeping\Application\ScheduleImpact;
use App\Modules\Timekeeping\Application\ScheduleImpactPending;
use App\Modules\Timekeeping\Contracts\Views\RosterLocationView;
use App\Modules\Timekeeping\Contracts\Views\RosterShiftView;
use App\Modules\Timekeeping\Contracts\Views\ShiftView as Shift;
use App\Modules\Timekeeping\Contracts\Views\TemporaryScheduleView;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Modules\Workforce\Contracts\DirectoryEmployee as EmployeeProfile;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\ManilaClock;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Collection as EloquentCollection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Shift Roster — the port of src/app/(protected)/roster/roster-page.tsx and
 * add-shift-dialog.tsx, with the reference's two assignment dialogs plus
 * weekly schedules and date-specific changes.
 *
 * Which shift pattern each employee works: an employee × pattern table with
 * search, status/position/pattern filters, a per-row pattern menu and an
 * "Assign shift" dialog. Both write through `AssignShift`, which copies the
 * pattern's hours, grace and break onto the employee's own `shifts` row (one
 * per employee, an upsert); editing the pattern later in Records → Shifting
 * Settings re-copies it onto every row still linked to it (`SaveShiftType`).
 *
 * DELIBERATE DIVERGENCE: the reference's row control is a commit-on-change
 * <Select> (roster-page.tsx:300-318) — a wheel slip re-times an employee with
 * no confirmation. Here the row is a menu whose pick `pickPattern()` stages,
 * and only the confirm dialog's `confirmAssign()` writes. The header dialog
 * confirms through its button, as the reference's does.
 *
 * Weekly schedule combines weekday hours and places. A weekday shift pick
 * overrides the standing assignment; DEFAULT keeps it. Location defaults
 * still use Employee Records. SaveRosterWeek owns the combined transaction.
 * A temporary schedule — one date or a From–To period — overrides shift and
 * location without changing the recurring weekly schedule (SaveAssignment,
 * EditAssignment, CancelAssignment).
 *
 * SCHEDULES ARE DATED. Every change carries an Effective from date — blank
 * means the earliest it can start — and the commands hold it to the
 * safeguards: never the past, today only until today's cutoff, the night
 * before left as it was. This screen adds nothing to those rules; it
 * shows them:
 *   - REVIEW: when a change moves to the next date, reaches a payroll draft,
 *     flips a rest day inside a temporary schedule, or overlaps a saved one,
 *     the command answers with what it affects and nothing is saved. The
 *     review dialog lists it and `confirmReview()` saves with the token of
 *     exactly what was shown — a change whose impact moved is shown again.
 *   - HISTORY: each employee's scheduled changes and temporary schedules,
 *     with who made and cancelled them, and Cancel where it is still allowed.
 *   - TODAY: the table shows the schedule in effect today; what is still
 *     ahead is counted under the hours and read in the history. (There is
 *     no As of date, CSV export/import or Scheduled changes file.)
 *
 * Permissions, exactly as the reference:
 *  - the PAGE needs ROSTER (roster/layout.tsx) — route middleware;
 *  - the EMPLOYEE LIST is tiered like GET /api/employees: all_data plus any
 *    operational module reads the whole directory, otherwise your own row;
 *  - the SHIFT LIST is scoped like GET /api/shifts (ROSTER, EDTR, PAYROLL),
 *    and the LOCATION LIST the same way (`RosterLocations`);
 *  - a WRITE needs all_data + ROSTER (PUT /api/shifts/[employeeId]) and is
 *    re-checked inside the command, never trusted to the route — the
 *    locations write included, and its menu item is hidden without it.
 */
#[Layout('layouts.app')]
#[Title('Shift Roster')]
class RosterPage extends Component
{
    use WithSorting;
    use WithTableFilters;

    /** Status filter — 'ALL', a status name, or 'INACTIVE'. Plain filter state. */
    public string $status = 'ALL';

    /** Position filter — 'ALL' or a position name. Plain filter state. */
    public string $position = 'ALL';

    /** Pattern filter — 'ALL', a shift_type id, or 'UNASSIGNED'. Plain filter state. */
    public string $pattern = 'ALL';

    /**
     * The dialog's picked employee. Id-bearing, so #[Locked]: the selects
     * write it through `dialogEmployeeChanged()`, which re-validates the id
     * against the actor's visible directory before accepting it.
     */
    #[Locked]
    public string $dialogEmployeeId = '';

    /** The dialog's picked pattern. Id-bearing → #[Locked]; set via action only. */
    #[Locked]
    public string $dialogPatternId = '';

    /**
     * The row menu's staged employee. Id-bearing → #[Locked]: `pickPattern()`
     * alone sets it, after re-resolving the id against the actor's visible
     * directory, and `confirmAssign()` clears it whether or not the write
     * succeeds. A client-side Cancel or Escape leaves the pair staged, which
     * is harmless — the next pick overwrites it and nothing reads it but the
     * confirm dialog (the `Records\ShiftTypesPage::$deletingId` shape).
     */
    #[Locked]
    public string $confirmEmployeeId = '';

    /**
     * The row menu's staged pattern, paired with `$confirmEmployeeId` and set
     * by `pickPattern()` only after `ReferenceData::shiftType()` confirms the
     * id exists. Id-bearing → #[Locked].
     */
    #[Locked]
    public string $confirmPatternId = '';

    /**
     * The Weekly schedule dialog's employee. Id-bearing → #[Locked]:
     * `openLocations()` alone sets it, after re-resolving the id against the
     * actor's visible directory, and `saveLocations()` clears it once the
     * command has written. A client-side Cancel or Escape leaves it staged,
     * which is harmless — the next open overwrites it and nothing reads it
     * but the dialog (the `$confirmEmployeeId` shape).
     */
    #[Locked]
    public string $locationsEmployeeId = '';

    /**
     * The Weekly schedule dialog's seven location selects: weekday 0 Sunday … 6
     * Saturday => 'DEFAULT' (the Assigned Worksite), 'WFH', or a
     * work_sites id. NOT Locked on purpose — this is the wire:model draft
     * the selects edit, the sanctioned exception: `saveLocations()` shapes
     * it to seven strings and `SaveRosterWeek` re-validates every key
     * and value (the weekday range, the three forms, each site id against
     * Organization) before anything is written, so a client-edited entry is
     * refused, never trusted. Read as mixed for that reason.
     *
     * @var array<array-key, mixed>
     */
    public array $locationDays = [];

    /**
     * NOT Locked: weekday pattern selects are a wire:model draft. The command
     * re-validates every weekday and pattern id. KEEP retains a saved pattern
     * whose catalog entry was deleted; DEFAULT and REST are explicit choices.
     *
     * @var array<array-key, mixed>
     */
    public array $shiftDays = [];

    /** Only an actor-scoped row action selects the employee to change. */
    #[Locked]
    public string $dateEmployeeId = '';

    /** Date and choices are editable drafts, validated again by SaveRosterDate. */
    public string $scheduleDate = '';

    public string $dateShiftChoice = 'DEFAULT';

    public string $dateLocationChoice = 'DEFAULT';

    /** The last date of a temporary schedule; blank for a single date. A draft the command validates. */
    public string $scheduleEnd = '';

    /**
     * The saved temporary schedule the dialog is changing, or '' for a new
     * one. Id-bearing → #[Locked]: set only by loading a schedule the actor's
     * own history lists.
     */
    #[Locked]
    public string $editingAssignmentId = '';

    /**
     * Effective from, per dialog: the date a change starts, blank for the
     * earliest it can. Drafts — the commands refuse a past date and decide
     * whether today is still possible.
     */
    public string $assignFrom = '';

    public string $confirmFrom = '';

    public string $weekFrom = '';

    /** Which save the review dialog is holding: assign, row, week, dates, end-period or drop-change. */
    #[Locked]
    public string $reviewAction = '';

    /** The token of the impact shown in the review dialog; the save carries it back. */
    #[Locked]
    public string $reviewToken = '';

    /**
     * The saved temporary schedules the person is shown as overlapping, which
     * confirming replaces on the shared dates. Ids → #[Locked].
     *
     * @var list<string>
     */
    #[Locked]
    public array $reviewReplaceIds = [];

    /**
     * The review dialog's lines, as the command reported them.
     *
     * @var array{takes_effect: string, moved: string, conflicts: list<string>, rest_days: list<string>, drafts: list<string>}
     */
    #[Locked]
    public array $review = ScheduleReview::EMPTY;

    /** The employee whose schedule history is open. Id-bearing → #[Locked]. */
    #[Locked]
    public string $historyEmployeeId = '';

    /** The temporary schedule queued to be cancelled. Id-bearing → #[Locked]. */
    #[Locked]
    public string $endingAssignmentId = '';

    /** The scheduled change queued to be cancelled. Id-bearing → #[Locked]. */
    #[Locked]
    public string $droppingChangeId = '';

    /**
     * The explicit choice to schedule work on a date the weekly schedule
     * rests — a shift change alone keeps rest days. A draft like the two
     * selects beside it; SaveRosterDate decides what it means.
     */
    public bool $dateWorkOnRestDay = false;

    public function mount(): void
    {
        // useSort<SortKey>("employee") — roster-page.tsx:68.
        $this->sortKey = 'employee';
    }

    /** @return list<string> */
    protected function sortableKeys(): array
    {
        return ['id', 'employee', 'shift'];
    }

    /** The three FilterSelects reset the page on change (roster-page.tsx:192-231). */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPosition(): void
    {
        $this->resetPage();
    }

    public function updatedPattern(): void
    {
        $this->resetPage();
    }

    public function updatedAsOf(): void
    {
        $this->resetPage();
    }

    /** Open the Assign shift dialog clean (the source resets state on close). */
    public function openAssign(): void
    {
        $this->dialogEmployeeId = '';
        $this->dialogPatternId = '';
        $this->assignFrom = '';

        $this->modal('assign-shift')->show();
    }

    /**
     * The Assign shift dialog's employee is picked through the searchable
     * `kit.employee-picker`, so nobody scrolls the whole directory to find
     * one. The picker announces a pick as this event, which the client can
     * dispatch too, so the id goes through the same re-check as any other
     * pick.
     */
    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        if ($name !== 'assign-employee') {
            return;
        }

        $this->dialogEmployeeChanged($id);
    }

    /**
     * Commit the dialog's employee pick. The id arrives from the client, so it
     * is re-resolved against the actor-visible directory — an id outside it,
     * or an inactive employee (the picker offers actives only), is dropped on
     * the floor, not selected.
     */
    public function dialogEmployeeChanged(string $id): void
    {
        if ($id === '') {
            $this->dialogEmployeeId = '';

            return;
        }

        $employee = $this->visibleEmployees(app(Access::class))->firstWhere('id', $id);

        if ($employee !== null && $employee->is_active) {
            $this->dialogEmployeeId = (string) $employee->id;
        }
    }

    /** Commit the dialog's pattern pick, re-validated against shift_types. */
    public function dialogPatternChanged(string $id): void
    {
        if ($id === '') {
            $this->dialogPatternId = '';

            return;
        }

        if (app(ReferenceData::class)->shiftType(app(Access::class), $id) !== null) {
            $this->dialogPatternId = $id;
        }
    }

    /** The dialog's save (add-shift-dialog.tsx:71-95). */
    public function dialogSave(): void
    {
        if ($this->dialogEmployeeId === '' || $this->dialogPatternId === '') {
            Flux::toast(variant: 'danger', text: 'Pick an employee and a shift pattern.');

            return;
        }

        $this->guarded('assign');
    }

    /**
     * Stage a row-menu pick and ask for confirmation.
     *
     * DELIBERATE DIVERGENCE from the commit-on-change <Select> at
     * roster-page.tsx:300-318: nothing is written here. Both ids are client
     * arguments, so each is re-resolved before it is staged — the employee
     * through the actor-scoped single-row lookup (an id outside the visible
     * directory, or any id for a non-all_data account with no employee link,
     * is dropped on the floor), the pattern against `shift_types`. Picking the pattern the
     * row already stores is a no-op: the menu shows it checked and without a
     * click, and this guard covers a stale menu. Re-applying the same pattern
     * on purpose stays possible through the header dialog.
     */
    public function pickPattern(string $employeeId, string $patternId): void
    {
        if ($employeeId === '' || $patternId === '') {
            return;
        }

        $access = app(Access::class);

        if (app(OperationalDirectory::class)->find($access, $employeeId) === null) {
            return;
        }

        if (app(ReferenceData::class)->shiftType($access, $patternId) === null) {
            return;
        }

        if (app(ReferenceData::class)->shift($access, $employeeId)?->shift_type_id === $patternId) {
            return;
        }

        $this->confirmEmployeeId = $employeeId;
        $this->confirmPatternId = $patternId;
        $this->confirmFrom = '';

        $this->modal('confirm-assign')->show();
    }

    /**
     * The confirm dialog's Assign, behind the all_data + ROSTER gate the
     * command enforces. The dialog closes on success and failure alike: on
     * failure the row is unchanged and the toast says why. The staged pair is
     * kept only while the review dialog holds the change, and cleared the
     * moment it is saved or refused, so a second click writes nothing.
     */
    public function confirmAssign(): void
    {
        if ($this->confirmEmployeeId === '' || $this->confirmPatternId === '') {
            return;
        }

        $this->guarded('row');
    }

    /**
     * Open the Weekly schedule dialog for one row. The id is a client
     * argument, so it is re-resolved through the actor-scoped single-row
     * lookup first (an id outside the visible directory is dropped on the
     * floor, the `pickPattern()` rule); then the seven selects are seeded
     * from the stored locations and shifts. DEFAULT retains the standing
     * assignment; a saved copy survives deletion of its source pattern.
     */
    public function openLocations(string $employeeId): void
    {
        if ($employeeId === '') {
            return;
        }

        $access = app(Access::class);

        if (app(OperationalDirectory::class)->find($access, $employeeId) === null) {
            return;
        }

        $days = array_fill(0, 7, LocationKind::DEFAULT);
        foreach (app(RosterLocations::class)->weekly($access, $employeeId) as $weekday => $view) {
            $days[$weekday] = $view->kind === LocationKind::WFH
                ? LocationKind::WFH
                : ($view->site_id ?? LocationKind::DEFAULT);
        }

        $this->locationsEmployeeId = $employeeId;
        $this->weekFrom = '';
        $this->locationDays = $days;
        $this->shiftDays = array_fill(0, 7, 'DEFAULT');
        foreach (app(RosterShifts::class)->weekly($access, $employeeId) as $weekday => $view) {
            $this->shiftDays[$weekday] = $view->is_rest_day ? 'REST' : ($view->shift_type_id ?? 'KEEP');
        }

        $this->modal('weekly-locations')->show();
    }

    /**
     * The Weekly schedule dialog's Save — `performAssign()`'s shape for the
     * other write this screen has. A fresh Access is gated here so a refused
     * actor gets the toast at once; the command gates again (all_data +
     * ROSTER), re-resolves the employee, re-validates every select and
     * audits — the write lives there, not here. The draft is shaped to
     * exactly seven strings first: a missing, blank or non-string entry
     * reads DEFAULT (keep the Assigned Worksite), and everything else —
     * 'WFH' or a site id — passes through for the command to judge. The
     * dialog stays open on failure so the picks are not lost, and closes
     * only once the command has answered.
     */
    public function saveLocations(): void
    {
        $employeeId = $this->locationsEmployeeId;

        if ($employeeId === '') {
            return;
        }

        $access = app(Access::class);

        if (! $access->mayReadAllData(ModuleCode::ROSTER)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');

            return;
        }

        $this->guarded('week');
    }

    /** Start with tomorrow; a temporary schedule never rewrites the recurring week. */
    public function openDateSchedule(string $employeeId): void
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::ROSTER)
            || app(OperationalDirectory::class)->find($access, $employeeId) === null) {
            return;
        }

        $this->dateEmployeeId = $employeeId;
        $this->scheduleDate = CalendarDays::next(ManilaClock::todayKey());
        $this->updatedScheduleDate();
        $this->modal('date-schedule')->show();
    }

    /**
     * Every first-date pick reloads what is saved for it, not the preceding
     * date's draft: a temporary schedule that starts on the date and still
     * applies is loaded to be changed; any other date starts a new one.
     */
    public function updatedScheduleDate(): void
    {
        $this->dateShiftChoice = 'DEFAULT';
        $this->dateLocationChoice = 'DEFAULT';
        $this->dateWorkOnRestDay = false;
        $this->scheduleEnd = '';
        $this->editingAssignmentId = '';
        if ($this->dateEmployeeId === '') {
            return;
        }
        foreach ($this->temporarySchedules(app(Access::class), $this->dateEmployeeId) as $saved) {
            if ($saved->from === $this->scheduleDate) {
                $this->load($saved);

                return;
            }
        }
    }

    /**
     * Load one saved temporary schedule into the dialog to change it. The id
     * is a client argument, so it is matched against the schedules the
     * actor's own history lists for the staged employee; any other id is
     * dropped on the floor.
     */
    public function editTemporary(string $id): void
    {
        $access = app(Access::class);
        if ($this->dateEmployeeId === '' || ! $access->mayReadAllData(ModuleCode::ROSTER)) {
            return;
        }
        foreach ($this->temporarySchedules($access, $this->dateEmployeeId) as $saved) {
            if ($saved->id === $id) {
                $this->load($saved);

                return;
            }
        }
    }

    /** Back to a new temporary schedule, keeping the employee. */
    public function newTemporary(): void
    {
        $this->scheduleDate = CalendarDays::next(ManilaClock::todayKey());
        $this->updatedScheduleDate();
    }

    public function saveDateSchedule(): void
    {
        if ($this->dateEmployeeId === '') {
            return;
        }

        $this->guarded('dates');
    }

    /** Cancel a saved temporary schedule from the dialog's list or the history. */
    public function cancelTemporary(string $id): void
    {
        if ($id === '') {
            return;
        }
        $this->endingAssignmentId = $id;
        $this->guarded('end-period');
    }

    /**
     * Open one employee's schedule history. The id is a client argument,
     * re-resolved through the actor-scoped lookup first; the history itself
     * is scoped again by its own query.
     */
    public function openHistory(string $employeeId): void
    {
        if ($employeeId === '' || app(OperationalDirectory::class)->find(app(Access::class), $employeeId) === null) {
            return;
        }

        $this->historyEmployeeId = $employeeId;
        $this->modal('schedule-history')->show();
    }

    /** Cancel a default-shift or weekly change that has not taken effect. */
    public function cancelChange(string $id): void
    {
        if ($id === '') {
            return;
        }
        $this->droppingChangeId = $id;
        $this->guarded('drop-change');
    }

    /**
     * The review dialog's Confirm: the held save runs again, carrying the
     * token of what was shown and the overlaps the person agreed to replace.
     * Both are copied and cleared first, so a second click carries nothing.
     */
    public function confirmReview(): void
    {
        $action = $this->reviewAction;
        $token = $this->reviewToken;
        $replace = $this->reviewReplaceIds;
        $this->reviewAction = '';
        $this->reviewToken = '';
        $this->reviewReplaceIds = [];
        if ($action === '' || $token === '') {
            return;
        }

        $this->modal('schedule-review')->close();
        $this->guarded($action, $token, $replace);
    }

    /**
     * Run one of this screen's saves behind a fresh Access, and answer
     * whether it was saved. A refusal toasts the command's own sentence. A
     * change that needs reviewing opens the review dialog and keeps the
     * dialog's draft, so nothing the person picked is lost.
     *
     * @param  list<string>  $replace
     */
    private function guarded(string $action, ?string $confirmed = null, array $replace = []): bool
    {
        $access = app(Access::class);
        if (! $access->mayReadAllData(ModuleCode::ROSTER)) {
            Flux::toast(variant: 'danger', text: 'Your role cannot manage organization-wide data.');
            $this->settle($action, saved: false);

            return false;
        }

        try {
            try {
                $message = $this->perform($access, $action, $confirmed, $replace, preview: false);
            } catch (AssignmentConflict $conflict) {
                // Shown with what replacing them would affect, in one step.
                $ids = array_column($conflict->conflicts, 'id');
                $impact = $this->perform($access, $action, null, $ids, preview: true);
                assert($impact instanceof ScheduleImpact);
                $this->hold($action, $impact, $conflict->conflicts);

                return false;
            } catch (ScheduleImpactPending $pending) {
                $this->hold($action, $pending->impact, []);

                return false;
            }
        } catch (QueryException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: 'The schedule could not be saved. Please try again.');
            $this->settle($action, saved: false);

            return false;
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->settle($action, saved: false);

            return false;
        }

        assert(is_string($message));
        $this->settle($action, saved: true);
        Flux::toast(variant: 'success', text: $message);

        return true;
    }

    /**
     * The command behind each save. `preview` answers what it would affect
     * and saves nothing.
     *
     * @param  list<string>  $replace
     */
    private function perform(Access $access, string $action, ?string $confirmed, array $replace, bool $preview): string|ScheduleImpact
    {
        $from = static fn (string $draft): ?string => trim($draft) === '' ? null : trim($draft);
        $end = $from($this->scheduleEnd);

        switch ($action) {
            case 'assign':
                return $preview
                    ? app(AssignShift::class)->preview($access, $this->dialogEmployeeId, $this->dialogPatternId, $from($this->assignFrom))
                    : app(AssignShift::class)->handle($access, $this->dialogEmployeeId, $this->dialogPatternId, $from($this->assignFrom), $confirmed);
            case 'row':
                return $preview
                    ? app(AssignShift::class)->preview($access, $this->confirmEmployeeId, $this->confirmPatternId, $from($this->confirmFrom))
                    : app(AssignShift::class)->handle($access, $this->confirmEmployeeId, $this->confirmPatternId, $from($this->confirmFrom), $confirmed);
            case 'week':
                // Shaped to exactly seven strings: a missing, blank or
                // non-string entry reads DEFAULT, and everything else passes
                // through for the command to judge.
                $days = [];
                foreach (range(0, 6) as $weekday) {
                    $value = $this->locationDays[$weekday] ?? LocationKind::DEFAULT;
                    $value = is_string($value) ? trim($value) : '';
                    $days[$weekday] = $value === '' ? LocationKind::DEFAULT : $value;
                }

                return $preview
                    ? app(SaveRosterWeek::class)->preview($access, $this->locationsEmployeeId, $this->shiftDays, $days, $from($this->weekFrom))
                    : app(SaveRosterWeek::class)->handle($access, $this->locationsEmployeeId, $this->shiftDays, $days, $from($this->weekFrom), $confirmed);
            case 'dates':
                if ($this->editingAssignmentId === '') {
                    return $preview
                        ? app(SaveAssignment::class)->preview($access, $this->dateEmployeeId, $this->scheduleDate, $end, $this->dateShiftChoice, $this->dateLocationChoice, $this->dateWorkOnRestDay, $replace)
                        : app(SaveAssignment::class)->handle($access, $this->dateEmployeeId, $this->scheduleDate, $end, $this->dateShiftChoice, $this->dateLocationChoice, $this->dateWorkOnRestDay, $replace, $confirmed);
                }
                // Both back on the weekly schedule is the dialog's way of
                // removing a saved one.
                if ($this->dateShiftChoice === 'DEFAULT' && $this->dateLocationChoice === 'DEFAULT') {
                    return $preview
                        ? app(CancelAssignment::class)->preview($access, $this->editingAssignmentId)
                        : app(CancelAssignment::class)->handle($access, $this->editingAssignmentId, $confirmed);
                }

                return $preview
                    ? app(EditAssignment::class)->preview($access, $this->editingAssignmentId, $this->scheduleDate, $end, $this->dateShiftChoice, $this->dateLocationChoice, $this->dateWorkOnRestDay, $replace)
                    : app(EditAssignment::class)->handle($access, $this->editingAssignmentId, $this->scheduleDate, $end, $this->dateShiftChoice, $this->dateLocationChoice, $this->dateWorkOnRestDay, $replace, $confirmed);
            case 'end-period':
                return $preview
                    ? app(CancelAssignment::class)->preview($access, $this->endingAssignmentId)
                    : app(CancelAssignment::class)->handle($access, $this->endingAssignmentId, $confirmed);
            case 'drop-change':
                return $preview
                    ? app(CancelScheduleChange::class)->preview($access, $this->droppingChangeId)
                    : app(CancelScheduleChange::class)->handle($access, $this->droppingChangeId, $confirmed);
            default:
                throw new \RuntimeException('That change is no longer open. Start it again.');
        }
    }

    /**
     * Hold a save for review: what it affects, in the person's words.
     *
     * @param  list<array{id: string, from: string, to: string, shift: string, place: string, overlap_from: string, overlap_to: string}>  $conflicts
     */
    private function hold(string $action, ScheduleImpact $impact, array $conflicts): void
    {
        $this->reviewAction = $action;
        $this->reviewToken = $impact->token();
        $this->reviewReplaceIds = array_column($conflicts, 'id');
        $this->review = ScheduleReview::lines($impact, $conflicts);

        foreach (['assign-shift', 'confirm-assign', 'weekly-locations', 'date-schedule', 'schedule-history'] as $open) {
            $this->modal($open)->close();
        }
        $this->modal('schedule-review')->show();
    }

    /** Clear what a finished save staged, and close its dialog once it is saved. */
    private function settle(string $action, bool $saved): void
    {
        switch ($action) {
            case 'assign':
                if ($saved) {
                    $this->dialogEmployeeId = '';
                    $this->dialogPatternId = '';
                    $this->assignFrom = '';
                    $this->modal('assign-shift')->close();
                }
                break;
            case 'row':
                $this->confirmEmployeeId = '';
                $this->confirmPatternId = '';
                $this->confirmFrom = '';
                $this->modal('confirm-assign')->close();
                break;
            case 'week':
                if ($saved) {
                    $this->locationsEmployeeId = '';
                    $this->locationDays = [];
                    $this->shiftDays = [];
                    $this->weekFrom = '';
                    $this->modal('weekly-locations')->close();
                }
                break;
            case 'dates':
                if ($saved) {
                    $this->dateEmployeeId = '';
                    $this->editingAssignmentId = '';
                    $this->scheduleEnd = '';
                    $this->modal('date-schedule')->close();
                }
                break;
            case 'end-period':
                $this->endingAssignmentId = '';
                if ($saved && $this->dateEmployeeId !== '') {
                    $this->newTemporary();
                }
                break;
            case 'drop-change':
                $this->droppingChangeId = '';
                break;
        }
    }

    private function load(TemporaryScheduleView $saved): void
    {
        $this->editingAssignmentId = $saved->id;
        $this->scheduleDate = $saved->from;
        $this->scheduleEnd = $saved->applies_through === $saved->from ? '' : $saved->applies_through;
        $this->dateShiftChoice = $saved->shift_choice;
        $this->dateLocationChoice = $saved->location_choice;
        $this->dateWorkOnRestDay = $saved->works_rest_days;
    }

    /**
     * The employee's temporary schedules that can still change: upcoming or
     * running, first date first.
     *
     * @return list<TemporaryScheduleView>
     */
    private function temporarySchedules(Access $access, string $employeeId): array
    {
        $open = array_values(array_filter(
            app(RosterHistory::class)->forEmployee($access, $employeeId)['temporary'],
            static fn (TemporaryScheduleView $row): bool => in_array($row->state, ['UPCOMING', 'ACTIVE'], true),
        ));
        usort($open, static fn (TemporaryScheduleView $a, TemporaryScheduleView $b): int => [$a->from, $a->id] <=> [$b->from, $b->id]);

        return $open;
    }

    public function render(): View
    {
        $access = app(Access::class);

        $employees = $this->visibleEmployees($access);

        // GET /api/shifts scoping: all_data + (ROSTER | EDTR | PAYROLL) reads
        // the whole roster, otherwise only the row you are rostered on — and an
        // account with no employee link reads NONE (api/shifts/route.ts:11-17).
        // The schedule in effect today, since rows are dated.
        $shifts = app(ReferenceData::class)->shifts($access)
            ->keyBy('employee_id');

        $patterns = app(ReferenceData::class)->shiftTypes($access, ordered: true);

        // Records Management's positions, plus any job title still in use
        // (roster-page.tsx:71-75).
        $positionNames = app(OrganizationDirectory::class)->positions($access, false);

        foreach ($employees as $employee) {
            if ($employee->position !== null && $employee->position !== '') {
                $positionNames[] = $employee->position;
            }
        }

        $positions = (new Collection($positionNames))
            ->unique()
            ->sort(Hris::compareText(...))
            ->values();

        // Records Management's statuses, plus any an employee still carries
        // (roster-page.tsx:78-84).
        $statusNames = app(EmployeeRecords::class)->statuses($access, false);

        foreach ($employees as $employee) {
            if ($employee->employment_status !== '') {
                $statusNames[] = Hris::employmentStatusLabel($employee->employment_status);
            }
        }

        $statuses = (new Collection($statusNames))
            ->unique()
            ->sort(static fn (string $a, string $b): int => strcasecmp($a, $b))
            ->values();

        // Sites by id — the Location column's names, the dialog's options and
        // its "Assigned worksite (…)" caption — and the page's weekly
        // locations in ONE query, scoped like the shift list; never a lookup
        // per row.
        $sites = app(OrganizationDirectory::class)->sites($access)->keyBy('id');
        $employeeIds = array_values($employees->map(static fn (EmployeeProfile $e): string => (string) $e->id)->all());
        $weekly = app(RosterLocations::class)->weeklyForEmployees($access, $employeeIds);
        $weeklyShifts = app(RosterShifts::class)->weeklyForEmployees($access, $employeeIds);
        $canEditLocations = $access->mayReadAllData(ModuleCode::ROSTER);

        $rows = $this->paginateClamped($this->buildRows($employees, $shifts, $weekly, $weeklyShifts));

        // The dialog's derived state (add-shift-dialog.tsx:51-64). The list
        // itself is the kit picker's, searched server-side; the page only
        // resolves the pick and what it already works.
        $dialogSelected = $this->dialogEmployeeId === ''
            ? null
            : $employees->firstWhere('id', $this->dialogEmployeeId);
        $dialogExisting = $this->dialogEmployeeId === '' ? null : $shifts->get($this->dialogEmployeeId);
        $dialogChosen = $this->dialogPatternId === ''
            ? null
            : $patterns->firstWhere('id', $this->dialogPatternId);

        // The confirm dialog's derived state, read from the collections already
        // in hand — no extra query for a pair pickPattern() has just validated.
        $confirmEmployee = $this->confirmEmployeeId === ''
            ? null
            : $employees->firstWhere('id', $this->confirmEmployeeId);
        $confirmExisting = $this->confirmEmployeeId === '' ? null : $shifts->get($this->confirmEmployeeId);
        $confirmPattern = $this->confirmPatternId === ''
            ? null
            : $patterns->firstWhere('id', $this->confirmPatternId);

        // The dialog derives rest-day hints from the draft weekday choices,
        // falling back to the standing pattern. Places remain editable on
        // rest days for an employee who works one anyway.
        $locationsEmployee = $this->locationsEmployeeId === ''
            ? null
            : $employees->firstWhere('id', $this->locationsEmployeeId);
        $locationsAssignedSite = $locationsEmployee !== null && $locationsEmployee->work_site_id !== null
            ? $sites->get($locationsEmployee->work_site_id)
            : null;
        $draftWorks = [];
        foreach ($this->shiftDays as $weekday => $choice) {
            if ($choice === 'REST') {
                $draftWorks[(int) $weekday] = false;
            } elseif (is_string($choice) && $choice !== 'DEFAULT') {
                $draftWorks[(int) $weekday] = true;
            }
        }
        $locationsRestDays = $locationsEmployee === null
            ? []
            : array_values(array_diff(
                [0, 1, 2, 3, 4, 5, 6],
                Hris::workingWeekdays($shifts->get($locationsEmployee->id)?->work_days, $draftWorks),
            ));

        return view('livewire.roster.roster-page', [
            'rows' => $rows,
            'patterns' => $patterns,
            'patternNames' => $patterns->pluck('name', 'id'),
            'statuses' => $statuses,
            'positions' => $positions,
            'dialogSelected' => $dialogSelected,
            'dialogExisting' => $dialogExisting,
            'dialogChosen' => $dialogChosen,
            'confirmEmployee' => $confirmEmployee,
            'confirmExisting' => $confirmExisting,
            'confirmPattern' => $confirmPattern,
            'sites' => $sites,
            'canEditLocations' => $canEditLocations,
            'locationsEmployee' => $locationsEmployee,
            'locationsAssignedSite' => $locationsAssignedSite,
            // The site each weekday already names: an inactive one stays
            // offered there, and nowhere else.
            'locationsSaved' => array_map(static fn (RosterLocationView $row): ?string => $row->site_id, $weekly[$this->locationsEmployeeId] ?? []),
            'locationsRestDays' => $locationsRestDays,
            'locationsBaseShift' => $shifts->get($this->locationsEmployeeId),
            'locationsSavedShifts' => $weeklyShifts[$this->locationsEmployeeId] ?? [],
            'dateEmployee' => $employees->firstWhere('id', $this->dateEmployeeId),
            'savedSchedules' => $this->dateEmployeeId === '' ? [] : $this->temporarySchedules($access, $this->dateEmployeeId),
            'editingSchedule' => $this->editingAssignmentId === '' ? null
                : collect($this->dateEmployeeId === '' ? [] : $this->temporarySchedules($access, $this->dateEmployeeId))->firstWhere('id', $this->editingAssignmentId),
            'effectiveDate' => $this->dateEmployeeId === '' ? null : app(RosterDates::class)->effective($access, $this->dateEmployeeId, $this->scheduleDate),
            'upcoming' => app(RosterHistory::class)->upcomingCounts($access, $employeeIds),
            'historyEmployee' => $employees->firstWhere('id', $this->historyEmployeeId),
            'history' => $this->historyEmployeeId === '' ? null : app(RosterHistory::class)->forEmployee($access, $this->historyEmployeeId),
        ]);
    }

    /**
     * Filter, join and sort — the in-memory pipeline of roster-page.tsx:86-128,
     * kept in PHP so blanks-last sorting and the desc `reverse()` behave
     * exactly as the source's array pipeline did.
     *
     * @param  EloquentCollection<int, EmployeeProfile>  $employees
     * @param  Collection<array-key, Shift>  $shifts
     * @param  array<string, array<int, RosterLocationView>>  $weekly  employee id => weekday => view; no entry means no row
     * @param  array<string, array<int, RosterShiftView>>  $weeklyShifts
     * @return list<array{employee: EmployeeProfile, shift: Shift|null, locations: array<int, RosterLocationView>, weeklyShifts: array<int, RosterShiftView>}>
     */
    private function buildRows(EloquentCollection $employees, Collection $shifts, array $weekly, array $weeklyShifts): array
    {
        // The filter/join/sort pipeline lives in RosterRows.
        return RosterRows::build($employees, $shifts, $weekly, $weeklyShifts, $this->search, $this->status, $this->position, $this->pattern, $this->sortKey, $this->sortDir);
    }

    /**
     * The three read tiers of GET /api/employees (api/employees/route.ts:36-70),
     * collapsed to what this screen renders: the full and operational tiers
     * show the same roster columns, so any of the widening modules reads the
     * whole directory; otherwise the actor's OWN row — and NO rows for an
     * account with no employee link, never every row.
     *
     * @return EloquentCollection<int, EmployeeProfile>
     */
    private function visibleEmployees(Access $access): EloquentCollection
    {
        return app(OperationalDirectory::class)->list($access);
    }
}
