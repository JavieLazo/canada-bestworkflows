<?php

declare(strict_types=1);

namespace App\Livewire\Edtr;

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\DtrSummary;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest as DomainLeave;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Livewire\Edtr\Support\CorrectPunches;
use App\Livewire\Edtr\Support\Hris;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Support\Access;
use App\Support\AuditService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
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
 * the reference read `computeDtr` / `summarize`.
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

    public ?string $correctionError = null;

    public function mount(): void
    {
        // Pay is cut off twice a month, so the record opens on the half of the
        // month we are standing in — the 1st to the 15th, or the 16th to its
        // last day.
        $period = Hris::semiMonthlyPeriod();
        $this->start = $period['start'];
        $this->end = $period['end'];
    }

    /* ── guards on client-writable state ─────────────────────────────────── */

    public function updatedTab(string $value): void
    {
        $this->tab = in_array($value, ['punch', 'dtr'], true) ? $value : 'punch';
    }

    public function updatedStart(string $value): void
    {
        $this->start = $value === '' || PayrollPeriod::isPayrollDate($value) ? $value : '';
        $this->remarkDrafts = [];
    }

    public function updatedEnd(string $value): void
    {
        $this->end = $value === '' || PayrollPeriod::isPayrollDate($value) ? $value : '';
        $this->remarkDrafts = [];
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
        if (! $this->actor()->mayReadAllData(ModuleCode::EDTR)) {
            return;
        }

        if (EmployeeProfile::query()->whereKey($id)->exists()) {
            $this->dtrEmployee = $id;
            $this->remarkDrafts = [];
        }
    }

    /** The "This cut-off" shortcut beside Period start. */
    public function thisCutoff(): void
    {
        $cutoff = Hris::semiMonthlyPeriod();
        $this->start = $cutoff['start'];
        $this->end = $cutoff['end'];
        $this->remarkDrafts = [];
    }

    /**
     * A day's remark, saved on blur (wire:model.blur lands here). The typed
     * note replaces what the record says; emptying the box clears it.
     */
    public function updatedRemarkDrafts(mixed $value, string $date): void
    {
        $actor = $this->actor();
        $targetId = $this->targetId($actor);

        // A remark is an HR note about someone, so writing one needs the wider
        // role (the reference PUT checks `session.allData`; the page itself is
        // behind EDTR, so the pair is mayReadAllData(EDTR)).
        if (! $actor->mayReadAllData(ModuleCode::EDTR) || $targetId === '') {
            unset($this->remarkDrafts[$date]);

            return;
        }
        if (! PayrollPeriod::isPayrollDate($date)) {
            unset($this->remarkDrafts[$date]);

            return;
        }

        $note = trim(is_string($value) ? $value : '');
        $stored = AttendanceRemark::query()
            ->where('employee_id', $targetId)
            ->whereDate('date', $date)
            ->first();

        if ($note === trim($stored->note ?? '')) {
            $this->remarkDrafts[$date] = $note;

            return;
        }

        if ($note === '') {
            $stored?->delete();
        } else {
            $remark = $stored ?? new AttendanceRemark;
            $remark->fill(['employee_id' => $targetId, 'date' => $date, 'note' => $note]);
            // Actor column from the session, never the request.
            $remark->author_id = $actor->userId === null ? null : (int) $actor->userId;
            $remark->save();
        }

        AuditService::record(
            $actor,
            AuditEvent::dtrRemark($note !== ''),
            'attendance_remarks',
            $targetId,
            $date.($note !== '' ? ' — '.$note : ''),
        );

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

        $day = $this->computedDayFor($actor, $targetId, $date);

        $this->correctionForm = [
            'time_in' => Hris::slotOf($day?->timeIn) ?? '',
            'break_out' => Hris::slotOf($day?->breakOut) ?? '',
            'break_in' => Hris::slotOf($day?->breakIn) ?? '',
            'time_out' => Hris::slotOf($day?->timeOut) ?? '',
            'note' => trim((string) AttendanceRemark::query()
                ->where('employee_id', $targetId)
                ->whereDate('date', $date)
                ->value('note')),
        ];
        $this->correctionError = null;
        $this->correctingDate = $date;
        $this->correctionOpen = true;
    }

    public function updatedCorrectionOpen(bool $value): void
    {
        if (! $value) {
            $this->correctingDate = null;
            $this->correctionError = null;
        }
    }

    public function saveCorrection(): void
    {
        $actor = $this->actor();
        $targetId = $this->targetId($actor);
        $date = $this->correctingDate;

        if (! $actor->mayReadAllData(ModuleCode::EDTR) || $targetId === '' || $date === null) {
            return;
        }

        $slots = [
            'time_in' => $this->slotOrNull($this->correctionForm['time_in'] ?? ''),
            'break_out' => $this->slotOrNull($this->correctionForm['break_out'] ?? ''),
            'break_in' => $this->slotOrNull($this->correctionForm['break_in'] ?? ''),
            'time_out' => $this->slotOrNull($this->correctionForm['time_out'] ?? ''),
        ];

        try {
            CorrectPunches::apply(
                $actor,
                $targetId,
                $date,
                $slots,
                (string) ($this->correctionForm['note'] ?? ''),
            );
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

    /* ── render ──────────────────────────────────────────────────────────── */

    public function render(): View
    {
        $actor = $this->actor();
        $selfOnly = ! $actor->mayReadAllData(ModuleCode::EDTR);
        $targetId = $this->targetId($actor);
        $canEditRemarks = $actor->mayReadAllData(ModuleCode::EDTR);

        $employee = null;
        $shiftRow = null;
        $rows = [];
        $totals = null;
        $holidays = [];
        $leaveTypes = [];
        $nothingToShow = '';

        if ($this->tab === 'dtr' && $targetId !== '') {
            $employee = $this->visibleEmployee($actor, $targetId);
            $shiftRow = Shift::query()
                ->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
                ->where('employee_id', $targetId)
                ->first();

            $holidays = HolidaySetting::listFromArrays(
                Holiday::query()->get()->map->toArray()->all(),
            );
            $leaveTypes = LeaveType::query()->orderBy('name')->get(['code', 'name'])
                ->map(static fn (LeaveType $t): array => ['code' => (string) $t->code, 'name' => (string) $t->name])
                ->all();

            $computedDays = $this->computeDays($actor, $targetId, $holidays);
            $totals = DtrSummary::of($computedDays);

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
            'holidays' => $holidays,
            'leaveTypes' => $leaveTypes,
            'nothingToShow' => $nothingToShow,
            'storedRemarks' => $targetId === '' ? [] : $this->storedRemarks($actor, $targetId),
            'selfName' => $selfOnly ? Hris::employeeName($this->visibleEmployee($actor, $targetId)) : '',
            'employeeLabel' => $employee !== null ? Hris::employeeName($employee) : 'This employee',
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

        return EmployeeProfile::query()->whereKey($targetId)->first();
    }

    /**
     * The computed DTR for the target and period — DtrCalculator output only,
     * never re-derived here.
     *
     * @param  list<HolidaySetting>  $holidays
     * @return list<DayRecord>
     */
    private function computeDays(Access $actor, string $targetId, array $holidays): array
    {
        $start = $this->start;
        $end = $this->end;
        $today = Hris::todayKey();

        // A cut-off runs to the 15th or the month's last day, and today is
        // usually somewhere in the middle of it. Computing past today would
        // mark days that have not happened yet as absences, so those rows
        // render blank instead.
        $lastComputed = $end < $today ? $end : $today;

        if ($start === '' || $end === '' || $start > $end || $start > $lastComputed) {
            return [];
        }

        // The DTR needs every punch inside the selected period (±1 day for
        // overnight shifts), not the newest-N window the feed shows.
        $range = PayrollPeriod::attendanceDateRange($start, $end);
        $instants = PayrollPeriod::attendanceInstants($range['from'], $range['to']);

        $logs = AttendanceLog::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->where('employee_id', $targetId)
            ->whereBetween('logged_at', [$instants['gte'], $instants['lte']])
            ->orderByDesc('logged_at')
            ->limit(50000)
            ->get(['id', 'employee_id', 'log_type', 'logged_at'])
            ->map(static fn (AttendanceLog $log): DomainLog => DomainLog::fromArray([
                'id' => $log->id,
                'employee_id' => $log->employee_id,
                'log_type' => $log->log_type,
                'logged_at' => $log->logged_at,
            ]))
            ->all();

        $shiftRow = Shift::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::ROSTER, ModuleCode::EDTR, ModuleCode::PAYROLL)
            ->where('employee_id', $targetId)
            ->first();

        // Leaves widen on LEAVES, exactly as GET /api/leave-requests scoped
        // them — a viewer without that grant computes without them, as the
        // reference page did.
        $leaves = LeaveTravelRequest::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
            ->where('employee_id', $targetId)
            ->get(['id', 'employee_id', 'request_type', 'day_type', 'start_date', 'end_date', 'status', 'is_paid'])
            ->map(static fn (LeaveTravelRequest $l): DomainLeave => DomainLeave::fromArray([
                'id' => $l->id,
                'employee_id' => $l->employee_id,
                'request_type' => $l->request_type,
                'day_type' => $l->day_type,
                'start_date' => $l->start_date,
                'end_date' => $l->end_date,
                'status' => $l->status,
                'is_paid' => $l->is_paid,
            ]))
            ->all();

        return DtrCalculator::compute(
            array_values($logs),
            $shiftRow === null ? null : DomainShift::fromArray($shiftRow->toArray()),
            array_values($leaves),
            $start,
            $lastComputed,
            $holidays,
        );
    }

    /** One day's computed record, for prefilling the correction dialog. */
    private function computedDayFor(Access $actor, string $targetId, string $date): ?DayRecord
    {
        $holidays = HolidaySetting::listFromArrays(Holiday::query()->get()->map->toArray()->all());

        foreach ($this->computeDays($actor, $targetId, $holidays) as $day) {
            if ($day->date === $date) {
                return $day;
            }
        }

        return null;
    }

    /**
     * Typed notes for the shown period, matched back to their date.
     *
     * @return array<string, string>
     */
    private function storedRemarks(Access $actor, string $targetId): array
    {
        if ($this->start === '' || $this->end === '' || $this->start > $this->end) {
            return [];
        }

        return AttendanceRemark::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::EDTR)
            ->where('employee_id', $targetId)
            ->whereDate('date', '>=', $this->start)
            ->whereDate('date', '<=', $this->end)
            ->get(['date', 'note'])
            ->mapWithKeys(static fn (AttendanceRemark $r): array => [$r->date->format('Y-m-d') => (string) $r->note])
            ->all();
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

            return "Flexible · {$hours}h a day, no set start";
        }

        return Hris::fmtHm($shiftRow->start_time).' – '.Hris::fmtHm($shiftRow->end_time)
            ." · {$shiftRow->grace_minutes}m grace";
    }

    private function slotOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
