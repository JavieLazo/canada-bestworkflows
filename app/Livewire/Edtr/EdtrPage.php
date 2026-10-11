<?php

declare(strict_types=1);

namespace App\Livewire\Edtr;

use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\DtrSummary;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Livewire\Edtr\Support\CorrectPunches;
use App\Livewire\Edtr\Support\Hris;
use App\Livewire\Kit\Support\DepartmentFilter;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Timekeeping\Application\Commands\DecideOvertime;
use App\Modules\Timekeeping\Application\Commands\DecidePendingOvertime;
use App\Modules\Timekeeping\Application\Commands\SaveRemark;
use App\Modules\Timekeeping\Application\Queries\AttendanceRemarks;
use App\Modules\Timekeeping\Application\Queries\DayCorrections;
use App\Modules\Timekeeping\Application\Queries\OvertimeDecisions;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Modules\Timekeeping\Application\Queries\ScheduleDays;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Contracts\OpenPunchLimit;
use App\Modules\Timekeeping\Contracts\Views\LeaveTypeView as LeaveType;
use App\Modules\Timekeeping\Contracts\Views\ShiftView as Shift;
use App\Modules\Timekeeping\Domain\Attendance\DayClose;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Modules\Timekeeping\Domain\Roster\ScheduleBasis;
use App\Modules\Workforce\Contracts\DirectoryEmployee as EmployeeProfile;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * eDTR & Attendance — the port of
 * copems/src/app/(protected)/edtr/edtr-page.tsx.
 *
 * Two tabs: the punch station beside the live feed, and the computed Daily
 * Time Record for one employee over one period. THE DTR TAB NEVER RE-DERIVES
 * A MINUTE — every figure comes from DtrCalculator / DtrSummary, exactly as
 * the reference read `computeDtr` / `summarize`, and every overtime figure
 * from Timekeeping's OvertimeDecisions (through OvertimeApprovalPolicy),
 * shown beside the computed OT in each row and in its approval dialog.
 *
 * DELIBERATE DIVERGENCE: edtr-page.tsx:465 shows `totals.absences` over
 * days computed through today, so today's row — ABSENT before its shift
 * starts — counts before anyone is due. The Absences tile counts through
 * Timekeeping's DayClose instead: a day with no complete Time In and Time
 * Out (DayRecord::isAbsenceDay, the payslip's own per-day test), once its
 * shift and the Formulas OT max after it have passed.
 *
 * Tab switching re-renders server-side and only the active tab's content is
 * mounted, which matches the reference: Radix TabsContent unmounts inactive
 * content, so switching away and back reset the punch form there too.
 *
 * Permissions, as the reference gated them:
 *   - selfOnly (`!me.allData`): the employee field is locked to the actor's
 *     own record on both tabs.
 *   - remarks and corrections take the role that sees everyone (`me.allData`);
 *     the write actions re-check for themselves — route middleware alone is
 *     not enough for a Livewire action.
 *
 * The correction dialog reads a per-date view — DayCorrections::view(), the
 * shift for THAT date, the day as computed, the stored remark and the
 * recorded punches with their captured location — on open and on every
 * render while it is open. Nothing from it lands in Livewire state: it is
 * not Wireable, and reading it afresh means the punches listed are the ones
 * on the table right now.
 *
 * Night shifts: where the date's shift can hold a next-day time (overnight
 * or flexible) each of the four slots has a "Next day" tick, prefilled from
 * the saved punch — on a shift that ends the same day only while a tick is
 * still set, so it can be unticked — and a save uses exactly what is
 * ticked: nothing is inferred from the order of the times. The DTR table
 * tags a time dated after its row "next day". The save replaces the punches
 * the DTR reads as that row and nothing else (Timekeeping's
 * CorrectionWindow), and a tick on a shift that ends the same day is
 * refused there, not here.
 *
 * Overtime is decided here (payroll pays only what is approved): each
 * pending OT cell offers inline approval. The OT amount opens details for a
 * typed minutes or note change. Unapproved overtime is excluded without
 * holding up payroll. The table keeps computed OT and its approval status
 * together, and punch corrections use a separate dialog. Decision controls
 * show only to an all-data EDTR holder, including on their own record, and
 * each action resolves the actor afresh; Timekeeping's DecideOvertime /
 * DecidePendingOvertime re-check everything and own the audit and the
 * notification.
 */
