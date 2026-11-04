{{--
    One 13th month run — "13th Month Payroll", with no counterpart in the
    source. Laid out like the regular workspace: the name and the year, the
    members' grid (basic pay in EMS, the typed outside figure, the year's
    total, 1/12 of it, its tax-exempt part and the excess over PHP 90,000, the
    withholding — computed, or typed over — and net pay), Save / Finalize /
    Export, and the shared add-employees dialog. Every figure comes from
    ThirteenthMonthData; the two typed cells are money-field inputs like the
    regular grid's.
--}}
@php
    use App\Livewire\Payroll\Support\PayrollData;
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
                :href="route('payroll.thirteenth.index')"
                wire:navigate
            >
                13th Month Payroll
            </flux:button>
            <h1 class="text-2xl font-bold tracking-tight">
                {{ $run ? $run->name : 'Create 13th month payroll' }}
                @if ($run)
                    <span class="{{ $finalized ? 'pill pill-success' : 'pill pill-warning' }} ml-3 align-middle">
                        {{ $run->status }}
                    </span>
                @endif
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ $finalized
                    ? 'Finalized — these payslips are posted and no longer change.'
                    : 'One twelfth of the basic pay each employee earned in the year, tax-free up to PHP 90,000 of benefits.' }}
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
                        :disabled="count($members) === 0 || $cutoffError !== null || $issue !== null"
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
                    placeholder="e.g. 13th Month Pay 2026"
                    wire:model.live.debounce.500ms="name"
                    :disabled="$finalized"
                />
            </div>
            <div class="w-[9rem] space-y-2">
                <flux:label>Year</flux:label>
                {{-- The year is chosen once: a saved run keeps its year. --}}
                <x-kit.select aria-label="Year" wire:model.live="year" :disabled="$finalized || $run !== null">
                    @foreach ($years as $option)
                        <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                    @endforeach
                </x-kit.select>
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
            </p>
        @endif
        {{-- A warning, never a block: the withholding table's figures are not confirmed through 31 December (StatutoryTables). --}}
        @if ($statutoryWarning)
            <p role="status" data-statutory-warning class="border-b border-warning/20 bg-warning-soft px-5 py-3 text-sm text-foreground">
                {{ $statutoryWarning }}
            </p>
        @endif
        @if ($issue)
            <p role="alert" class="border-b border-danger/20 bg-danger-soft px-5 py-3 text-sm text-danger">
                {{ $issue['employee'] }}: {{ $issue['message'] }}
            </p>
        @endif

        @if ($lineCount > 0)
            <dl class="grid gap-px border-b border-border bg-border sm:grid-cols-2 lg:grid-cols-4">
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Employees</dt>
                    <dd class="num mt-1 text-xl font-bold">{{ $lineCount }}</dd>
                </div>
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">13th month pay</dt>
                    <dd class="num mt-1 text-xl font-bold">{{ PayrollData::peso($totals['gross_pay']) }}</dd>
                </div>
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Withholding tax</dt>
                    <dd class="num mt-1 text-xl font-bold text-danger">{{ PayrollData::peso($totals['total_deductions']) }}</dd>
                </div>
                <div class="bg-card px-5 py-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Net pay</dt>
                    <dd class="num mt-1 text-xl font-bold text-success">{{ PayrollData::peso($totals['net_pay']) }}</dd>
                </div>
            </dl>
        @endif

        {{-- The x-axis scrolls inside the card, the Employee column pinned,
             exactly as on the regular workspace. --}}
        <div class="hris-table-wrap">
            <table class="hris-table hris-table-pin-first">
                {{-- A draft groups its three basic-pay columns under one header, as
                     the regular grid groups Days / Earnings / Deductions: the three
                     long labels on one row made the grid 15px wider than the card at
                     1920px. --}}
                <thead>
                    <tr>
                        <th @unless ($finalized) rowspan="2" @endunless>Employee</th>
                        @unless ($finalized)
                            <th colspan="3" class="text-center">Basic pay</th>
                        @endunless
                        <th @unless ($finalized) rowspan="2" @endunless class="text-right">13th month</th>
                        <th @unless ($finalized) rowspan="2" @endunless class="text-right">Tax-exempt</th>
                        <th @unless ($finalized) rowspan="2" @endunless class="text-right">Over PHP 90,000</th>
                        <th @unless ($finalized) rowspan="2" @endunless class="text-right">Withholding</th>
                        <th @unless ($finalized) rowspan="2" @endunless class="text-right">Net Pay</th>
                        <th @unless ($finalized) rowspan="2" @endunless class="text-right">Actions</th>
                    </tr>
                    @unless ($finalized)
                        <tr>
                            <th class="text-right">In EMS</th>
                            <th class="text-right">Outside EMS</th>
                            <th class="text-right">For the year</th>
                        </tr>
                    @endunless
                </thead>
                <tbody>
                    @if ($lineCount === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="$finalized ? 7 : 10" :title="$cutoffError !== null ? 'Nothing is computed while the cut-off is refused.' : 'No employee on this payroll yet.'" />
                        </tr>
                    @endif
                    @foreach ($lines as $p)
                        @php
                            $e = $employees->get($p->employee_id);
                            $nameForInput = PayrollData::employeeName($e);
                            $basis = $statutory[$p->employee_id]['basis'] ?? null;
                            $taxTyped = isset($withholding[$p->employee_id]);
                        @endphp
                        <tr
                            wire:key="line-{{ $p->employee_id }}"
                            @if ($finalized && $run)
                                tabindex="0"
                                aria-label="Open payslip for {{ $nameForInput }}"
                                class="cursor-pointer focus:outline-none focus-visible:bg-accent"
                                x-on:click="Livewire.navigate('{{ route('payroll.thirteenth.payslip', ['id' => $run->id, 'employeeId' => $p->employee_id]) }}')"
                                x-on:keydown.enter.prevent="Livewire.navigate('{{ route('payroll.thirteenth.payslip', ['id' => $run->id, 'employeeId' => $p->employee_id]) }}')"
                                x-on:keydown.space.prevent="Livewire.navigate('{{ route('payroll.thirteenth.payslip', ['id' => $run->id, 'employeeId' => $p->employee_id]) }}')"
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
                                    @php $identity = \App\Support\EmployeeIdentity::besideName($e); @endphp
                                    <div class="min-w-0 max-w-[7.5rem] md:max-w-none">
                                        <p class="truncate font-medium">{{ $nameForInput }}</p>
                                        @if ($identity !== '')
                                            <p class="num truncate text-xs text-muted-foreground">{{ $identity }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            @unless ($finalized)
                                <td class="num text-right">{{ PayrollData::peso($basis['ems'] ?? 0) }}</td>
                                <td class="text-right">
                                    <input
                                        type="text" inputmode="decimal" autocomplete="off"
                                        wire:key="outside-{{ $p->employee_id }}"
                                        x-data="moneyField(@js(isset($outsideBasicPay[$p->employee_id]) ? (string) $outsideBasicPay[$p->employee_id] : ''))"
                                        x-bind:value="text"
                                        x-on:input="onInput($event)"
                                        x-on:focus="$event.target.select()"
                                        x-on:blur="settle()"
                                        aria-label="{{ $nameForInput }} basic pay earned outside EMS"
                                        x-on:change="$wire.updateOutside('{{ $p->employee_id }}', raw)"
                                        class="num h-8 w-full min-w-[7.5rem] rounded-md border border-border bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                    />
                                </td>
                                <td class="num text-right">{{ PayrollData::peso($basis['total'] ?? 0) }}</td>
                            @endunless
                            <td class="num text-right font-semibold">{{ PayrollData::peso($p->thirteenth_month) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($p->exempt_benefits) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($p->taxable_benefits_excess) }}</td>
                            @if ($finalized)
                                <td class="num text-right text-danger">{{ PayrollData::peso($p->withholding_tax) }}</td>
                            @else
                                {{-- Computed unless typed; a typed figure is marked, and the
                                     marker clears it back to the computed one. --}}
                                <td class="text-right" @if ($taxTyped) data-overridden="withholding_tax" @endif>
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($taxTyped)
                                            <button
                                                type="button"
                                                wire:click="updateWithholding('{{ $p->employee_id }}', '')"
                                                title="Typed. Computed: {{ PayrollData::peso($statutory[$p->employee_id]['computed']['withholding_tax'] ?? 0) }}. Click to use the computed figure."
                                                aria-label="Typed: use the computed withholding ({{ PayrollData::peso($statutory[$p->employee_id]['computed']['withholding_tax'] ?? 0) }}) for {{ $nameForInput }}"
                                                class="pill pill-caution shrink-0 cursor-pointer focus:outline-none focus:ring-2 focus:ring-ring"
                                            >
                                                Typed
                                            </button>
                                        @endif
                                        <input
                                            type="text" inputmode="decimal" autocomplete="off"
                                            wire:key="tax-{{ $p->employee_id }}-{{ $taxTyped ? 'typed' : 'computed' }}"
                                            x-data="moneyField(@js((string) $p->withholding_tax), @js($taxTyped ? null : (string) $p->withholding_tax))"
                                            x-bind:value="text"
                                            x-on:input="onInput($event)"
                                            x-on:focus="$event.target.select()"
                                            x-on:blur="settle()"
                                            aria-label="{{ $nameForInput }} withholding tax"
                                            x-on:change="$wire.updateWithholding('{{ $p->employee_id }}', raw); restoreIfEmpty()"
                                            class="num h-8 w-full min-w-[7.5rem] rounded-md border {{ $taxTyped ? 'border-warning' : 'border-border' }} bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                        />
                                    </div>
                                </td>
                            @endif
                            <td class="num text-right font-bold {{ $p->net_pay < 0 ? 'text-danger' : '' }}">{{ PayrollData::peso($p->net_pay) }}</td>
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
                            @unless ($finalized)
                                <td></td>
                                <td></td>
                                <td></td>
                            @endunless
                            <td class="num text-right">{{ PayrollData::peso($totals['gross_pay']) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($totals['exempt_benefits']) }}</td>
                            <td></td>
                            <td class="num text-right text-danger">{{ PayrollData::peso($totals['withholding_tax']) }}</td>
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

    @include('livewire.payroll.partials.add-employees-dialog', [
        'pickerNote' => "Everyone employed at some point of {$year}, resigned staff included. Unticking an employee removes them from this run.",
    ])
</div>
