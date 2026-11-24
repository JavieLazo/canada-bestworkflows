{{--
    One payroll, being created or opened again — the port of
    payroll/run-workspace.tsx + add-employees-dialog.tsx.

    Fidelity notes where shadcn has no Flux twin:
      - DraftMoneyInput is a bare <input> carrying the source's exact geometry
        (h-8, min-w-[7.5rem], right-aligned, tabular numerals) — a Flux input's
        wrapper does not fit a wide grid cell, which is why these cannot
        be <x-kit.money-input>. It is `type="text"`, not `type="number"`,
        because a number input will not DISPLAY a comma: the browser drops
        every character that is not part of a number, so thousands separators
        are impossible in one. `moneyField` (resources/js/money-field.js)
        supplies the grouping, and the wire call is handed its comma-free
        `raw` — never the displayed `text`, which asMoney would read as 0.
      - The dialog's pick checkboxes are native inputs styled with
        accent-color; Flux free's checkbox binds via wire:model, and these are
        action-driven like the source's onCheckedChange. (The source's
        overtime "acknowledge" checkbox has no counterpart here: overtime is
        approved per day on the Daily Time Record.)
      - The Save button's floppy icon has no heroicon; it approximates with
        "check". Export keeps "arrow-down-tray" for Download, Finalize keeps
        "lock-closed" for Lock.
      - The reference's busy spinner is Flux's built-in wire:loading state.

    Livewire-shaped divergence: the grid recomputes when an edit commits
    (change/blur) rather than on every keystroke — same domain arithmetic,
    same figures, one round-trip later.
--}}
@php
    use App\Livewire\Payroll\Support\LeaveChargeText;
    use App\Livewire\Payroll\Support\PayrollData;
    use App\Modules\Payroll\Domain\DeductionCalculation;
    use App\Platform\Files\PortraitUrl;
    use App\Support\HrisFormat;

    $photoUrl = static fn ($employee): ?string => $employee?->photo_path
        ? PortraitUrl::for($employee->photo_path)
        : null;

    $lineCount = count($lines);
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <flux:button
                variant="ghost"
                size="sm"
                icon="arrow-left"
                class="-ml-2 mb-1 text-muted-foreground"
                :href="route('payroll.index')"
                wire:navigate
            >
                Payroll
            </flux:button>
            <h1 class="text-2xl font-bold tracking-tight">
                {{ $run ? $run->name : 'Create Payroll' }}
                @if ($run)
                    <span class="{{ $finalized ? 'pill pill-success' : 'pill pill-warning' }} ml-3 align-middle">
                        {{ $run->status }}
                    </span>
                @endif
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ $finalized
                    ? 'Finalized — these payslips are posted and no longer change.'
                    : 'Name the payroll, set its period, then add the employees it covers.' }}
            </p>
            @if ($yearly)
                {{-- A 13th month payroll posted before: read-only, its one earning named in the grid. --}}
                <p class="text-sm text-muted-foreground" data-thirteenth-month-run>
                    This payroll paid 13th month pay for the year. Select an employee to see the pay statement.
                </p>
            @endif
            {{-- How long the period is, when it is paid and which pay of the year that is. --}}
            @if ($payLine !== null)
                <p class="text-sm text-muted-foreground" data-pay-line>
                    {{ $payLine['length'] }} · Paid on <span class="num">{{ \App\Livewire\Records\Support\PayScheduleText::longDay($payLine['pay_date']) }}</span>@if ($payLine['number'] !== null) · Pay <span class="num">{{ $payLine['number'] }}</span> of <span class="num">{{ $payLine['of'] }}</span> this year @endif
                </p>
            @endif
            {{-- The schedule's deadlines, when it has any. --}}
            @if (($schedulePeriod['deadlines'] ?? []) !== [])
                <p class="text-sm text-muted-foreground" data-pay-schedule-line>
                    @foreach ($schedulePeriod['deadlines'] as $deadline)
                        {{ $loop->first ? '' : '· ' }}{{ $deadline['label'] }} by <span class="num">{{ PayrollData::fmtDate($deadline['date']) }}</span>
                    @endforeach
                </p>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($lineCount > 0)
                {{-- Green like every Export CSV (x-kit.export-button); a wire:click because the grid streams its own file. --}}
                <flux:button variant="primary" icon="arrow-down-tray" wire:click="exportCsv" class="hris-button-success">
                    Export CSV
                </flux:button>
            @endif
            @if (! $finalized)
                <flux:button
                    variant="{{ $run ? 'outline' : 'primary' }}"
                    icon="check"
                    wire:click="save"
                    wire:loading.attr="disabled"
                    :disabled="$cutoffError !== null"
                >
                    Save
                </flux:button>
            @endif
            @if ($run && ! $finalized)
                @if ($reviewRequired)
                    <flux:button variant="primary" icon="arrow-path" wire:click="reviewChanges" wire:loading.attr="disabled">
                        Review Updated Payroll
                    </flux:button>
                @else
                    <flux:button
                        variant="primary"
                        icon="lock-closed"
                        wire:click="finalize"
                        wire:loading.attr="disabled"
                        :disabled="count($members) === 0 || $cutoffError !== null || $advanceError !== null || $invalidDraft !== null || $readinessIssue !== null || $scheduleWarnings['noShift'] !== [] || $dirty"
                    >
                        Finalize
                    </flux:button>
                @endif
            @endif
        </div>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-end gap-4 border-b border-border px-5 py-4">
            <div class="min-w-[14rem] flex-1 space-y-2">
                <flux:label>Payroll Name</flux:label>
                <flux:input
                    placeholder="e.g. Nov 8 to Nov 21, 2026"
                    wire:model.live.debounce.500ms="name"
                    :disabled="$finalized"
                />
            </div>
            <div class="w-[11rem] space-y-2">
                <flux:label>Period From</flux:label>
                <x-kit.date-field wire:model.live="start" :disabled="$finalized" aria-label="Period from" />
            </div>
            <div class="w-[11rem] space-y-2">
                <flux:label>Period To</flux:label>
                <x-kit.date-field wire:model.live="end" :disabled="$finalized" aria-label="Period to" />
            </div>
            {{-- The counted date is not a field: a draft is counted through yesterday
                 while its period runs (RunWorkspace::followTodayCountedThrough), and the
                 panel above the grid says so. --}}
            {{-- The pay schedule and the pay date are not asked for: the company's
                 default schedule gives the dates their pay date (PaySchedules,
                 filled in mount and on a date change), and the subtitle shows
                 it. The properties stay for the commands. --}}
            @if (! $finalized)
                <flux:button variant="primary" icon="plus" wire:click="openPicker">
                    Add Employee
                </flux:button>
            @endif
        </header>
        @if ($reviewRequired && ! $finalized)
            <x-payroll.notice tone="danger" class="border-b px-5 py-3">
                Inputs changed, so finalizing was cancelled. Review the new figures before posting.
            </x-payroll.notice>
        @endif
        @if ($cutoffError)
            <x-payroll.notice tone="danger" class="border-b px-5 py-3">
                {{ $cutoffError }}
                @if ($cutoffMembers !== [])
                    <span class="mt-1 block text-xs">On this payroll: {{ implode(', ', $cutoffMembers) }}. Change the dates, or remove someone through Add employee.</span>
                @endif
            </x-payroll.notice>
        @endif
        @if ($advanceError)
            <x-payroll.notice tone="danger" data-advance-error class="border-b px-5 py-3">
                {{ $advanceError }}
            </x-payroll.notice>
        @endif
        @if ($invalidDraft)
            <x-payroll.notice tone="danger" class="border-b px-5 py-3">
                {{ $invalidDraft['employee'] }}: {{ $invalidDraft['message'] }}
            </x-payroll.notice>
        @endif
        {{-- The advance payroll: what this payroll pays ahead of attendance,
             and what it settles from earlier ones. Information, never a block:
             the days after the counted date are paid as scheduled by design. --}}
        @if ($advance !== null)
            <x-payroll.notice tone="info" data-advance class="border-b px-5 py-3">
                @if ($advance['from'] !== null)
                    {{-- Every payroll is processed before its period ends, so this is the
                         normal case and is not called out as an "advance": the counted date
                         and what the days after it are paid as are stated plainly. --}}
                    <p>
                        <span class="font-semibold">Attendance is counted through <span class="num">{{ HrisFormat::date($advance['through']) }}</span>.</span>
                        {{-- The day count is every employee's days added together, so it can
                             be far more than the days in the range: it is always worded as a
                             total across the employees. --}}
                        @if ($advance['days'] > 0)
                            @if ($advance['from'] === $advance['to'])
                                The working day on <span class="num">{{ HrisFormat::date($advance['from']) }}</span> is
                            @else
                                The working days from <span class="num">{{ HrisFormat::date($advance['from']) }}</span> to <span class="num">{{ HrisFormat::date($advance['to']) }}</span> are
                            @endif
                            paid in full ahead of attendance:
                            @if ($advance['members'] === 1)
                                <span class="num">{{ $advance['days'] }}</span> {{ $advance['days'] === 1 ? 'day' : 'days' }} for 1 employee.
                            @else
                                <span class="num">{{ $advance['days'] }}</span> days in total across <span class="num">{{ $advance['members'] }}</span> employees.
                            @endif
                            Any difference from actual attendance is adjusted on the next payroll.
                        @else
                            <span class="num">{{ HrisFormat::date($advance['from']) }}</span> to <span class="num">{{ HrisFormat::date($advance['to']) }}</span>:
                            paid as scheduled and adjusted on the next payroll.
                        @endif
                    </p>
                    {{-- An unfinished day (a Time In without a Time Out) among the counted
                         days is assumed to finish: that member is counted through the day
                         before it and the rest is paid as scheduled too (PayrollAdvance::countedThrough). --}}
                    @if ($advance['earlier'] > 0)
                        <p data-advance-earlier>
                            {{ $advance['earlier'] === 1 ? '1 employee has' : $advance['earlier'].' employees have' }} a Time In without a Time Out among the counted days.
                            From that day on, they are paid as scheduled and adjusted once it is finished.
                        </p>
                    @endif
                @endif
                @if ($advance['settled'] > 0)
                    <p data-advance-settled>
                        <span class="font-semibold">Adjusts earlier days:</span>
                        <span class="num">{{ PayrollData::money($advance['total']) }}</span> for {{ $advance['settled'] === 1 ? '1 employee' : $advance['settled'].' employees' }}.
                        Select a Prior Cut-off Adjustment figure for details.
                    </p>
                @endif
            </x-payroll.notice>
        @endif
        @if ($readinessIssue)
            <x-payroll.notice tone="danger" class="border-b px-5 py-3">
                {{ $readinessIssue['employee'] }}: {{ $readinessIssue['message'] }}
            </x-payroll.notice>
        @endif
        {{-- A warning, never a block: dates that are not one of the pay
             schedule's periods are still priced as one regular pay of it. --}}
        @if ($cutoffWarning)
            <x-payroll.notice tone="warning" data-cutoff-warning class="border-b px-5 py-3">
                These dates are not one of the pay schedule's periods. CPP, EI and income tax are still worked out as one regular pay. Check the dates before finalizing.
            </x-payroll.notice>
        @endif
        {{-- Also a warning, never a block: the $90,000 exemption is
             spent in the order payrolls are finalized. --}}
        @if ($exemptOrderWarning)
            <x-payroll.notice tone="warning" data-exempt-order-warning class="border-b px-5 py-3">
                Finalize the earlier open payroll first. It also pays tax-exempt incentives to someone here, and the $90,000 exemption is used in finalizing order.
            </x-payroll.notice>
        @endif
        {{-- Two holds, then a warning. An adjustment awaiting approval and a
             date with no shift (judged by the engine's default week, which
             is nobody's schedule) each hold finalizing until they are dealt
             with; FinalizePayroll refuses by the same reads. A schedule
             changed after the draft was saved has already moved the amounts
             shown, and finalizing needs the receipt of exactly what is
             shown, so it is said, not stopped. --}}
        @if ($pendingAdjustments !== [])
            <x-payroll.notice tone="warning" data-pending-adjustments class="border-b px-5 py-3">
                An adjustment is awaiting approval for <span class="font-medium">{{ implode(', ', $pendingAdjustments) }}</span>.
                Review or discard it on <flux:link :href="route('payroll.adjustments')" wire:navigate>Adjustments</flux:link> before finalizing.
            </x-payroll.notice>
        @endif
        @if ($scheduleWarnings['noShift'] !== [])
            <x-payroll.notice tone="warning" data-no-shift-hold class="border-b px-5 py-3">
                Some dates in this period have no shift for <span class="font-medium">{{ implode(', ', $scheduleWarnings['noShift']) }}</span>.
                Set their schedule in @if ($canOpenRoster)<flux:link :href="route('roster.index')" wire:navigate>Shift Roster</flux:link>@else Shift Roster @endif before finalizing.
            </x-payroll.notice>
        @endif
        @if ($scheduleWarnings['changed'] !== [])
            <x-payroll.notice tone="warning" data-schedule-changed-warning class="border-b px-5 py-3">
                Schedules changed after this draft was last saved ({{ $scheduleWarnings['since'] }}):
                {{ implode(', ', $scheduleWarnings['changed']) }}.
                The amounts shown already follow the schedule each date has now. Review them before finalizing.
            </x-payroll.notice>
        @endif

        {{-- One band however many employees: a count, and the names folded
             away (a native <details wire:ignore.self>, so the reader's open
             state survives a re-render; nothing it holds changes attributes). --}}
        @if ($leaveCancellationWarnings !== [])
            <x-payroll.notice tone="warning" data-leave-cancellation-warning class="border-b px-5 py-3">
                <p>
                    <span class="font-semibold">Leave dates cancelled</span>
                    for {{ count($leaveCancellationWarnings) === 1 ? '1 employee' : count($leaveCancellationWarnings).' employees' }} since the last save. Review before finalizing.
                </p>
                <details wire:ignore.self class="mt-1 text-sm">
                    <summary class="cursor-pointer text-xs font-medium text-primary">Show Details</summary>
                    <ul class="mt-2 list-disc space-y-1 ps-5">
                        @foreach ($leaveCancellationWarnings as $warning)
                            <li wire:key="leave-cancelled-{{ $warning['employee_id'] }}">
                                {{ PayrollData::employeeName($employees->get($warning['employee_id'])) }}:
                                <span class="num">{{ implode(', ', $warning['dates']) }}</span>.
                                @if ($warning['overridden'])
                                    The manually entered Leave amount still applies. Clear the Leave cell to use the calculated amount.
                                @else
                                    The calculation excludes those leave dates.
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            </x-payroll.notice>
        @endif

        @if ($lineCount > 0)
            <dl class="grid gap-px border-b border-border bg-border sm:grid-cols-2 lg:grid-cols-4">
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Employees</dt>
                    <dd class="num mt-1 text-xl font-bold">{{ $lineCount }}</dd>
                </div>
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Gross Pay</dt>
                    <dd class="num mt-1 text-xl font-bold">{{ PayrollData::money($totals['gross_pay']) }}</dd>
                </div>
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Total Deductions</dt>
                    <dd class="num mt-1 text-xl font-bold text-danger">{{ PayrollData::money($totals['total_deductions']) }}</dd>
                </div>
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Net Pay</dt>
                    <dd class="num mt-1 text-xl font-bold text-success">{{ PayrollData::money($totals['net_pay']) }}</dd>
                </div>
            </dl>
        @endif

        {{--
            NO hris-table-scroll: every employee on the run shows at once. The
            members are not paginated, so the cap would be the only thing
            standing between the reader and the whole payroll, and a payroll
            is read as a whole — you are looking for the row that is wrong.

            hris-table-wrap STAYS, unlike the DTR's table, and the difference
            is width. The many columns and input boxes come to roughly
            2,500px, so letting them out of the card would scroll the page
            sideways and take the summary tiles and the Finalize button with
            them. The x-axis belongs inside the card here.

            The cost of keeping it is the same one the DTR pays for its own
            reasons: `overflow-x` makes this wrap a scrollport, hris-table pins
            its thead to the nearest one, and with no height cap that
            scrollport never scrolls — so the header rides up with the page.

            The x-axis it does scroll is what `hris-table-pin-first` is for:
            somewhere out past Gross Pay the row stops saying whose row it
            is, so the Employee cell stays put while the figures slide under
            it. Employee HAS TO REMAIN THE FIRST COLUMN of every section —
            thead, tbody and tfoot — because that is what the utility pins.
        --}}
        @if ($figureMarks !== [])
            {{-- What the marks under some figures mean (FigureSources); unmarked figures come from the company settings. --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-border px-5 py-3 text-xs text-muted-foreground" data-figure-key>
                <span>Figures that are not the company settings' are marked:</span>
                <span class="pill pill-muted">Recurring</span><span>a recurring deduction or allowance</span>
                <span class="pill pill-caution">Typed</span><span>typed on this payroll.</span>
                <span class="font-medium text-foreground">Select a mark to see why the figure is that amount.</span>
            </div>
        @endif
        <div class="hris-table-wrap">
            <table class="hris-table hris-table-pin-first">
                <thead>
                    <tr>
                        <th rowspan="2">Employee</th>
                        <th colspan="5" class="text-center">Days</th>
                        <th colspan="10" class="text-center">Earnings</th>
                        {{-- One column per Payroll Deductions row, in the list's order,
                             then the one typed line the reference has, the two
                             DTR-priced lines while their row exists, and the footing. --}}
                        @php
                            $columnRows = $deductionColumns->filter(static fn ($c) => $c->headsColumn())->values();
                            $lateUndertimeOn = $deductionColumns->contains(static fn ($c) => $c->calculation === DeductionCalculation::LATE_UNDERTIME);
                        @endphp
                        <th colspan="{{ $columnRows->count() + 6 + ($lateUndertimeOn ? 2 : 0) }}" class="text-center">Deductions</th>
                        <th rowspan="2" class="text-right">Net Pay</th>
                        <th rowspan="2" class="text-right">Actions</th>
                    </tr>
                    <tr>
                        <th class="text-right">Work</th>
                        <th class="text-right">Holiday</th>
                        <th class="text-right">Rest</th>
                        {{-- Where the days go that Work does not count. --}}
                        <th class="text-right">Paid Leave</th>
                        {{-- Of the Work days, those paid as scheduled before their attendance was in (PayrollAdvance). --}}
                        <th class="text-right">As Scheduled</th>
                        {{-- Days of leave credits charged for lateness, undertime and absences, by the employee's leave policy. --}}
                        <th class="text-right">Credits Deducted</th>

                        <th class="text-right">Basic Pay</th>
                        <th class="text-right">Allowance</th>
                        {{-- Basic pay plus the allowance. --}}
                        <th class="text-right">Subtotal</th>
                        <th class="text-right">OT Pay</th>
                        <th class="text-right">NSD</th>
                        <th class="text-right">Leave Pay</th>
                        <th class="text-right">Other Earnings</th>
                        {{-- The settlement of days paid in advance on an earlier payroll, positive or negative. --}}
                        <th class="text-right">Prior Cut-Off Adjustment</th>
                        {{-- Incentives and bonuses, taxed and tax-exempt together; on a 13th month payroll, the 13th month pay it posted. --}}
                        <th class="text-right">{{ $yearly ? '13th Month Pay' : 'Incentives' }}</th>
                        <th class="text-right">Gross Pay</th>

                        {{-- The source deductions: computed from the published figures and
                             each employee's tax details, never typed. --}}
                        <th class="text-right" data-source-deduction-head="cpp">CPP</th>
                        <th class="text-right" data-source-deduction-head="ei">EI</th>
                        <th class="text-right" data-source-deduction-head="federal_tax">Federal Tax</th>
                        <th class="text-right" data-source-deduction-head="provincial_tax">{{ $provinceName }} Tax</th>

                        @foreach ($columnRows as $column)
                            {{-- The name ends the cell unless the column is a percentage, whose rule follows on its own line (an expression, not a directive: a Blade conditional would put a Livewire block marker after the name). --}}
                            <th class="text-right" wire:key="deduction-head-{{ $column->key() }}" data-deduction-column="{{ $column->key() }}">{{ $column->name }}{!! $column->isPercent() ? '<span class="block text-xs font-normal text-muted-foreground">'.e(\App\Livewire\Records\Support\DeductionText::percent($column->percent)).'</span>' : '' !!}</th>
                        @endforeach
                        <th class="text-right">Other Deductions</th>
                        @if ($lateUndertimeOn)
                            <th class="text-right">Undertime</th>
                            <th class="text-right">Late</th>
                        @endif
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($lineCount === 0)
                        <tr wire:key="empty">
                            {{-- Employee, 5 Days, 10 Earnings, the Deductions group, Net Pay, Actions. --}}
                            <td colspan="{{ 20 + $columnRows->count() + ($lateUndertimeOn ? 2 : 0) }}" class="py-10 text-center text-muted-foreground">
                                {{ $cutoffError !== null || $advanceError !== null ? 'Nothing is computed while the cut-off is refused.' : 'No employee on this payroll yet.' }}
                            </td>
                        </tr>
                    @endif
                    @foreach ($lines as $p)
                        @php
                            $e = $employees->get($p->employee_id);
                            $nameForInput = PayrollData::employeeName($e);
                        @endphp
                        <tr
                            wire:key="line-{{ $p->employee_id }}"
                            @if ($finalized && $run)
                                tabindex="0"
                                aria-label="Open payslip for {{ $nameForInput }}"
                                class="cursor-pointer focus:outline-none focus-visible:bg-accent"
                                x-on:click="Livewire.navigate('{{ route('payroll.payslip', ['id' => $run->id, 'employeeId' => $p->employee_id]) }}')"
                                x-on:keydown.enter.prevent="Livewire.navigate('{{ route('payroll.payslip', ['id' => $run->id, 'employeeId' => $p->employee_id]) }}')"
                                x-on:keydown.space.prevent="Livewire.navigate('{{ route('payroll.payslip', ['id' => $run->id, 'employeeId' => $p->employee_id]) }}')"
                            @endif
                        >
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                        @if ($photoUrl($e))
                                            <img src="{{ $photoUrl($e) }}" alt="{{ $nameForInput }}" class="h-full w-full object-cover" />
                                        @else
                                            <span class="num text-xs font-semibold">{{ PayrollData::initials($e) }}</span>
                                        @endif
                                    </span>
                                    {{-- The Employee ID, not the row id — and no line at
                                         all when the company has not given this person
                                         a number. An em dash here would read as "this row
                                         has no employee", which is a different thing. --}}
                                    @php $identity = \App\Support\EmployeeIdentity::besideName($e); @endphp
                                    {{-- The cap is what makes `truncate` fire, and it is
                                         here because this column is PINNED: nothing bounds a cell
                                         in an auto-layout table, so "Ma. Cristina
                                         Villanueva-Santos" widens it to 282px, and on a 390px
                                         phone that leaves 59px of scrollport for twenty-two
                                         columns of figures. 7.5rem is the money inputs' own
                                         min-width, so the pinned column never costs more than one
                                         figure column plus the avatar. Above `md` the scrollport
                                         is wide enough not to care, and names render in full. --}}
                                    <div class="min-w-0 max-w-[7.5rem] md:max-w-none">
                                        <p class="truncate font-medium">{{ $nameForInput }}</p>
                                        @if ($identity !== '')
                                            <p class="num truncate text-xs text-muted-foreground">{{ $identity }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="num text-right">{{ PayrollData::dayCount($p->work_days) }}</td>
                            <td class="num text-right">{{ PayrollData::dayCount($p->holiday_days) }}</td>
                            <td class="num text-right">{{ PayrollData::dayCount($p->rest_days) }}</td>
                            <td class="num text-right">{{ PayrollData::dayCount($p->paid_leave_days) }}</td>
                            @php $presumedDays = (float) ($p->presumed_days ?? 0.0); @endphp
                            <td class="num text-right {{ $presumedDays > 0 ? '' : 'text-muted-foreground' }}" data-presumed-days="{{ $p->employee_id }}">{{ $presumedDays > 0 ? PayrollData::dayCount($presumedDays) : '—' }}</td>
                            {{-- Leave credits charged (DIVERGENCE 15): found by the calculation from the
                                 employee's leave policy, never typed; a draft's figure opens "Why this amount?". --}}
                            @php $chargedDays = (float) ($p->leave_charged_days ?? 0.0); $chargePlan = $leaveChargeLines[$p->employee_id] ?? null; @endphp
                            <td class="text-right" data-leave-charged="{{ $p->employee_id }}">
                                @if ($finalized || ($chargedDays === 0.0 && $chargePlan === null))
                                    <span class="num {{ $chargedDays > 0 ? '' : 'text-muted-foreground' }}">{{ $chargedDays > 0 ? LeaveChargeText::credits($chargedDays) : '—' }}</span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="openWhy('{{ $p->employee_id }}', 'leave')"
                                        aria-label="Why are {{ LeaveChargeText::credits($chargedDays) }} days of {{ $nameForInput }}'s leave credits deducted?"
                                        class="num inline-flex h-8 w-full min-w-[6rem] items-center justify-end rounded-md border border-border bg-background px-2.5 text-sm hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring {{ $chargedDays > 0 ? '' : 'text-muted-foreground' }}"
                                    >
                                        {{ $chargedDays > 0 ? LeaveChargeText::credits($chargedDays) : '—' }}
                                    </button>
                                @endif
                            </td>

                            <td class="num text-right">{{ PayrollData::money($p->basic_pay) }}</td>
                            @if ($finalized)
                                <td class="num text-right">{{ PayrollData::money($p->allowances) }}</td>
                            @else
                                <td class="text-right">
                                    <input
                                        type="text" inputmode="decimal" autocomplete="off"
                                        x-data="moneyField(@js((string) $p->allowances))"
                                        x-bind:value="text"
                                        x-on:input="onInput($event)"
                                        x-on:focus="$event.target.select()"
                                        x-on:blur="settle()"
                                        aria-label="{{ $nameForInput }} Allowance"
                                        x-on:change="$wire.updateAllowance('{{ $p->employee_id }}', raw)"
                                        class="num h-8 w-full min-w-[7.5rem] rounded-md border border-border bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                    />
                                </td>
                            @endif
                            <td class="num text-right">{{ PayrollData::money($p->total_base_pay) }}</td>
                            {{-- Overtime is approved per day on the Daily Time Record,
                                 so a draft shows the approved pay. A posted payslip may
                                 instead carry the computed amount and the source's
                                 run-level acknowledgement: unacknowledged, the amount is
                                 struck through because it never reached gross, exactly as
                                 the individual payslip shows it. --}}
                            <td class="text-right">
                                <span
                                    class="{{ $p->overtime_acknowledged ? 'num text-success' : 'num text-muted-foreground line-through' }}"
                                    title="{{ $p->overtime_acknowledged ? 'Approved: included in gross pay.' : 'Not approved: excluded from gross pay.' }}"
                                >
                                    {{ PayrollData::money($p->overtime_pay) }}
                                </span>
                            </td>
                            @foreach (PayrollData::EARNING_FIELDS as $field => $label)
                                @if ($finalized)
                                    <td class="num text-right">{{ PayrollData::money($p->{$field}) }}</td>
                                @else
                                    {{-- An emptied cell returns to the computed figure (see
                                         money-field.js on `fallback`); the key changes with the
                                         override so a cleared override rebuilds the input. --}}
                                    @php $earningTyped = isset($draftEarnings[$p->employee_id][$field]); @endphp
                                    <td class="text-right">
                                        <input
                                            type="text" inputmode="decimal" autocomplete="off"
                                            wire:key="earning-{{ $p->employee_id }}-{{ $field }}-{{ $earningTyped ? 'typed' : 'computed' }}"
                                            x-data="moneyField(@js((string) $p->{$field}), @js($earningTyped ? null : (string) $p->{$field}))"
                                            x-bind:value="text"
                                            x-on:input="onInput($event)"
                                            x-on:focus="$event.target.select()"
                                            x-on:blur="settle()"
                                            aria-label="{{ $nameForInput }} {{ $label }}"
                                            x-on:change="$wire.updateEarning('{{ $p->employee_id }}', '{{ $field }}', raw); restoreIfEmpty()"
                                            class="num h-8 w-full min-w-[7.5rem] rounded-md border border-border bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                        />
                                    </td>
                                @endif
                            @endforeach
                            {{-- The settlement of earlier presumed days (the advance payroll):
                                 found by the calculation, never typed; a figure opens "Why this amount?". --}}
                            @php $settlementAmount = (float) ($p->settlement ?? 0.0); @endphp
                            <td class="text-right" data-settlement="{{ $p->employee_id }}">
                                @if ($settlementAmount === 0.0)
                                    <span class="num text-muted-foreground">—</span>
                                @elseif ($finalized)
                                    <span class="num {{ $settlementAmount < 0 ? 'text-danger' : '' }}">{{ PayrollData::money($settlementAmount) }}</span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="openWhy('{{ $p->employee_id }}', 'settlement')"
                                        aria-label="Why is {{ $nameForInput }}'s prior cut-off adjustment {{ PayrollData::money($settlementAmount) }}?"
                                        class="num inline-flex h-8 w-full min-w-[7.5rem] items-center justify-end rounded-md border border-border bg-background px-2.5 text-sm hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring {{ $settlementAmount < 0 ? 'text-danger' : '' }}"
                                    >
                                        {{ PayrollData::money($settlementAmount) }}
                                    </button>
                                @endif
                            </td>
                            {{-- Incentives and bonuses: one figure, the taxed part
                                 and the tax-exempt part together; a draft's cell opens the
                                 per-employee dialog, and its title itemises the lines. --}}
                            @php
                                $incentiveTitle = collect($incentiveLines[$p->employee_id] ?? [])
                                    ->map(fn (array $line): string => $line['name'].': '.PayrollData::money($line['amount'])
                                        .' ('.strtolower(\App\Livewire\Records\Support\BenefitText::TAXES[$line['tax']] ?? ($line['is_taxable'] ? 'Taxable' : 'Tax-exempt')).($line['over_ceiling'] > 0 ? ', '.PayrollData::money($line['over_ceiling']).' over the yearly ceiling' : '').')')
                                    ->implode('; ');
                            @endphp
                            <td class="text-right" data-incentives="{{ $p->employee_id }}">
                                @if ($yearly)
                                    <span class="num" data-thirteenth-month-pay>{{ PayrollData::money($p->thirteenth_month) }}</span>
                                @elseif ($finalized)
                                    <span class="num">{{ PayrollData::money(PayrollData::incentivesTotal($p)) }}</span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="openIncentives('{{ $p->employee_id }}')"
                                        aria-label="Incentives for {{ $nameForInput }}: {{ PayrollData::money(PayrollData::incentivesTotal($p)) }}"
                                        @if ($incentiveTitle !== '') title="{{ $incentiveTitle }}" @endif
                                        class="num inline-flex h-8 w-full min-w-[7.5rem] items-center justify-end rounded-md border border-border bg-background px-2.5 text-sm hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring"
                                    >
                                        {{ PayrollData::money(PayrollData::incentivesTotal($p)) }}
                                    </button>
                                    @if (isset($figureMarks[$p->employee_id]['incentives']))
                                        <div class="mt-1 flex justify-end"><span class="pill pill-muted" data-figure-mark="incentives">{{ $figureMarks[$p->employee_id]['incentives'] }} recurring</span></div>
                                    @endif
                                @endif
                            </td>
                            <td class="num text-right font-semibold">{{ PayrollData::money($p->gross_pay) }}</td>

                            {{-- CPP shows both contributions together; the second one is named under it when there is any. --}}
                            <td class="num bg-muted/40 text-right text-danger" data-source-deduction="cpp">{{ PayrollData::money(($p->cpp ?? 0) + ($p->cpp2 ?? 0)) }}{!! ($p->cpp2 ?? 0) > 0 ? '<span class="block text-xs font-normal text-muted-foreground">incl. second CPP '.e(PayrollData::money($p->cpp2)).'</span>' : '' !!}</td>
                            <td class="num bg-muted/40 text-right text-danger" data-source-deduction="ei">{{ PayrollData::money($p->ei ?? 0) }}</td>
                            <td class="num bg-muted/40 text-right text-danger" data-source-deduction="federal_tax">{{ PayrollData::money($p->federal_tax ?? 0) }}</td>
                            <td class="num bg-muted/40 text-right text-danger" data-source-deduction="provincial_tax">{{ PayrollData::money($p->provincial_tax ?? 0) }}</td>

                            @foreach ($columnRows as $column)
                                {{-- A column for SSS, PhilHealth, Pag-IBIG or withholding tax is on a
                                     finalized payroll that posted one: its figure as posted, never typed. --}}
                                @php $field = $column->calculation !== null ? DeductionCalculation::statutoryKey($column->calculation) : null; @endphp
                                @if ($field !== null)
                                    <td class="num text-right text-danger" data-deduction-cell="{{ $column->key() }}">{{ PayrollData::money($p->{$field}) }}</td>
                                @elseif ($finalized)
                                    {{-- A typed row, as the payslip posted it: zero when this
                                         employee had no such line. --}}
                                    <td class="num text-right text-danger" data-deduction-cell="{{ $column->key() }}">
                                        {{ PayrollData::money($typedAmounts[$p->employee_id][$column->key()] ?? 0) }}
                                    </td>
                                @else
                                    {{-- A typed row's cell: the Payroll Deductions row's amount for this
                                         employee, zero when nothing is charged. Typed like Other
                                         Deductions; an emptied cell removes the line and shows zero. --}}
                                    @php
                                        // A percentage column — or an employee's recurring line on this row — shows the
                                        // computed amount and takes a typed amount over it; an emptied cell shows it
                                        // again, so the input is re-keyed by which it holds.
                                        $recurringCell = collect($customDeductionLines[$p->employee_id] ?? [])->contains(fn (array $line): bool => $line['type_id'] === $column->id && $line['automatic'] && $line['recurring_line_id'] !== null);
                                        $percentShare = $column->isPercent() || $recurringCell ? (string) ($typedAmounts[$p->employee_id][$column->key()] ?? 0) : '0';
                                        $typedCell = isset($draftCustomDeductions[$p->employee_id][$column->id]);
                                    @endphp
                                    <td class="text-right" data-deduction-cell="{{ $column->key() }}" @if ($typedCell && $column->isPercent()) data-overridden="{{ $column->key() }}" title="Typed over the computed {{ PayrollData::money((float) $percentShare) }}" @elseif ($recurringCell) data-recurring="{{ $column->key() }}" title="Recurring for this employee: {{ PayrollData::money((float) $percentShare) }}. A typed amount replaces it." @endif>
                                        <input
                                            type="text" inputmode="decimal" autocomplete="off"
                                            wire:key="deduction-{{ $p->employee_id }}-{{ $column->key() }}-{{ $typedCell ? 'typed' : 'computed' }}"
                                            x-data="moneyField(@js($typedCell ? (string) $draftCustomDeductions[$p->employee_id][$column->id] : $percentShare), @js($percentShare))"
                                            x-bind:value="text"
                                            x-on:input="onInput($event)"
                                            x-on:focus="$event.target.select()"
                                            x-on:blur="settle(); restoreIfEmpty()"
                                            aria-label="{{ $nameForInput }} {{ $column->name }}"
                                            x-on:change="$wire.updateCustomDeduction('{{ $p->employee_id }}', '{{ $column->id }}', raw); restoreIfEmpty()"
                                            class="num h-8 w-full min-w-[7.5rem] rounded-md border border-border bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                        />
                                        @if (isset($figureMarks[$p->employee_id]['row:'.$column->id]))
                                            @include('livewire.payroll.partials.figure-mark', ['source' => $figureMarks[$p->employee_id]['row:'.$column->id], 'employeeId' => $p->employee_id, 'cell' => 'row:'.$column->id, 'label' => $nameForInput.' '.$column->name])
                                        @endif
                                    </td>
                                @endif
                            @endforeach
                            @if ($finalized)
                                <td class="num text-right text-danger">{{ PayrollData::money($p->other_deductions) }}</td>
                            @else
                                {{-- The reference's one free-form line (run-workspace.tsx:57), the
                                     only Deductions column not from the list; a typed 0 is 0. --}}
                                <td class="text-right">
                                    <input
                                        type="text" inputmode="decimal" autocomplete="off"
                                        wire:key="deduction-{{ $p->employee_id }}-other_deductions"
                                        x-data="moneyField(@js((string) $p->other_deductions), '0')"
                                        x-bind:value="text"
                                        x-on:input="onInput($event)"
                                        x-on:focus="$event.target.select()"
                                        x-on:blur="settle(); restoreIfEmpty()"
                                        aria-label="{{ $nameForInput }} Other deductions"
                                        x-on:change="$wire.updateDeduction('{{ $p->employee_id }}', 'other_deductions', raw); restoreIfEmpty()"
                                        class="num h-8 w-full min-w-[7.5rem] rounded-md border border-border bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                    />
                                </td>
                            @endif
                            {{-- The DTR-priced lines: attendance results, fixed after Other
                                 Deductions, while the Late / Undertime row is on the list. --}}
                            @if ($lateUndertimeOn)
                                <td class="num text-right text-danger">{{ PayrollData::money($p->undertime_deduction) }}</td>
                                <td class="num text-right text-danger">{{ PayrollData::money($p->late_deduction) }}</td>
                            @endif
                            <td class="num text-right text-danger">{{ PayrollData::money($p->total_deductions) }}</td>

                            <td class="num text-right font-bold {{ $p->net_pay < 0 ? 'text-danger' : '' }}">
                                {{ PayrollData::money($p->net_pay) }}
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if (! $finalized && in_array($p->employee_id, $recurringMembers, true))
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        square
                                        icon="arrow-path"
                                        wire:click="openRecurring('{{ $p->employee_id }}')"
                                        aria-label="Recurring deductions and allowances for {{ $nameForInput }}"
                                    />
                                @endif
                                {{-- A new tab: the draft keeps any unsaved edit, and recomputes with the new settings on its next change. --}}
                                @if ($paySettingsLinks)
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        square
                                        icon="adjustments-horizontal"
                                        :href="route('records.employee-pay-settings.show', ['employee' => $p->employee_id])"
                                        target="_blank"
                                        aria-label="Pay settings for {{ $nameForInput }} (opens in a new tab)"
                                    />
                                @endif
                                @if (! $finalized)
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        square
                                        icon="trash"
                                        class="text-danger hover:text-danger"
                                        aria-label="Remove {{ $nameForInput }} from this payroll"
                                        wire:click="removeMember('{{ $p->employee_id }}')"
                                    />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                @if ($lineCount > 0)
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="num text-right">{{ PayrollData::dayCount($totals['work_days']) }}</td>
                            <td class="num text-right">{{ PayrollData::dayCount($totals['holiday_days']) }}</td>
                            <td class="num text-right">{{ PayrollData::dayCount($totals['rest_days']) }}</td>
                            <td class="num text-right">{{ PayrollData::dayCount($totals['paid_leave_days']) }}</td>
                            <td class="num text-right">{{ PayrollData::dayCount($totals['presumed_days']) }}</td>
                            <td class="num text-right">{{ LeaveChargeText::credits($totals['leave_charged_days']) }}</td>

                            <td class="num text-right">{{ PayrollData::money($totals['basic_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::money($totals['allowances']) }}</td>
                            <td class="num text-right">{{ PayrollData::money($totals['total_base_pay']) }}</td>
                            <td class="num text-right text-success">{{ PayrollData::money($totals['overtime_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::money($totals['night_differential']) }}</td>
                            <td class="num text-right">{{ PayrollData::money($totals['leave_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::money($totals['other_earnings']) }}</td>
                            <td class="num text-right {{ $totals['settlement'] < 0 ? 'text-danger' : '' }}">{{ PayrollData::money($totals['settlement']) }}</td>
                            <td class="num text-right">{{ PayrollData::money($yearly ? $thirteenthMonthTotal : PayrollData::incentivesTotal((object) $totals)) }}</td>
                            <td class="num text-right font-semibold">{{ PayrollData::money($totals['gross_pay']) }}</td>

                            <td class="num bg-muted/40 text-right text-danger" data-source-deduction-total="cpp">{{ PayrollData::money($totals['cpp'] + $totals['cpp2']) }}</td>
                            <td class="num bg-muted/40 text-right text-danger" data-source-deduction-total="ei">{{ PayrollData::money($totals['ei']) }}</td>
                            <td class="num bg-muted/40 text-right text-danger" data-source-deduction-total="federal_tax">{{ PayrollData::money($totals['federal_tax']) }}</td>
                            <td class="num bg-muted/40 text-right text-danger" data-source-deduction-total="provincial_tax">{{ PayrollData::money($totals['provincial_tax']) }}</td>

                            @foreach ($columnRows as $column)
                                <td class="num text-right text-danger" wire:key="deduction-total-{{ $column->key() }}">{{ PayrollData::money($deductionTotals[$column->key()] ?? 0) }}</td>
                            @endforeach
                            <td class="num text-right text-danger">{{ PayrollData::money($totals['other_deductions']) }}</td>
                            @if ($lateUndertimeOn)
                                <td class="num text-right text-danger">{{ PayrollData::money($totals['undertime_deduction']) }}</td>
                                <td class="num text-right text-danger">{{ PayrollData::money($totals['late_deduction']) }}</td>
                            @endif
                            <td class="num text-right text-danger">{{ PayrollData::money($totals['total_deductions']) }}</td>

                            <td class="num text-right font-bold">{{ PayrollData::money($totals['net_pay']) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    {{-- What the payroll costs beyond the pay, and what it owes the tax agency:
         footings of the grid's own figures, nothing computed here. --}}
    @if ($lineCount > 0)
        @php
            $employerPension = $totals['cpp_er'] + $totals['cpp2_er'];
            $employeePension = $totals['cpp'] + $totals['cpp2'];
            $incomeTax = $totals['federal_tax'] + $totals['provincial_tax'];
        @endphp
        <div class="grid items-start gap-6 lg:grid-cols-2" data-source-deduction-summary>
            <section class="hris-card overflow-hidden" aria-labelledby="employer-pays-heading" data-employer-pays>
                <x-payroll.settings.head title="What the Employer Pays" heading-id="employer-pays-heading" description="On top of the pay. These are not deducted from anyone." />
                <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2.5 p-5 text-sm">
                    <dt>Employer CPP <span class="text-muted-foreground">(same as the employees' CPP)</span></dt>
                    <dd class="num text-right" data-employer-cpp>{{ PayrollData::money($employerPension) }}</dd>
                    <dt>Employer EI <span class="text-muted-foreground">(1.4 times each employee's EI)</span></dt>
                    <dd class="num text-right" data-employer-ei>{{ PayrollData::money($totals['ei_er']) }}</dd>
                    <dt class="border-t border-border pt-2.5 font-bold">Total</dt>
                    <dd class="num border-t border-border pt-2.5 text-right font-bold" data-employer-total>{{ PayrollData::money($employerPension + $totals['ei_er']) }}</dd>
                </dl>
            </section>
            <section class="hris-card overflow-hidden" aria-labelledby="remit-heading" data-remittance>
                <x-payroll.settings.head title="Amount to Send to the CRA" heading-id="remit-heading" description="What this payroll owes the Canada Revenue Agency. The system shows the figure; the payment itself is made at your bank." />
                <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2.5 p-5 text-sm">
                    <dt>CPP, employees and employer</dt>
                    <dd class="num text-right" data-remit-cpp>{{ PayrollData::money($employeePension + $employerPension) }}</dd>
                    <dt>EI, employees and employer</dt>
                    <dd class="num text-right" data-remit-ei>{{ PayrollData::money($totals['ei'] + $totals['ei_er']) }}</dd>
                    <dt>Income tax, federal and {{ $provinceName }}</dt>
                    <dd class="num text-right" data-remit-tax>{{ PayrollData::money($incomeTax) }}</dd>
                    <dt class="border-t border-border pt-2.5 font-bold">Total</dt>
                    <dd class="num border-t border-border pt-2.5 text-right font-bold" data-remit-total>{{ PayrollData::money($employeePension + $employerPension + $totals['ei'] + $totals['ei_er'] + $incomeTax) }}</dd>
                </dl>
            </section>
        </div>
    @endif

    @if (! $finalized && $dirty && $run)
        <p class="text-xs text-warning">Unsaved changes — save before finalizing.</p>
    @endif

    {{-- Add-employees dialog (add-employees-dialog.tsx). --}}
    @include('livewire.payroll.partials.add-employees-dialog', [
        'pickerNote' => 'Tick everyone this payroll covers. Unticking an employee removes them from it.',
    ])

    {{-- "Why this amount?": payroll's checks for one marked figure, in order, the one it used highlighted (FigureSources::explain). --}}
    @if (! $finalized)
        <flux:modal
            wire:model.self="whyOpen"
            class="w-full max-w-lg"
            x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'why-heading')"
        >
            <div class="space-y-4" data-why-dialog>
                @if ($why !== null)
                    <div class="space-y-1">
                        <flux:heading size="lg" id="why-heading">{{ $why['title'] }}</flux:heading>
                        <flux:text class="text-sm text-muted-foreground">
                            <span class="num font-semibold text-foreground">{{ ($why['unit'] ?? '') === 'days' ? LeaveChargeText::days($why['amount']) : PayrollData::money($why['amount']) }}</span> on this payroll.
                            {{ $whyCell === 'settlement' ? 'Found from the days paid as scheduled on earlier payrolls, each priced again from its attendance:' : ($whyCell === 'leave' ? 'Deducted from leave credits first, by the leave settings; only what the credits did not cover is priced to pay:' : 'Payroll takes the first of these that is set:') }}
                        </flux:text>
                    </div>
                    <ol class="divide-y divide-border rounded-md border border-border">
                        @foreach ($why['steps'] as $step)
                            <li @class(['flex items-start justify-between gap-3 px-4 py-3', 'bg-primary-soft' => $step['state'] === 'used']) wire:key="why-step-{{ $loop->index }}">
                                <div class="min-w-0">
                                    <p class="font-medium">{{ $loop->iteration }}. {{ $step['label'] }}</p>
                                    <p class="text-xs text-muted-foreground">{{ $step['detail'] }}</p>
                                </div>
                                @if ($step['state'] === 'used')
                                    <span class="pill pill-success">Used</span>
                                @elseif ($step['state'] === 'unused')
                                    <span class="pill pill-muted">Not used</span>
                                @else
                                    <span class="pill pill-muted">Not set</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        @if ($whyFor !== null)
                            <flux:link :href="route('records.employee-pay-settings.show', ['employee' => $whyFor])" target="_blank" class="text-sm font-medium">Change it in their pay settings</flux:link>
                        @endif
                        <flux:modal.close>
                            <flux:button variant="ghost">Close</flux:button>
                        </flux:modal.close>
                    </div>
                @else
                    <flux:heading size="lg" id="why-heading">Why this amount?</flux:heading>
                    <flux:text class="text-sm text-muted-foreground">That figure is no longer on this payroll.</flux:text>
                @endif
            </div>
        </flux:modal>
    @endif

    {{-- Recurring deductions & allowances dialog: one member's standing deductions and incentives on this payroll,
         each skipped here or not. Nothing is written until Save. --}}
    @if (! $finalized)
        <flux:modal
            wire:model.self="recurringOpen"
            class="w-full max-w-lg"
            x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'recurring-heading')"
        >
            <div class="space-y-4">
                <div class="space-y-1">
                    <flux:heading size="lg" id="recurring-heading">Recurring Deductions &amp; Allowances · {{ PayrollData::employeeName($recurringEmployee) }}</flux:heading>
                    <flux:text class="text-sm text-muted-foreground">
                        Proposed on every payroll they are due on. Skip one to leave it off this payroll only; a typed amount in its cell replaces the proposal.
                    </flux:text>
                </div>
                <ul class="divide-y divide-border rounded-md border border-border">
                    @forelse ($recurringRows as $line)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="recurring-{{ $line['id'] }}">
                            <div class="min-w-0">
                                <p class="font-medium">{{ $line['name'] }}
                                    <span class="text-xs font-normal text-muted-foreground">· {{ $line['kind'] === 'DEDUCTION' ? 'deduction' : 'allowance' }}</span>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    @if (! $line['due'])
                                        Not due on this cut-off.
                                    @elseif ($line['skipped'])
                                        Skipped on this payroll.
                                    @else
                                        <span class="num">{{ PayrollData::money($line['proposed']) }}</span> on this payroll.
                                    @endif
                                    @if ($line['left'] !== null)
                                        Balance: <span class="num">{{ PayrollData::money($line['left']) }}</span>.
                                    @endif
                                    @if ($line['note'])
                                        {{ $line['note'] }}
                                    @endif
                                </p>
                            </div>
                            @if ($line['due'])
                                <flux:checkbox
                                    :checked="$line['skipped']"
                                    wire:click="toggleRecurringSkip('{{ $line['id'] }}')"
                                    label="Skip on This Payroll"
                                />
                            @endif
                        </li>
                    @empty
                        <li class="px-4 py-3 text-sm text-muted-foreground">No recurring deduction or allowance is active on this payroll.</li>
                    @endforelse
                </ul>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
                    @if ($recurringFor !== null && $paySettingsLinks)
                        <flux:link :href="route('records.employee-pay-settings.show', ['employee' => $recurringFor])" target="_blank" class="text-sm">
                            Manage recurring deductions and allowances
                        </flux:link>
                    @endif
                    <flux:button variant="primary" wire:click="$set('recurringOpen', false)">Done</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Incentives dialog: one member's amount per catalog type.
         Component state only; Save writes it with the rest of the draft. --}}
    <flux:modal
        wire:model.self="incentivesOpen"
        class="w-full max-w-lg"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'incentives-heading')"
    >
        <div class="space-y-4" data-incentives-dialog>
            <div class="space-y-1.5">
                <h2 id="incentives-heading" class="pr-8 text-lg font-semibold leading-none tracking-tight">
                    Incentives and Bonuses
                    @if ($incentivesEmployee)
                        <span class="text-muted-foreground">· {{ PayrollData::employeeName($incentivesEmployee) }}</span>
                    @endif
                </h2>
                <p class="text-sm text-muted-foreground">
                    A taxable amount is taxed with the pay. A tax-exempt one is added after the tax, up to
                    the yearly ceiling of exempt benefits; anything over that is taxed on this payslip.
                    A scheduled benefit is proposed on its own; an amount typed here replaces it.
                </p>
            </div>

            @if ($incentiveTypes->isEmpty())
                <p class="text-sm text-muted-foreground">No incentive types yet.</p>
            @else
                <div class="space-y-3">
                    @foreach ($incentiveTypes as $type)
                        @php $incentiveTax = \App\Livewire\Records\Support\BenefitText::tax($type); @endphp
                        <div class="grid grid-cols-[1fr_10rem] items-center gap-3" wire:key="incentive-{{ $type->id }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ $type->name }}</p>
                                <span class="{{ \App\Livewire\Records\Support\BenefitText::TAX_PILLS[$incentiveTax] }}">
                                    {{ \App\Livewire\Records\Support\BenefitText::TAXES[$incentiveTax] }}
                                </span>
                                @if (isset($proposedIncentives[$type->id]))
                                    <p class="mt-1 text-xs text-muted-foreground">Proposed <span class="num">{{ PayrollData::money($proposedIncentives[$type->id]) }}</span> · {{ trim((string) ($incentiveForm[$type->id] ?? '')) === '' ? 'leave empty to pay it as proposed; it cannot be set to 0 here' : 'the typed amount replaces it' }}</p>
                                @endif
                            </div>
                            <x-kit.money-input wire:model="incentiveForm.{{ $type->id }}" aria-label="{{ $type->name }} amount" />
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($incentiveError)
                <p role="alert" class="text-sm text-danger">{{ $incentiveError }}</p>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
                <flux:link :href="route('records.incentive-types')" wire:navigate class="text-sm">
                    Manage Incentive Types
                </flux:link>
                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="$set('incentivesOpen', false)">
                        Cancel
                    </flux:button>
                    <flux:button variant="primary" icon="check" wire:click="applyIncentives" :disabled="$incentiveTypes->isEmpty()">
                        Apply
                    </flux:button>
                </div>
            </div>
        </div>
    </flux:modal>
</div>
