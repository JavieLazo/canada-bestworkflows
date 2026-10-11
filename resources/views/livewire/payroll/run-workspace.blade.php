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
    use App\Livewire\Payroll\Support\PayrollData;
    use App\Modules\Payroll\Domain\DeductionCalculation;
    use App\Platform\Files\PortraitUrl;

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
                Employee Payroll
            </flux:button>
            <h1 class="text-2xl font-bold tracking-tight">
                {{ $run ? $run->name : 'Create payroll' }}
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
                        Review updated payroll
                    </flux:button>
                @else
                    <flux:button
                        variant="primary"
                        icon="lock-closed"
                        wire:click="finalize"
                        wire:loading.attr="disabled"
                        :disabled="count($members) === 0 || $cutoffError !== null || $invalidDraft !== null || $readinessIssue !== null || $dirty"
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
                <flux:label>Payroll name</flux:label>
                <flux:input
                    placeholder="e.g. August 2026 — 1st cut-off"
                    wire:model.live.debounce.500ms="name"
                    :disabled="$finalized"
                />
            </div>
            <div class="w-[11rem] space-y-2">
                <flux:label>Date from</flux:label>
                <x-kit.date-field wire:model.live="start" :disabled="$finalized" aria-label="Date from" />
            </div>
            <div class="w-[11rem] space-y-2">
                <flux:label>Date to</flux:label>
                <x-kit.date-field wire:model.live="end" :disabled="$finalized" aria-label="Date to" />
            </div>
            @if (! $finalized)
                <flux:button variant="primary" icon="plus" wire:click="openPicker">
                    Add employee
                </flux:button>
            @endif
        </header>

        @if ($reviewRequired && ! $finalized)
            <p role="alert" class="border-b border-danger/20 bg-danger-soft px-5 py-3 text-sm text-danger">
                Payroll inputs changed. Finalization was cancelled. Review the updated figures before posting.
            </p>
        @endif
        @if ($cutoffError)
            <p role="alert" class="border-b border-danger/20 bg-danger-soft px-5 py-3 text-sm text-danger">
                {{ $cutoffError }}
                @if ($cutoffMembers !== [])
                    <span class="mt-1 block text-xs">Employees on this payroll: {{ implode(', ', $cutoffMembers) }} — change the dates, or take an employee off through Add employee.</span>
                @endif
            </p>
        @endif
        @if ($invalidDraft)
            <p role="alert" class="border-b border-danger/20 bg-danger-soft px-5 py-3 text-sm text-danger">
                {{ $invalidDraft['employee'] }}: {{ $invalidDraft['message'] }}
            </p>
        @endif
        @if ($readinessIssue)
            <p role="alert" class="border-b border-danger/20 bg-danger-soft px-5 py-3 text-sm text-danger">
                {{ $readinessIssue['employee'] }}: {{ $readinessIssue['message'] }}
            </p>
        @endif
        {{-- A warning, never a block (CutoffAdvice): the statutory lines
             follow the semi-monthly rule, which a range of days other than a
             1st–15th or 16th–end cut-off only approximates. --}}
        @if ($cutoffWarning)
            <p role="status" data-cutoff-warning class="border-b border-warning/20 bg-warning-soft px-5 py-3 text-sm text-foreground">
                {{ $cutoffWarning }}
            </p>
        @endif
        {{-- Also a warning, never a block: the PHP 90,000 exemption is
             spent in the order payrolls are finalized. --}}
        @if ($exemptOrderWarning)
            <p role="status" data-exempt-order-warning class="border-b border-warning/20 bg-warning-soft px-5 py-3 text-sm text-foreground">
                An open payroll that ends earlier this year also pays tax-exempt incentives to someone here.
                The PHP 90,000 exemption is spent in the order payrolls are finalized, so finalize that one first.
            </p>
        @endif
        {{-- Also warnings, never blocks. Dates nobody assigned a schedule
             for are judged by the system default, which is not the
             employee's record; a schedule changed after the draft was saved
             has already moved the amounts shown, and finalizing needs the
             receipt of exactly what is shown. How payroll should treat
             unscheduled dates is an open question, so it is said, not
             stopped. --}}
        @if ($scheduleWarnings['unscheduled'] !== [])
            <p role="status" data-unscheduled-warning class="border-b border-warning/20 bg-warning-soft px-5 py-3 text-sm text-foreground">
                No assigned schedule on some dates of this period:
                <span class="num">{{ implode(', ', $scheduleWarnings['unscheduled']) }}</span>.
                Absences, lateness and deductions on those dates are measured against the system default
                (8:00 AM – 5:00 PM, Monday to Friday), which nobody assigned. Check their Daily Time Record before finalizing.
            </p>
        @endif
        @if ($scheduleWarnings['changed'] !== [])
            <p role="status" data-schedule-changed-warning class="border-b border-warning/20 bg-warning-soft px-5 py-3 text-sm text-foreground">
                Schedules changed after this draft was last saved ({{ $scheduleWarnings['since'] }}):
                {{ implode(', ', $scheduleWarnings['changed']) }}.
                The amounts shown already follow the schedule each date has now. Review them before finalizing.
            </p>
        @endif

        @foreach ($leaveCancellationWarnings as $warning)
            <p role="status" data-leave-cancellation-warning class="border-b border-warning/20 bg-warning-soft px-5 py-3 text-sm text-foreground">
                Leave dates cancelled for {{ PayrollData::employeeName($employees->get($warning['employee_id'])) }}:
                <span class="num">{{ implode(', ', $warning['dates']) }}</span>.
                @if ($warning['overridden'])
                    The manually entered Leave amount still applies. Review it and clear the Leave cell to use the calculated amount, or retain it intentionally before finalizing.
                @else
                    The calculation excludes those leave dates. Review attendance and pay before finalizing.
                @endif
            </p>
        @endforeach

        @if ($lineCount > 0)
            <dl class="grid gap-px border-b border-border bg-border sm:grid-cols-2 lg:grid-cols-4">
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Employees</dt>
                    <dd class="num mt-1 text-xl font-bold">{{ $lineCount }}</dd>
                </div>
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Gross income</dt>
                    <dd class="num mt-1 text-xl font-bold">{{ PayrollData::peso($totals['gross_pay']) }}</dd>
                </div>
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Total deductions</dt>
                    <dd class="num mt-1 text-xl font-bold text-danger">{{ PayrollData::peso($totals['total_deductions']) }}</dd>
                </div>
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Net pay</dt>
                    <dd class="num mt-1 text-xl font-bold text-success">{{ PayrollData::peso($totals['net_pay']) }}</dd>
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
            somewhere out past Gross Income the row stops saying whose row it
            is, so the Employee cell stays put while the figures slide under
            it. Employee HAS TO REMAIN THE FIRST COLUMN of every section —
            thead, tbody and tfoot — because that is what the utility pins.
        --}}
        <div class="hris-table-wrap">
            <table class="hris-table hris-table-pin-first">
                <thead>
                    <tr>
                        <th rowspan="2">Employee</th>
                        <th colspan="4" class="text-center">Days</th>
                        <th colspan="9" class="text-center">Earnings</th>
                        {{-- One column per Payroll Deductions row, in the list's order,
                             then the one typed line the reference has, the two
                             DTR-priced lines while their row exists, and the footing.
                             A computed row deleted from the list is a deduction
                             switched off: no column, no money. --}}
                        @php
                            $columnRows = $deductionColumns->filter(static fn ($c) => $c->headsColumn())->values();
                            $lateUndertimeOn = $deductionColumns->contains(static fn ($c) => $c->calculation === DeductionCalculation::LATE_UNDERTIME);
                        @endphp
                        <th colspan="{{ $columnRows->count() + 2 + ($lateUndertimeOn ? 2 : 0) }}" class="text-center">Deductions</th>
                        <th rowspan="2" class="text-right">Net Pay</th>
                        <th rowspan="2" class="text-right">Actions</th>
                    </tr>
                    <tr>
                        <th class="text-right">Work</th>
                        <th class="text-right">Holiday</th>
                        <th class="text-right">Rest</th>
                        {{-- Where the days go that Work does not count. --}}
                        <th class="text-right">Paid Leave</th>

                        <th class="text-right">Basic Salary</th>
                        <th class="text-right">Allowance</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">OT Pay</th>
                        <th class="text-right">NSD</th>
                        <th class="text-right">Leave</th>
                        <th class="text-right">Other Earnings</th>
                        {{-- Incentives and bonuses, taxed and tax-exempt together. --}}
                        <th class="text-right">Incentives</th>
                        <th class="text-right">Gross Income</th>

                        @foreach ($columnRows as $column)
                            {{-- The name ends the cell unless the column is a percentage, whose rule follows on its own line (an expression, not a directive: a Blade conditional would put a Livewire block marker after the name). --}}
                            <th class="text-right" wire:key="deduction-head-{{ $column->key() }}" data-deduction-column="{{ $column->key() }}">{{ $column->name }}{!! $column->isPercent() ? '<span class="block text-xs font-normal text-muted-foreground">'.e(\App\Livewire\Records\Support\DeductionText::percent($column->percent)).'</span>' : '' !!}</th>
                        @endforeach
                        <th class="text-right">Other Deductions</th>
                        @if ($lateUndertimeOn)
                            <th class="text-right">Undertimes</th>
                            <th class="text-right">Late</th>
                        @endif
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($lineCount === 0)
                        <tr wire:key="empty">
                            {{-- Employee, 4 Days, 9 Earnings, the Deductions group, Net Pay, Actions. --}}
                            <td colspan="{{ 18 + $columnRows->count() + ($lateUndertimeOn ? 2 : 0) }}" class="py-10 text-center text-muted-foreground">
                                {{ $cutoffError !== null ? 'Nothing is computed while the cut-off is refused.' : 'No employee on this payroll yet.' }}
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

                            <td class="num text-right">{{ PayrollData::peso($p->basic_pay) }}</td>
                            @if ($finalized)
                                <td class="num text-right">{{ PayrollData::peso($p->allowances) }}</td>
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
                            <td class="num text-right">{{ PayrollData::peso($p->total_base_pay) }}</td>
                            {{-- Overtime is approved per day on the Daily Time Record,
                                 so a draft shows the approved pay. A posted payslip may
                                 instead carry the computed amount and the source's
                                 run-level acknowledgement: unacknowledged, the amount is
                                 struck through because it never reached gross, exactly as
                                 the individual payslip shows it. --}}
                            <td class="text-right">
                                <span
                                    class="{{ $p->overtime_acknowledged ? 'num text-success' : 'num text-muted-foreground line-through' }}"
                                    title="{{ $p->overtime_acknowledged ? 'Approved: included in gross income.' : 'Not approved: excluded from gross income.' }}"
                                >
                                    {{ PayrollData::peso($p->overtime_pay) }}
                                </span>
                            </td>
                            @foreach (PayrollData::EARNING_FIELDS as $field => $label)
                                @if ($finalized)
                                    <td class="num text-right">{{ PayrollData::peso($p->{$field}) }}</td>
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
                            {{-- Incentives and bonuses: one figure, the taxed part
                                 and the tax-exempt part together; a draft's cell opens the
                                 per-employee dialog, and its title itemises the lines. --}}
                            @php
                                $incentiveTitle = collect($incentiveLines[$p->employee_id] ?? [])
                                    ->map(fn (array $line): string => $line['name'].': '.PayrollData::peso($line['amount'])
                                        .' ('.strtolower(\App\Livewire\Records\Support\BenefitText::TAXES[$line['tax']] ?? ($line['is_taxable'] ? 'Taxable' : 'Tax-exempt')).($line['over_ceiling'] > 0 ? ', '.PayrollData::peso($line['over_ceiling']).' over the yearly ceiling' : '').')')
                                    ->implode('; ');
                            @endphp
                            <td class="text-right" data-incentives="{{ $p->employee_id }}">
                                @if ($finalized)
                                    <span class="num">{{ PayrollData::peso(PayrollData::incentivesTotal($p)) }}</span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="openIncentives('{{ $p->employee_id }}')"
                                        aria-label="Incentives for {{ $nameForInput }}: {{ PayrollData::peso(PayrollData::incentivesTotal($p)) }}"
                                        @if ($incentiveTitle !== '') title="{{ $incentiveTitle }}" @endif
                                        class="num inline-flex h-8 w-full min-w-[7.5rem] items-center justify-end rounded-md border border-border bg-background px-2.5 text-sm hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring"
                                    >
                                        {{ PayrollData::peso(PayrollData::incentivesTotal($p)) }}
                                    </button>
                                @endif
                            </td>
                            <td class="num text-right font-semibold">{{ PayrollData::peso($p->gross_pay) }}</td>

                            @foreach ($columnRows as $column)
                                @php $field = $column->calculation !== null ? DeductionCalculation::statutoryKey($column->calculation) : null; @endphp
                                @if ($field !== null && $finalized)
                                    <td class="num text-right text-danger" data-deduction-cell="{{ $column->key() }}">{{ PayrollData::peso($p->{$field}) }}</td>
                                @elseif ($field !== null)
                                    {{-- A typed figure overrides the computed statutory amount.
                                         Clearing the cell restores it; both use the same styling. --}}
                                    @php $overridden = isset($draftDeductions[$p->employee_id][$field]); @endphp
                                    <td class="text-right" data-deduction-cell="{{ $column->key() }}" @if ($overridden) data-overridden="{{ $field }}" @endif>
                                        {{-- An emptied computed cell shows its computed figure again
                                             (money-field.js `fallback`); a cleared override changes the
                                             key, so the re-render rebuilds the input from the server. --}}
                                        <input
                                            type="text" inputmode="decimal" autocomplete="off"
                                            wire:key="deduction-{{ $p->employee_id }}-{{ $field }}-{{ $overridden ? 'typed' : 'computed' }}"
                                            x-data="moneyField(@js((string) $p->{$field}), @js(! $overridden ? (string) $p->{$field} : null))"
                                            x-bind:value="text"
                                            x-on:input="onInput($event)"
                                            x-on:focus="$event.target.select()"
                                            x-on:blur="settle()"
                                            aria-label="{{ $nameForInput }} {{ $column->name }}"
                                            x-on:change="$wire.updateDeduction('{{ $p->employee_id }}', '{{ $field }}', raw); restoreIfEmpty()"
                                            class="num h-8 w-full min-w-[7.5rem] rounded-md border border-border bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                        />
                                    </td>
                                @elseif ($finalized)
                                    {{-- A typed row, as the payslip posted it: zero when this
                                         employee had no such line. --}}
                                    <td class="num text-right text-danger" data-deduction-cell="{{ $column->key() }}">
                                        {{ PayrollData::peso($typedAmounts[$p->employee_id][$column->key()] ?? 0) }}
                                    </td>
                                @else
                                    {{-- A typed row's cell: the Payroll Deductions row's amount for this
                                         employee, zero when nothing is charged. Typed like Other
                                         Deductions; an emptied cell removes the line and shows zero. --}}
                                    @php
                                        // A percentage column shows the computed share and takes a typed amount over it;
                                        // an emptied cell shows the share again, so the input is re-keyed by which it holds.
                                        $percentShare = $column->isPercent() ? (string) ($typedAmounts[$p->employee_id][$column->key()] ?? 0) : '0';
                                        $typedCell = isset($draftCustomDeductions[$p->employee_id][$column->id]);
                                    @endphp
                                    <td class="text-right" data-deduction-cell="{{ $column->key() }}" @if ($typedCell && $column->isPercent()) data-overridden="{{ $column->key() }}" title="Typed over the computed {{ PayrollData::peso((float) $percentShare) }}" @endif>
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
                                    </td>
                                @endif
                            @endforeach
                            @if ($finalized)
                                <td class="num text-right text-danger">{{ PayrollData::peso($p->other_deductions) }}</td>
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
                                <td class="num text-right text-danger">{{ PayrollData::peso($p->undertime_deduction) }}</td>
                                <td class="num text-right text-danger">{{ PayrollData::peso($p->late_deduction) }}</td>
                            @endif
                            <td class="num text-right text-danger">{{ PayrollData::peso($p->total_deductions) }}</td>

                            <td class="num text-right font-bold {{ $p->net_pay < 0 ? 'text-danger' : '' }}">
                                {{ PayrollData::peso($p->net_pay) }}
                            </td>
                            <td class="text-right">
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

                            <td class="num text-right">{{ PayrollData::peso($totals['basic_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['allowances']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['total_base_pay']) }}</td>
                            <td class="num text-right text-success">{{ PayrollData::peso($totals['overtime_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['night_differential']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['leave_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['other_earnings']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso(PayrollData::incentivesTotal((object) $totals)) }}</td>
                            <td class="num text-right font-semibold">{{ PayrollData::peso($totals['gross_pay']) }}</td>

                            @foreach ($columnRows as $column)
                                <td class="num text-right text-danger" wire:key="deduction-total-{{ $column->key() }}">{{ PayrollData::peso($deductionTotals[$column->key()] ?? 0) }}</td>
                            @endforeach
                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['other_deductions']) }}</td>
                            @if ($lateUndertimeOn)
                                <td class="num text-right text-danger">{{ PayrollData::peso($totals['undertime_deduction']) }}</td>
                                <td class="num text-right text-danger">{{ PayrollData::peso($totals['late_deduction']) }}</td>
                            @endif
                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['total_deductions']) }}</td>

                            <td class="num text-right font-bold">{{ PayrollData::peso($totals['net_pay']) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    @if (! $finalized && $dirty && $run)
        <p class="text-xs text-warning">Unsaved changes — save before finalizing.</p>
    @endif

    {{-- Add-employees dialog (add-employees-dialog.tsx), shared with the 13th month run. --}}
    @include('livewire.payroll.partials.add-employees-dialog', [
        'pickerNote' => 'Tick everyone this payroll covers. Unticking an employee removes them from it.',
    ])

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
                    Incentives and bonuses
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
                                    <p class="mt-1 text-xs text-muted-foreground">Proposed <span class="num">{{ PayrollData::peso($proposedIncentives[$type->id]) }}</span> · {{ trim((string) ($incentiveForm[$type->id] ?? '')) === '' ? 'leave empty to pay it as proposed; it cannot be set to 0 here' : 'the typed amount replaces it' }}</p>
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
                    Manage incentive types
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
