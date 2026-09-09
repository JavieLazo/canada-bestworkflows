{{--
    One payroll, being created or opened again — the port of
    payroll/run-workspace.tsx + add-employees-dialog.tsx.

    Fidelity notes where shadcn had no Flux twin:
      - DraftMoneyInput is a bare <input> carrying the source's exact geometry
        (h-8, min-w-[7.5rem], right-aligned, tabular numerals) — a Flux input's
        wrapper does not fit a 22-column grid cell, which is why these cannot
        be <x-kit.money-input>. It is `type="text"`, not `type="number"`,
        because a number input will not DISPLAY a comma: the browser drops
        every character that is not part of a number, so thousands separators
        are impossible in one. `moneyField` (resources/js/money-field.js)
        supplies the grouping, and the wire call is handed its comma-free
        `raw` — never the displayed `text`, which asMoney would read as 0.
      - Checkboxes (overtime acknowledge, dialog picks) are native inputs
        styled with accent-color; Flux free's checkbox binds via wire:model,
        and these are action-driven like the source's onCheckedChange.
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
    use Illuminate\Support\Facades\Storage;

    $photoUrl = static fn ($employee): ?string => $employee?->photo_path
        ? Storage::disk('public')->url($employee->photo_path)
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
                <flux:button variant="outline" icon="arrow-down-tray" wire:click="exportCsv">
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
                <flux:button
                    variant="primary"
                    icon="lock-closed"
                    wire:click="finalize"
                    wire:loading.attr="disabled"
                    :disabled="count($members) === 0 || $cutoffError !== null || $invalidDraft !== null || $readinessIssue !== null"
                >
                    Finalize
                </flux:button>
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

        @if ($cutoffError)
            <p role="alert" class="border-b border-danger/20 bg-danger-soft px-5 py-3 text-sm text-danger">
                {{ $cutoffError }}
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
            members are not paginated, so the cap was the only thing standing
            between the reader and the whole payroll, and a payroll is read as
            a whole — you are looking for the row that is wrong.

            hris-table-wrap STAYS, unlike the DTR's table, and the difference
            is width. Twenty-two columns and eight input boxes come to roughly
            2,500px, so letting them out of the card would scroll the page
            sideways and take the summary tiles and the Finalize button with
            them. The x-axis belongs inside the card here.

            The cost of keeping it is the same one the DTR pays for its own
            reasons: `overflow-x` makes this wrap a scrollport, hris-table pins
            its thead to the nearest one, and with no height cap that
            scrollport never scrolls — so the header rides up with the page.
            It did not pin before this change either.
        --}}
        <div class="hris-table-wrap">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th rowspan="2">Employee</th>
                        <th colspan="3" class="text-center">Days</th>
                        <th colspan="8" class="text-center">Earnings</th>
                        <th colspan="8" class="text-center">Deductions</th>
                        <th rowspan="2" class="text-right">Net Pay</th>
                        <th rowspan="2" class="text-right">Actions</th>
                    </tr>
                    <tr>
                        <th class="text-right">Work</th>
                        <th class="text-right">Holiday</th>
                        <th class="text-right">Rest</th>

                        <th class="text-right">Basic Salary</th>
                        <th class="text-right">Allowance</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">OT Pay</th>
                        <th class="text-right">NSD</th>
                        <th class="text-right">Leave</th>
                        <th class="text-right">Others</th>
                        <th class="text-right">Gross Income</th>

                        <th class="text-right">SSS</th>
                        <th class="text-right">PhilHealth</th>
                        <th class="text-right">Pag-IBIG</th>
                        <th class="text-right">WHT</th>
                        <th class="text-right">Others</th>
                        <th class="text-right">Undertimes</th>
                        <th class="text-right">Late</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($lineCount === 0)
                        <tr wire:key="empty">
                            <td colspan="22" class="py-10 text-center text-muted-foreground">
                                {{ $cutoffError ?? 'No employee on this payroll yet.' }}
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
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ $nameForInput }}</p>
                                        <p class="num truncate text-xs text-muted-foreground">{{ $e?->employee_code ?? '—' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="num text-right">{{ PayrollData::dayCount($p->work_days) }}</td>
                            <td class="num text-right">{{ PayrollData::dayCount($p->holiday_days) }}</td>
                            <td class="num text-right">{{ PayrollData::dayCount($p->rest_days) }}</td>

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
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <span
                                        class="{{ $p->overtime_acknowledged ? 'num text-success' : 'num text-muted-foreground line-through' }}"
                                        @unless ($p->overtime_acknowledged) title="Not acknowledged — excluded from gross income." @endunless
                                    >
                                        {{ PayrollData::peso($p->overtime_pay) }}
                                    </span>
                                    @if (! $finalized)
                                        <input
                                            type="checkbox"
                                            aria-label="Acknowledge overtime for {{ $nameForInput }}"
                                            class="size-4 shrink-0 rounded border-border accent-[var(--color-primary)]"
                                            @checked($p->overtime_acknowledged)
                                            @disabled((float) $p->overtime_pay === 0.0)
                                            wire:change="acknowledgeOvertime('{{ $p->employee_id }}', $event.target.checked)"
                                        />
                                    @endif
                                </div>
                            </td>
                            @foreach (PayrollData::EARNING_FIELDS as $field => $label)
                                @if ($finalized)
                                    <td class="num text-right">{{ PayrollData::peso($p->{$field}) }}</td>
                                @else
                                    <td class="text-right">
                                        <input
                                            type="text" inputmode="decimal" autocomplete="off"
                                            x-data="moneyField(@js((string) $p->{$field}))"
                                            x-bind:value="text"
                                            x-on:input="onInput($event)"
                                            x-on:focus="$event.target.select()"
                                            x-on:blur="settle()"
                                            aria-label="{{ $nameForInput }} {{ $label }}"
                                            x-on:change="$wire.updateEarning('{{ $p->employee_id }}', '{{ $field }}', raw)"
                                            class="num h-8 w-full min-w-[7.5rem] rounded-md border border-border bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                        />
                                    </td>
                                @endif
                            @endforeach
                            <td class="num text-right font-semibold">{{ PayrollData::peso($p->gross_pay) }}</td>

                            @foreach (PayrollData::DEDUCTION_LABELS as $field => $label)
                                @if ($finalized)
                                    <td class="num text-right text-danger">{{ PayrollData::peso($p->{$field}) }}</td>
                                @else
                                    <td class="text-right">
                                        <input
                                            type="text" inputmode="decimal" autocomplete="off"
                                            x-data="moneyField(@js((string) $p->{$field}))"
                                            x-bind:value="text"
                                            x-on:input="onInput($event)"
                                            x-on:focus="$event.target.select()"
                                            x-on:blur="settle()"
                                            aria-label="{{ $nameForInput }} {{ $label }}"
                                            x-on:change="$wire.updateDeduction('{{ $p->employee_id }}', '{{ $field }}', raw)"
                                            class="num h-8 w-full min-w-[7.5rem] rounded-md border border-border bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                        />
                                    </td>
                                @endif
                            @endforeach
                            <td class="num text-right text-danger">{{ PayrollData::peso($p->undertime_deduction) }}</td>
                            <td class="num text-right text-danger">{{ PayrollData::peso($p->late_deduction) }}</td>
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

                            <td class="num text-right">{{ PayrollData::peso($totals['basic_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['allowances']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['total_base_pay']) }}</td>
                            <td class="num text-right text-success">{{ PayrollData::peso($totals['overtime_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['night_differential']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['leave_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['other_earnings']) }}</td>
                            <td class="num text-right font-semibold">{{ PayrollData::peso($totals['gross_pay']) }}</td>

                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['sss']) }}</td>
                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['philhealth']) }}</td>
                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['pagibig']) }}</td>
                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['withholding_tax']) }}</td>
                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['other_deductions']) }}</td>
                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['undertime_deduction']) }}</td>
                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['late_deduction']) }}</td>
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

    {{-- Add-employees dialog (add-employees-dialog.tsx). --}}
    <flux:modal wire:model.self="picking" class="w-full max-w-3xl">
        <div class="space-y-4">
            <div class="space-y-1.5">
                <h2 class="text-lg font-semibold leading-none tracking-tight">Add employees</h2>
                <p class="text-sm text-muted-foreground">
                    Tick everyone this payroll covers. Unticking an employee removes them from it.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <flux:input
                    aria-label="Search employees"
                    placeholder="Search code, name or position"
                    class="w-full min-w-[12rem] max-w-xs flex-1"
                    wire:model.live="pickSearch"
                />
                <x-kit.filter-select label="Filter by position" class="w-[14rem]" wire:model.live="pickPosition">
                    <flux:select.option value="ALL">All positions</flux:select.option>
                    @foreach ($pickerPositions as $position)
                        <flux:select.option value="{{ $position }}">{{ $position }}</flux:select.option>
                    @endforeach
                </x-kit.filter-select>
                <span class="num ml-auto text-xs text-muted-foreground">{{ count($picked) }} selected</span>
            </div>

            <div class="hris-table-wrap max-h-[22rem] overflow-y-auto rounded-md border border-border">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th class="w-10">
                                <input
                                    type="checkbox"
                                    aria-label="Select everyone shown"
                                    class="size-4 rounded border-border accent-[var(--color-primary)]"
                                    @checked($allShown)
                                    @disabled($pickerRows->isEmpty())
                                    wire:click="togglePickShown"
                                />
                            </th>
                            <th>ID</th>
                            <th>Employee</th>
                            <th>Position</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($pickerRows->isEmpty())
                            <tr wire:key="pick-empty">
                                <td colspan="4" class="py-10 text-center text-muted-foreground">
                                    No employee matches those filters.
                                </td>
                            </tr>
                        @endif
                        @foreach ($pickerRows as $e)
                            @php $on = in_array($e->id, $picked, true); @endphp
                            <tr
                                wire:key="pick-{{ $e->id }}"
                                class="cursor-pointer"
                                aria-selected="{{ $on ? 'true' : 'false' }}"
                                wire:click="togglePick('{{ $e->id }}')"
                            >
                                <td>
                                    <input
                                        type="checkbox"
                                        aria-label="Select {{ PayrollData::employeeName($e) }}"
                                        class="size-4 rounded border-border accent-[var(--color-primary)]"
                                        @checked($on)
                                        x-on:click.stop
                                        wire:click="togglePick('{{ $e->id }}')"
                                    />
                                </td>
                                <td class="num whitespace-nowrap">{{ $e->employee_code }}</td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                            @if ($photoUrl($e))
                                                <img src="{{ $photoUrl($e) }}" alt="{{ PayrollData::employeeName($e) }}" class="h-full w-full object-cover" />
                                            @else
                                                <span class="num text-xs font-semibold">{{ PayrollData::initials($e) }}</span>
                                            @endif
                                        </span>
                                        <span class="truncate font-medium">{{ PayrollData::employeeName($e) }}</span>
                                    </div>
                                </td>
                                <td class="text-muted-foreground">{{ $e->position ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end gap-2 border-t border-border pt-4">
                <flux:button variant="ghost" wire:click="$set('picking', false)">
                    Cancel
                </flux:button>
                <flux:button variant="primary" icon="check" wire:click="applyPicked">
                    Save selection
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