#[Layout('layouts.app')]
#[Title('eDTR & Attendance')]
class EdtrPage extends Component
{
    /** Which tab is showing. Plain UI state; anything unknown collapses back. */
    public string $tab = 'punch';

    /** The DTR tab's picked employee id — id-bearing, so #[Locked]. */
    #[Locked]
    public string $dtrEmployee = '';

    /**
     * The DTR tab's "Department or unit": ALL, NONE (Not placed), or a
     * department or unit id. It only narrows the employee picker's list,
     * which applies its own row scope first, so deliberately unlocked.
     */
    public string $department = DepartmentFilter::ALL;

    /** Period start, 'Y-m-d'. A date filter — deliberately unlocked. */
    public string $start = '';

    /** Period end, 'Y-m-d'. A date filter — deliberately unlocked. */
    public string $end = '';

    /**
     * Per-date remark drafts, date => text. Free text the user edits —
     * unlocked; the save action validates the date key and re-checks the role.
     *
     * @var array<string, string>
     */
    public array $remarkDrafts = [];

    /** The day being corrected, or null. Set only by openCorrection(). */
    #[Locked]
    public ?string $correctingDate = null;

    /** Drives the Flux modal; closing it clears the correction state. */
    public bool $correctionOpen = false;

    /**
     * The correction dialog's four slots ('HH:mm' or '') and its remark.
     *
     * Client-writable (the dialog binds to it), so its SHAPE is untrusted at
     * runtime — reads go through `?? ''`, hence the open map type.
     *
     * @var array<string, string>
     */
    public array $correctionForm = [
        'time_in' => '',
        'break_out' => '',
        'break_in' => '',
        'time_out' => '',
        'note' => '',
    ];

    /**
     * The correction dialog's "Next day" tick per slot. A wire:model draft
     * with no id in it, so deliberately not #[Locked]; client-writable, so
     * only a literal true counts (saveCorrection), and CorrectPunches judges
     * the ticks again against the date's shift and the rows the DTR reads.
     *
     * @var array<string, mixed>
     */
    public array $correctionNextDay = PunchSlots::SAME_DAY;

    public ?string $correctionError = null;

    /** The overtime date, independent of the day being corrected. */
    #[Locked]
    public ?string $overtimeDate = null;

    /** Drives the dedicated overtime dialog; closing it clears its draft. */
    public bool $overtimeOpen = false;

    /**
     * The per-day dialog's overtime draft: minutes to approve and a note.
     * Free text the approver edits — no id in it, so not #[Locked];
     * DecideOvertime re-validates both against the day it recomputes.
     *
     * @var array<string, string>
     */
    public array $overtimeForm = ['minutes' => '', 'note' => ''];

    public ?string $overtimeError = null;

    public function mount(): void
    {
        // Pay is cut off twice a month, so the record opens on the half of the
        // month we are standing in — the 1st to the 15th, or the 16th to its
        // last day.
        $period = Hris::semiMonthlyPeriod();
        $this->start = $period['start'];
        $this->end = $period['end'];

        // The ?tab=dtr&employee= deep link behind the Live Feed's names: the
        // DTR tab, and the employee only where the picker would take them —
        // a self-only viewer still lands on their own record.
        if (request()->query('tab') === 'dtr') {
            $this->tab = 'dtr';
            $employee = request()->query('employee');
            if (is_string($employee) && $employee !== '' && $this->mayShowEmployee($this->actor(), $employee)) {
                $this->dtrEmployee = $employee;
            }
        }
    }

    /* ── guards on client-writable state ─────────────────────────────────── */

    public function updatedTab(string $value): void
    {
        $this->tab = in_array($value, ['punch', 'dtr'], true) ? $value : 'punch';
        $this->resetOvertime();
    }

    public function updatedStart(string $value): void
    {
        $this->start = $value === '' || PayrollPeriod::isPayrollDate($value) ? $value : '';
        $this->remarkDrafts = [];
        $this->resetOvertime();
    }

    public function updatedEnd(string $value): void
    {
        $this->end = $value === '' || PayrollPeriod::isPayrollDate($value) ? $value : '';
        $this->remarkDrafts = [];
        $this->resetOvertime();
    }

    /* ── DTR tab actions ─────────────────────────────────────────────────── */

    #[On('kit-employee-picked')]
    public function employeePicked(string $name, string $id): void
    {
        if ($name !== 'dtr-employee') {
            return;
        }

        // selfOnly viewers have no picker at all; a forged event must not
        // widen the record they see.
        if ($this->mayShowEmployee($this->actor(), $id)) {
            $this->dtrEmployee = $id;
            $this->remarkDrafts = [];
            $this->resetOvertime();
        }
    }

    /** The "This cut-off" shortcut beside Period start. */
    public function thisCutoff(): void
    {
        $cutoff = Hris::semiMonthlyPeriod();
        $this->start = $cutoff['start'];
        $this->end = $cutoff['end'];
        $this->remarkDrafts = [];
        $this->resetOvertime();
    }

    /**
     * A day's remark, saved on blur (wire:model.blur lands here). The typed
     * note replaces what the record says; emptying the box clears it.
     */
    public function updatedRemarkDrafts(mixed $value, string $date): void
    {
        $actor = $this->actor();
        $note = app(SaveRemark::class)->handle($actor, $this->targetId($actor), $date, $value);
        if ($note === null) {
            unset($this->remarkDrafts[$date]);

            return;
        }
        $this->remarkDrafts[$date] = $note;
    }

    /* ── correction dialog ───────────────────────────────────────────────── */

    public function openCorrection(string $date): void
    {
        $actor = $this->actor();
        $targetId = $this->targetId($actor);

        if (! $actor->mayReadAllData(ModuleCode::EDTR) || $targetId === '') {
            return;
        }
        if (! PayrollPeriod::isPayrollDate($date)) {
            return;
        }
        if ($this->start === '' || $this->end === '' || $date < $this->start || $date > $this->end) {
            return;
        }

        // The prefill comes from the same per-date view the dialog draws, so
        // a slot left as it is still reads as the punch it came from.
        $view = app(DayCorrections::class)->view($actor, $targetId, $date);
        if ($view === null) {
            return;
        }

        $this->correctionForm = [
            'time_in' => $view->slots['time_in'],
            'break_out' => $view->slots['break_out'],
            'break_in' => $view->slots['break_in'],
            'time_out' => $view->slots['time_out'],
            'note' => trim($view->remark),
        ];
        $this->correctionNextDay = $view->next_day;
        $this->correctionError = null;
        $this->correctingDate = $date;
        $this->correctionOpen = true;
        $this->resetOvertime();
    }

    public function updatedCorrectionOpen(bool $value): void
    {
        if (! $value) {
            $this->correctingDate = null;
            $this->correctionError = null;
        }
    }

    /**
     * Empty all four slots of the open correction — the dialog's "Clear all"
     * (one slot is emptied by deleting its typed time). This touches
     * component state only — nothing is written until saveCorrection(), which
     * re-checks Access — so there is deliberately no Access check here. The
     * remark is never cleared this way.
     */
    public function clearAllSlots(): void
    {
        if ($this->correctingDate === null) {
            return;
        }

        foreach (PunchSlots::KEYS as $key) {
            $this->correctionForm[$key] = '';
        }
        // An empty slot is on no day at all.
        $this->correctionNextDay = PunchSlots::SAME_DAY;
        // The error described a shape that no longer exists.
        $this->correctionError = null;
    }

    public function saveCorrection(): void
    {
        $actor = $this->actor();
        $targetId = $this->targetId($actor);
        $date = $this->correctingDate;

        if (! $actor->mayReadAllData(ModuleCode::EDTR) || $targetId === '' || $date === null) {
            return;
        }

        $slots = $this->draftSlots();
        // Client-writable: a tick is a literal true or nothing.
        $nextDay = [];
        foreach (PunchSlots::KEYS as $key) {
            $nextDay[$key] = ($this->correctionNextDay[$key] ?? false) === true;
        }

        try {
            CorrectPunches::apply(
                $actor,
                $targetId,
                $date,
                $slots,
                $nextDay,
                (string) ($this->correctionForm['note'] ?? ''),
            );
        } catch (QueryException) {
            // Before RuntimeException, which it extends: never show raw SQL.
            $this->correctionError = 'The correction could not be saved. Nothing was changed.';

            return;
        } catch (RuntimeException $e) {
            $this->correctionError = $e->getMessage();

            return;
        }

        $this->remarkDrafts[$date] = trim((string) ($this->correctionForm['note'] ?? ''));
        $this->correctionOpen = false;
        $this->correctingDate = null;
        $this->correctionError = null;

        Flux::toast(text: Hris::fmtDate($date).' corrected.', variant: 'success');
    }

    /* ── overtime decisions ──────────────────────────────────────────────── */

    /** The row approves its displayed minutes; a concurrent decision must never be overwritten. */
    public function approveDayOvertime(string $employeeId, string $date, int $minutes): void
    {
        $actor = $this->actor();
        $targetId = $this->targetId($actor);
        if (! $actor->mayReadAllData(ModuleCode::EDTR) || $targetId === ''
            || $employeeId !== $targetId || $this->tab !== 'dtr') {
            return;
        }
        if (! PayrollPeriod::isPayrollDate($date) || $date > Hris::todayKey()
            || $this->start === '' || $this->end === '' || $date < $this->start || $date > $this->end) {
            return;
        }

        try {
            $sentence = app(DecideOvertime::class)->handle(
                $actor, $targetId, $date, OvertimeApprovalPolicy::APPROVED, $minutes, onlyIfPending: true,
            );
        } catch (QueryException) {
            Flux::toast(text: 'The overtime could not be approved. Nothing was changed.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast(text: $sentence, variant: 'success');
    }

    /** Review overtime directly from its computed amount, without opening punch fields. */
    public function openOvertime(string $date): void
    {
        $actor = $this->actor();
        $targetId = $this->targetId($actor);

        if (! $actor->mayReadAllData(ModuleCode::EDTR) || $targetId === '') {
            return;
        }
        if (! PayrollPeriod::isPayrollDate($date) || $date > Hris::todayKey()) {
            return;
        }
        if ($this->start === '' || $this->end === '' || $date < $this->start || $date > $this->end) {
            return;
        }

        $view = app(DayCorrections::class)->view($actor, $targetId, $date);
        if ($view?->overtime === null) {
            return;
        }

        $this->correctionOpen = false;
        $this->correctingDate = null;
        $this->correctionError = null;
        $this->overtimeDate = $date;
        $this->overtimeOpen = true;
        $this->overtimeForm = [
            'minutes' => (string) ($view->overtime->decision === OvertimeApprovalPolicy::APPROVED
                ? $view->overtime->approved_minutes
                : $view->overtime->ceiling),
            'note' => (string) ($view->overtime->note ?? ''),
        ];
        $this->overtimeError = null;
    }

    public function updatedOvertimeOpen(bool $value): void
    {
        if (! $value) {
            $this->resetOvertime();
        }
    }

    /** An employee or period change must not apply an open approval draft to another record. */
    private function resetOvertime(): void
    {
        $this->overtimeOpen = false;
        $this->overtimeDate = null;
        $this->overtimeForm = ['minutes' => '', 'note' => ''];
        $this->overtimeError = null;
    }

    /** Approve the open day's overtime — the typed minutes, re-validated by the command. */
    public function approveOvertime(): void
    {
        $this->decideOvertime($this->actor(), OvertimeApprovalPolicy::APPROVED);
    }

    /** Reject the open day's overtime: none of it is paid. */
    public function rejectOvertime(): void
    {
        $this->decideOvertime($this->actor(), OvertimeApprovalPolicy::REJECTED);
    }

    /** Reject every pending overtime day of the shown period. */
    public function rejectPendingOvertime(): void
    {
        $this->decidePendingOvertime($this->actor(), OvertimeApprovalPolicy::REJECTED);
    }

    /** Every action resolves its actor fresh and hands it here; the all-data check comes first. */
    private function decideOvertime(Access $actor, string $decision): void
    {
        $targetId = $this->targetId($actor);
        $date = $this->overtimeDate;

        if (! $actor->mayReadAllData(ModuleCode::EDTR) || $targetId === '' || $date === null || ! $this->overtimeOpen) {
            return;
        }

        try {
            $sentence = app(DecideOvertime::class)->handle(
                $actor,
                $targetId,
                $date,
                $decision,
                $this->overtimeForm['minutes'] ?? '',
                (string) ($this->overtimeForm['note'] ?? ''),
            );
        } catch (QueryException) {
            $this->overtimeError = 'The decision could not be saved. Nothing was changed.';

            return;
        } catch (RuntimeException $e) {
            $this->overtimeError = $e->getMessage();

            return;
        }

        // The dialog stays open on the fresh figures.
        $this->overtimeError = null;
        Flux::toast(text: $sentence, variant: 'success');
    }

    private function decidePendingOvertime(Access $actor, string $decision): void
    {
        $targetId = $this->targetId($actor);

        if (! $actor->mayReadAllData(ModuleCode::EDTR) || $targetId === '' || $this->start === '' || $this->end === '') {
            return;
        }

        try {
            $result = app(DecidePendingOvertime::class)->handle($actor, $targetId, $this->start, $this->end, $decision);
        } catch (QueryException) {
            Flux::toast(text: 'The overtime could not be decided. Nothing was changed.', variant: 'danger');

            return;
        } catch (RuntimeException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $verb = $decision === OvertimeApprovalPolicy::APPROVED ? 'Approved' : 'Rejected';
        $days = $result['decided'] === 1 ? '1 day' : "{$result['decided']} days";
        $skipped = $result['skipped'] > 0 ? " {$result['skipped']} could not be decided (already paid, or nothing approvable)." : '';
        Flux::toast(
            text: $result['decided'] === 0 && $result['skipped'] === 0 ? 'No unreviewed overtime to approve or reject.' : "{$verb} overtime on {$days}.{$skipped}",
            variant: $result['decided'] > 0 ? 'success' : 'warning',
        );
    }

    /* ── render ──────────────────────────────────────────────────────────── */

    public function render(): View
    {
        $actor = $this->actor();
        $selfOnly = ! $actor->mayReadAllData(ModuleCode::EDTR);
        $targetId = $this->targetId($actor);
        $canEditRemarks = $actor->mayReadAllData(ModuleCode::EDTR);

        // The picker's narrowing select, for a viewer who has a picker. A
        // department deleted since, or a value typed in by hand, falls back
        // to every department.
        $departments = [];
        if ($this->tab === 'dtr' && ! $selfOnly) {
            $departments = DepartmentFilter::options($actor);
            $this->department = DepartmentFilter::choice($this->department, $departments);
        }

        $employee = null;
        $shiftRow = null;
        $rows = [];
        $totals = null;
        $absences = 0;
        $holidays = [];
        $leaveTypes = [];
        $nothingToShow = '';
        $correction = null;
        $overtimeReview = null;
        $overtimeByDate = [];
        $correctionAutoRemark = '';
        $correctionStaleTick = false;
        $scheduleDays = [];
        $unscheduled = ['days' => 0, 'late' => 0, 'undertime' => 0, 'absences' => 0];

        if ($this->tab === 'dtr' && $targetId !== '') {
            $employee = $this->visibleEmployee($actor, $targetId);
            $shiftRow = app(ReferenceData::class)->shift($actor, $targetId);

            $holidays = app(HolidayCalendar::class)->all();
            $leaveTypes = array_values(app(ReferenceData::class)->leaveTypes($actor)
                ->map(static fn (LeaveType $t): array => ['code' => (string) $t->code, 'name' => (string) $t->name])
                ->all());

            [$computedDays, $schedule] = $this->computeDays($actor, $targetId, $holidays);
            $totals = DtrSummary::of($computedDays);

            // The Absences tile counts a day only once it is over: its shift's
            // end plus the Formulas OT max (DayClose). Today's row reads ABSENT
            // before its shift has even started, and INCOMPLETE while the
            // employee is still at work.
            $absences = $computedDays === [] ? 0 : DayClose::absences(
                $computedDays,
                $schedule,
                app(OpenPunchLimit::class)->hours(),
                CarbonImmutable::now(),
            );

            // The dates NOBODY ASSIGNED A SCHEDULE FOR: the engine judges
            // them by its default, which is an unverified fallback, so what
            // they add to the tiles is counted apart and the page says so
            // instead of presenting it as the record. The table itself shows
            // only the date: the shift a day was measured against is read in
            // its dialog.
            $scheduleDays = app(ScheduleDays::class)->forEmployee($actor, $targetId, $this->start, $this->end);
            $fallbackDays = array_values(array_filter(
                $computedDays,
                static fn (DayRecord $day): bool => ($scheduleDays[$day->date] ?? null)?->basis === ScheduleBasis::NONE,
            ));
            if ($fallbackDays !== []) {
                $unscheduled = [
                    'days' => count($fallbackDays),
                    'late' => array_sum(array_map(static fn (DayRecord $day): float => (float) $day->lateMinutes, $fallbackDays)),
                    'undertime' => array_sum(array_map(static fn (DayRecord $day): float => (float) $day->undertimeMinutes, $fallbackDays)),
                    'absences' => DayClose::absences($fallbackDays, $schedule, app(OpenPunchLimit::class)->hours(), CarbonImmutable::now()),
                ];
            }

            // The overtime decisions, judged against the SAME computed days.
            $overtimeByDate = app(OvertimeDecisions::class)->forDays($actor, $targetId, $computedDays, $this->start, $this->end);

            $byDate = [];
            foreach ($computedDays as $day) {
                $byDate[$day->date] = $day;
            }

            if ($this->start !== '' && $this->end !== '' && $this->start <= $this->end) {
                foreach (PayrollPeriod::eachDate($this->start, $this->end) as $date) {
                    $rows[] = $byDate[$date] ?? $date;
                }
            }

            // Why the table below is empty — never just a blank grid.
            $nothingToShow = $this->start === ''
                ? 'Set a period start.'
                : ($this->end === ''
                    ? 'Set a period end.'
                    : ($this->start > $this->end
                        ? 'Period start is after period end.'
                        : 'No dates in this period.'));

            // Prefill untouched drafts from the stored notes so the inputs
            // show the record until somebody writes over it.
            if ($canEditRemarks) {
                foreach ($this->storedRemarks($actor, $targetId) as $date => $note) {
                    if (! array_key_exists($date, $this->remarkDrafts)) {
                        $this->remarkDrafts[$date] = $note;
                    }
                }
            }

            // The open dialog, read fresh every render: a detached view, never
            // Livewire state, so the punches it lists are the ones on the
            // table now. The all-data check is the query's precondition.
            if ($this->correctingDate !== null && $canEditRemarks) {
                $correction = app(DayCorrections::class)->view($actor, $targetId, $this->correctingDate);
                if ($correction?->day !== null) {
                    $correctionAutoRemark = Hris::autoRemark($correction->day, $holidays, $leaveTypes);
                }
                // The dialog's "Untick Next day" warning, for a shift that ends
                // the same day: only a tick CorrectPunches would count — beside
                // a filled box (PunchSlots::nextDayFlags) — is one it refuses.
                $correctionStaleTick = $correction !== null && ! $correction->next_day_allowed
                    && in_array(true, PunchSlots::nextDayFlags($this->correctionNextDay, $this->draftSlots()), true);
            }

            if ($this->overtimeOpen && $this->overtimeDate !== null && $canEditRemarks) {
                $overtimeReview = app(DayCorrections::class)->view($actor, $targetId, $this->overtimeDate);
            }
        }

        return view('livewire.edtr.edtr-page', [
            'actor' => $actor,
            'selfOnly' => $selfOnly,
            'targetId' => $targetId,
            'canEditRemarks' => $canEditRemarks,
            'employee' => $employee,
            'shiftRow' => $shiftRow,
            'shiftLabel' => $this->shiftLabel($shiftRow),
            'rows' => $rows,
            'totals' => $totals,
            'absences' => $absences,
            'holidays' => $holidays,
            'leaveTypes' => $leaveTypes,
            'nothingToShow' => $nothingToShow,
            'storedRemarks' => $targetId === '' ? [] : $this->storedRemarks($actor, $targetId),
            'selfName' => $selfOnly ? Hris::employeeName($this->visibleEmployee($actor, $targetId)) : '',
            'correction' => $correction,
            'overtimeReview' => $overtimeReview,
            'overtimeByDate' => $overtimeByDate,
            'correctionAutoRemark' => $correctionAutoRemark,
            'correctionStaleTick' => $correctionStaleTick,
            'unscheduled' => $unscheduled,
            'departments' => $departments,
        ]);
    }

    /* ── internals ───────────────────────────────────────────────────────── */

    private function actor(): Access
    {
        return Access::forActor(auth()->user());
    }

    /**
     * Whose record the DTR tab shows: the actor's own when their role does not
     * see everyone, else whoever was picked. Never a client-writable value for
     * the selfOnly case.
     */
    private function targetId(Access $actor): string
    {
        if (! $actor->mayReadAllData(ModuleCode::EDTR)) {
            return $actor->hasEmployeeLink() ? $actor->ownEmployeeId() : '';
        }

        return $this->dtrEmployee;
    }

    /**
     * Whether the DTR tab may be pointed at this employee: only by the role
     * that sees everyone, and only at an id the directory resolves for them.
     * The picker's event and the deep link both arrive from the client, so
     * both ask here.
     */
    private function mayShowEmployee(Access $actor, string $id): bool
    {
        return $actor->mayReadAllData(ModuleCode::EDTR)
            && app(OperationalDirectory::class)->find($actor, $id) !== null;
    }

    private function visibleEmployee(Access $actor, string $targetId): ?EmployeeProfile
    {
        if ($targetId === '') {
            return null;
        }

        // selfOnly may only ever resolve their own row; the widened role reads any.
        if (! $actor->mayReadAllData(ModuleCode::EDTR)
            && (! $actor->hasEmployeeLink() || $actor->ownEmployeeId() !== $targetId)) {
            return null;
        }

        return app(OperationalDirectory::class)->find($actor, $targetId);
    }

    /**
     * The computed DTR for the target and period — DtrCalculator output only,
     * never re-derived here — and the shift it was computed against, which
     * the Absences tile reads to tell when each day is over.
     *
     * @param  list<HolidaySetting>  $holidays
     * @return array{0: list<DayRecord>, 1: ?DomainShift}
     */
    private function computeDays(Access $actor, string $targetId, array $holidays): array
    {
        $start = $this->start;
        $end = $this->end;
        $today = Hris::todayKey();
        $lastComputed = $end < $today ? $end : $today;
        if ($start === '' || $end === '' || $start > $end || $start > $lastComputed) {
            return [[], null];
        }
        $inputs = app(AttendanceSource::class)->forDtr($actor, $targetId, $start, $end);

        return [
            DtrCalculator::compute($inputs->logs, $inputs->shift, $inputs->leaves, $start, $lastComputed, $holidays),
            $inputs->shift,
        ];
    }

    /**
     * Typed notes for the shown period, matched back to their date.
     *
     * @return array<string, string>
     */
    private function storedRemarks(Access $actor, string $targetId): array
    {
        return app(AttendanceRemarks::class)->between($actor, $targetId, $this->start, $this->end);
    }

    /**
     * The shift line on the employee card. A flexible shift owes hours, not a
     * clock — printing its filler start time would read as a schedule the
     * employee was measured against.
     */
    private function shiftLabel(?Shift $shiftRow): string
    {
        if ($shiftRow === null) {
            return 'Not rostered — default 8:00 AM – 5:00 PM';
        }

        if ($shiftRow->mode === DomainShift::MODE_FLEXIBLE) {
            $hours = $shiftRow->required_hours ?? 8;
            $hours = $hours == (int) $hours ? (string) (int) $hours : (string) $hours;

            return "Flexible · {$hours}h a day, no set start, no overtime";
        }

        return Hris::fmtHm($shiftRow->start_time).' – '.Hris::fmtHm($shiftRow->end_time)
            ." · {$shiftRow->grace_minutes}m grace";
    }

    /**
     * The dialog's four boxes as CorrectPunches takes them: 'HH:mm', or null
     * for an empty box.
     *
     * @return array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}
     */
    private function draftSlots(): array
    {
        return [
            'time_in' => $this->slotOrNull($this->correctionForm['time_in'] ?? ''),
            'break_out' => $this->slotOrNull($this->correctionForm['break_out'] ?? ''),
            'break_in' => $this->slotOrNull($this->correctionForm['break_in'] ?? ''),
            'time_out' => $this->slotOrNull($this->correctionForm['time_out'] ?? ''),
        ];
    }

    private function slotOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
