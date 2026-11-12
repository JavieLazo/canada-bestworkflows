{{--
    One employee's pay, on one page: Pay at a glance (their salary and the
    rules that price their days, each figure marked Company, their status
    or Own), Contributions & tax (read as payroll applies them, the
    percentage deductions among them, and edited in place by an all-data
    PAYROLL holder), Allowances & incentives (ONE table: the company
    benefits they qualify for, the incentives assigned to them and their
    own recurring allowances), Loans & other deductions (their own
    recurring deductions), and last the Tax year. A row of links to the five
    cards, drawn as the system's view switch, sits under the employee's name
    and holds under the app's top bar while the page scrolls. "Set by" is
    the column
    that says whose a line is; "From" is only ever a date. The cards'
    buttons are all outline: no card's action outranks another's.
--}}
@php
    use App\Livewire\Records\Support\EmployeeRates;
    use App\Livewire\Records\Support\Hris;
    use App\Livewire\Records\Support\PaySettingsText;
    use App\Livewire\Records\Support\RecurringLineText;
    use App\Modules\Payroll\Application\PayrollPeriodText;
    use App\Modules\Payroll\Application\Queries\RecurringLineRecords;
    use App\Modules\Payroll\Domain\Recurring\RecurringLine;
    use App\Modules\Payroll\Application\GovDeductionInput as GovInput;
    use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings as GovSettings;
    use App\Support\EmployeeName;

    $differs = static fn (array $keys): bool => array_intersect($customized, $keys) !== [];
    $dt = 'text-xs font-medium uppercase tracking-wide text-muted-foreground';
    $status = $settings?->employment_status;
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0 space-y-2">
            <flux:link :href="route('records.employee-pay-settings')" wire:navigate class="text-sm">
                ← Employee Pay Settings
            </flux:link>
            @if ($settings !== null)
                <h1 class="text-2xl font-bold tracking-tight">{{ EmployeeName::display($settings) }}</h1>
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                    <span class="num">{{ $settings->employee_code }}</span>
                    @if ($settings->employee_id_no)
                        <span aria-hidden="true">·</span>
                        <span>Employee ID <span class="num">{{ $settings->employee_id_no }}</span></span>
                    @endif
                    <span class="pill pill-muted">{{ $settings->employment_status ?: 'No employment status' }}</span>
                    @unless ($settings->is_active)
                        <span class="pill pill-caution">Inactive</span>
                    @endunless
                </p>
            @else
                <h1 class="text-2xl font-bold tracking-tight">Employee Pay Settings</h1>
            @endif
        </div>
        @if ($canRead)
            <div class="w-full max-w-xs print:hidden">
                <livewire:kit.employee-picker
                    name="settings-employee"
                    placeholder="Open another employee"
                    aria-label="Open another employee's pay settings"
                    wire:key="settings-employee-picker"
                />
            </div>
        @endif
    </div>

    @unless ($canRead)
        <section class="hris-card px-5 py-6">
            <p class="text-sm text-muted-foreground">
                Employee pay settings are pay data. They are shown to payroll officers with all-data access to Payroll.
            </p>
        </section>
    @else
        {{-- The page is five cards long, so its cards are one click away and stay in reach while it scrolls: the
             row of links, drawn as the system's view switch, holds under the app's top bar on that bar's own
             backdrop. Plain links to the cards' own ids. --}}
        <nav aria-label="Sections of this page" class="sticky top-14 z-10 -mx-4 bg-background/95 px-4 py-2 backdrop-blur sm:-mx-6 sm:px-6 print:hidden" data-pay-sections>
            <div class="inline-flex max-w-full items-center gap-1 overflow-x-auto rounded-lg bg-muted p-1 text-sm text-muted-foreground [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach ([
                    'pay-glance' => 'Pay at a glance',
                    'pay-contributions' => 'Contributions & tax',
                    'pay-allowances' => 'Allowances & incentives',
                    'pay-recurring' => 'Loans & other deductions',
                    'pay-tax-year' => 'Tax year',
                ] as $anchor => $anchorLabel)
                    <a href="#{{ $anchor }}" class="inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-md px-3 py-1.5 font-medium transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ $anchorLabel }}</a>
                @endforeach
            </div>
        </nav>

        @php
            $source = $rates['source'];
            $next = $rates['next'];
            $ownRates = $source === EmployeeRates::OWN || $next !== null;
        @endphp
        <section id="pay-glance" class="hris-card scroll-mt-28 overflow-hidden" aria-labelledby="pay-glance-heading" data-salary-card>
            <x-payroll.settings.head title="Pay at a glance" heading-id="pay-glance-heading">
                <x-slot:note>
                    <span class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                        Each figure says where it comes from:
                        @include('livewire.records.partials.pay-source', ['from' => 'company', 'status' => $status]) the company settings,
                        @include('livewire.records.partials.pay-source', ['from' => 'status', 'status' => $status]) their employment status,
                        @include('livewire.records.partials.pay-source', ['from' => 'own', 'status' => $status]) set for them alone.
                    </span>
                </x-slot:note>
                @unless ($hourlyEditing)
                    <flux:button variant="outline" icon="pencil" wire:click="startEditHourly">Edit hourly rate</flux:button>
                @endunless
                @if ($salaryReady)
                    <flux:button variant="outline" icon="pencil" wire:click="openSalaryChange">Change salary</flux:button>
                @endif
            </x-payroll.settings.head>
            @unless ($salaryReady)
                <p class="border-b border-border px-5 py-3 text-xs text-muted-foreground">The salary can be changed here once this installation's upgrade finishes. Until then it is changed on Employee Details.</p>
            @endunless
            <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2 xl:grid-cols-4" data-salary-rates>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Monthly rate</dt>
                    <dd class="mt-1">
                        <span class="num text-lg font-semibold">{{ $glance['monthly'] === null ? 'Not set' : Hris::peso($glance['monthly']) }}</span>
                        @unless ($salaryReady)
                            <span class="mt-1 block text-xs text-muted-foreground">Rate Per Month on Employee Details ·
                                <flux:link :href="route('employees.show', ['employee' => $settings->id])" wire:navigate>Change</flux:link>
                            </span>
                        @else
                            <span class="mt-1 block text-xs text-muted-foreground">Rate Per Month on the employee record.</span>
                        @endunless
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Daily rate</dt>
                    <dd class="mt-1">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span @class(['text-lg font-semibold', 'num' => str_starts_with($glance['daily']['text'], '₱')])>{{ $glance['daily']['text'] }}</span>
                            @include('livewire.records.partials.pay-source', ['from' => $glance['daily']['from'], 'status' => $status])
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ $glance['daily']['note'] }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Hourly rate</dt>
                    <dd class="mt-1">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span @class(['text-lg font-semibold', 'num' => str_starts_with($glance['hourly']['text'], '₱')])>{{ $glance['hourly']['text'] }}</span>
                            @include('livewire.records.partials.pay-source', ['from' => $glance['hourly']['from'], 'status' => $status])
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ $glance['hourly']['note'] }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Days, overtime and night hours</dt>
                    <dd class="mt-1">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="text-lg font-semibold">
                                @if ($source === EmployeeRates::OWN)
                                    Their own rates
                                @elseif ($source === EmployeeRates::STATUS)
                                    The {{ $status }} override
                                @else
                                    The company rule
                                @endif
                            </span>
                            @include('livewire.records.partials.pay-source', ['from' => $glance['rules']['from'], 'status' => $status])
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ $glance['rules']['text'] }}@if ($glance['rules']['note'] !== '') · {{ $glance['rules']['note'] }}@endif</span>
                        @if ($next !== null)
                            <span class="block text-xs text-muted-foreground">
                                From <span class="num">{{ $next->effective_from->format('d M Y') }}</span>:
                                {{ $next->params === null ? 'back to their employment status\'s or the company\'s rules' : 'rates of their own' }}
                            </span>
                        @endif
                        @if ($ratesReady)
                            <flux:link :href="route('records.pay-rates', ['for' => $settings->id])" wire:navigate class="mt-1 inline-block text-xs font-medium">
                                {{ $ownRates ? 'Open own rates' : 'Set own rates' }}
                            </flux:link>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($hourlyEditing)
                <form class="grid gap-3 border-t border-border p-5 sm:grid-cols-2" wire:submit="saveHourly">
                    <div class="space-y-2">
                        <flux:label for="pay-hourly">Hourly rate override</flux:label>
                        <x-kit.money-input id="pay-hourly" wire:model="hourlyRate" aria-label="Hourly rate override" />
                        <p class="text-xs text-muted-foreground">Prices overtime, lateness and undertime. Leave it blank to derive it from the daily rate and the shift.</p>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="pay-hourly-reason">Reason</flux:label>
                        <flux:input id="pay-hourly-reason" wire:model="hourlyReason" maxlength="160" placeholder="Why this employee is paid differently" />
                    </div>
                    @if ($hourlyError !== null)
                        <p role="alert" class="text-sm text-danger sm:col-span-2">{{ $hourlyError }}</p>
                    @endif
                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <flux:button variant="ghost" icon="x-mark" wire:click="cancelEdit">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">Save</flux:button>
                    </div>
                </form>
            @endif
        </section>

        <flux:modal wire:model.self="salaryOpen" class="w-full max-w-lg" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'salary-change-heading')">
            <form wire:submit="saveSalaryChange" class="space-y-4">
                <flux:heading size="lg" level="2" id="salary-change-heading">Change salary</flux:heading>
                @include('livewire.payroll.partials.settings-applies-note', ['class' => 'text-sm text-muted-foreground'])
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-2">
                        <flux:label for="salary-monthly">Rate Per Month</flux:label>
                        <x-kit.money-input id="salary-monthly" wire:model="salaryForm.monthly" aria-label="Rate Per Month" />
                    </div>
                    <div class="space-y-2">
                        <flux:label for="salary-daily">Rate Per Day</flux:label>
                        <x-kit.money-input id="salary-daily" wire:model="salaryForm.daily" aria-label="Rate Per Day" />
                        <p class="text-xs text-muted-foreground">Leave blank to work the day out from the Rate Per Month.</p>
                    </div>
                </div>
                @if ($salaryError !== null)
                    <p role="alert" class="text-sm text-danger">{{ $salaryError }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="outline" wire:click="$set('salaryOpen', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
        </flux:modal>

        <section id="pay-contributions" class="hris-card scroll-mt-28 overflow-hidden" aria-labelledby="pay-contributions-heading" data-contributions-card>
            <x-payroll.settings.head
                title="Contributions & tax"
                heading-id="pay-contributions-heading"
                description="How SSS, PhilHealth, Pag-IBIG and withholding tax are deducted for this employee."
            >
                @if (! $editing && $govReady)
                    <flux:button variant="outline" icon="pencil" wire:click="startEdit">Edit</flux:button>
                @endif
            </x-payroll.settings.head>

            @unless ($govReady)
                <p class="border-b border-border px-5 py-3 text-xs text-muted-foreground">These settings can be edited once this installation's upgrade finishes. Until then the settings on the record apply.</p>
            @endunless

            @unless ($editing)
                @php
                    // How many of the rows below are the employee's own, by the same test as each row's pill.
                    $ownRows = ($differs(PaySettingsText::withholdingKeys()) ? 1 : 0)
                        + count(array_filter(array_keys(GovSettings::CONTRIBUTIONS), static fn (string $key): bool => $differs(PaySettingsText::contributionKeys($key))))
                        + count(array_filter($percentDeductions, static fn (array $deduction): bool => $deduction['own'] !== null));
                    $allRows = count(GovSettings::CONTRIBUTIONS) + 1 + count($percentDeductions);
                @endphp
                <p class="px-5 py-3 text-xs text-muted-foreground" data-gov-in-force>
                    @if ($ownRows === 0)
                        The standard settings apply.
                    @elseif ($ownRows === $allRows)
                        All {{ $allRows }} set for this employee.
                    @else
                        {{ $ownRows }} of {{ $allRows }} set for this employee. The rest follow the company settings.
                    @endif
                </p>
                <div class="hris-table-wrap">
                    <table class="hris-table">
                        <thead><tr><th>Deduction</th><th>How it is deducted</th><th>Set by</th></tr></thead>
                        <tbody>
                            @foreach (GovSettings::CONTRIBUTIONS as $key => $name)
                                <tr wire:key="read-{{ $key }}">
                                    <td class="font-medium">{{ $name }}</td>
                                    <td>{{ PaySettingsText::contribution($gov, $key) }}</td>
                                    <td>@include('livewire.records.partials.pay-source', ['from' => $differs(PaySettingsText::contributionKeys($key)) ? 'own' : 'company', 'status' => $status])</td>
                                </tr>
                            @endforeach
                            <tr wire:key="read-withholding">
                                <td class="font-medium">Withholding tax</td>
                                <td>{{ PaySettingsText::withholding($gov) }}</td>
                                <td>@include('livewire.records.partials.pay-source', ['from' => $differs(PaySettingsText::withholdingKeys()) ? 'own' : 'company', 'status' => $status])</td>
                            </tr>
                            @foreach ($percentDeductions as $deduction)
                                <tr wire:key="read-percent-{{ $deduction['id'] }}">
                                    <td class="font-medium">{{ $deduction['name'] }}</td>
                                    <td>
                                        @if ($deduction['own'] === null)
                                            {{ $deduction['detail'] }}
                                        @elseif ($deduction['own'] <= 0.0)
                                            Not a member (no employee or employer share)
                                        @else
                                            Own rate {{ Hris::moneyText($deduction['own']) }}% · listed: {{ $deduction['detail'] }}
                                        @endif
                                        @if (($deduction['amount'] ?? 0.0) > 0.0)
                                            <span class="block text-xs text-muted-foreground"><span class="num">{{ Hris::peso($deduction['amount']) }}</span> this cut-off</span>
                                        @endif
                                    </td>
                                    <td>@include('livewire.records.partials.pay-source', ['from' => $deduction['own'] === null ? 'company' : 'own', 'status' => $status])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <form class="grid gap-5 p-5" wire:submit="save" data-gov-form>
                    <p class="text-xs text-muted-foreground">A fixed share is the month's figure in place of the table's; leave it blank to use the table.</p>
                    <div class="grid gap-5 xl:grid-cols-3">
                    @foreach (GovSettings::CONTRIBUTIONS as $key => $name)
                        @php $on = (bool) ($form["{$key}_deduct"] ?? false); @endphp
                        <fieldset class="min-w-0 rounded-md border border-border p-4" wire:key="edit-{{ $key }}">
                            <legend class="px-1 text-sm font-semibold">{{ $name }}</legend>
                            <div class="flex items-center gap-3">
                                <flux:switch id="pay-{{ $key }}-deduct" wire:model.live="form.{{ $key }}_deduct" />
                                <flux:label for="pay-{{ $key }}-deduct">Deduct {{ $name }}</flux:label>
                            </div>
                            <div class="mt-3 grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                                <div class="space-y-2">
                                    <flux:label for="pay-{{ $key }}-ee">Fixed employee share</flux:label>
                                    <x-kit.money-input id="pay-{{ $key }}-ee" wire:model="form.{{ $key }}_fixed_ee" aria-label="Fixed {{ $name }} employee share" placeholder="From the table" :disabled="! $on" />
                                </div>
                                <div class="space-y-2">
                                    <flux:label for="pay-{{ $key }}-er">Fixed employer share</flux:label>
                                    <x-kit.money-input id="pay-{{ $key }}-er" wire:model="form.{{ $key }}_fixed_er" aria-label="Fixed {{ $name }} employer share" placeholder="From the table" :disabled="! $on" />
                                </div>
                                <x-kit.select label="Deducted" wire:model="form.{{ $key }}_mode" :disabled="! $on">
                                    @foreach (GovInput::MODES as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </x-kit.select>
                            </div>
                        </fieldset>
                    @endforeach
                    </div>

                    @php
                        $taxOn = (bool) ($form['tax_deduct'] ?? false);
                        $method = (string) ($form['tax_method'] ?? GovInput::METHOD_TABLE);
                    @endphp
                    <div class="grid gap-5 xl:grid-cols-2 xl:items-start">
                    <fieldset class="min-w-0 rounded-md border border-border p-4">
                        <legend class="px-1 text-sm font-semibold">Withholding tax</legend>
                        <div class="flex items-center gap-3">
                            <flux:switch id="pay-tax-deduct" wire:model.live="form.tax_deduct" />
                            <flux:label for="pay-tax-deduct">Deduct withholding tax</flux:label>
                        </div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <x-kit.select label="Tax method" wire:model.live="form.tax_method" :disabled="! $taxOn">
                                @foreach (GovInput::METHODS as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                            <div class="space-y-2">
                                @if ($method === GovInput::METHOD_FIXED)
                                    <flux:label for="pay-tax-amount">Tax per payslip</flux:label>
                                    <x-kit.money-input id="pay-tax-amount" wire:model="form.tax_amount" aria-label="Tax per payslip" :disabled="! $taxOn" />
                                @elseif ($method === GovInput::METHOD_PERCENT)
                                    <flux:label for="pay-tax-amount">Percent of taxable compensation</flux:label>
                                    <flux:input id="pay-tax-amount" type="number" min="0" max="100" step="0.01" class:input="num" wire:model="form.tax_amount" aria-label="Percent of taxable compensation" :disabled="! $taxOn" />
                                @elseif ($method === GovInput::METHOD_WHOLE_YEAR)
                                    <x-kit.select label="Remaining tax spread" wire:model="form.tax_spread" :disabled="! $taxOn">
                                        @foreach (GovInput::SPREADS as $value => $label)
                                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </x-kit.select>
                                    <p class="text-xs text-muted-foreground">The year's tax is projected from pay posted so far and the payrolls left; the year's last payroll makes the year-end adjustment: it withholds the exact remainder or refunds any excess.</p>
                                @endif
                            </div>
                        </div>
                    </fieldset>

                    @if ($percentRows !== [])
                        <fieldset class="min-w-0 rounded-md border border-border p-4">
                            <legend class="px-1 text-sm font-semibold">Percentage deductions</legend>
                            <p class="text-xs text-muted-foreground">A rate replaces the employee share only. Blank uses the listed rate. 0 means not a member, so neither share is taken.</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                @foreach ($percentRows as $rowId => $row)
                                    <div class="space-y-2" wire:key="percent-{{ $rowId }}">
                                        <flux:label for="pay-percent-{{ $rowId }}">{{ $row['name'] }} (%)</flux:label>
                                        <flux:input id="pay-percent-{{ $rowId }}" type="number" min="0" max="100" step="0.01" class:input="num" wire:model="form.percent.{{ $rowId }}" aria-label="{{ $row['name'] }} employee share percent" placeholder="Listed rate" />
                                        <p class="text-xs text-muted-foreground">Listed: {{ $row['detail'] }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                    </div>

                    @include('livewire.payroll.partials.settings-applies-note')

                    @if ($formError !== null)
                        <p role="alert" class="text-sm text-danger">{{ $formError }}</p>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
                        <flux:button variant="outline" icon="arrow-uturn-left" wire:click="useStandard">Reset to standard</flux:button>
                        <div class="flex gap-2">
                            <flux:button variant="ghost" icon="x-mark" wire:click="cancelEdit">Cancel</flux:button>
                            <flux:button type="submit" variant="primary">Save</flux:button>
                        </div>
                    </div>
                </form>
            @endunless

        </section>

        @php
            $allowances = $lines->filter(static fn ($line): bool => $line->kind === RecurringLine::KIND_INCENTIVE);
            $loans = $lines->filter(static fn ($line): bool => $line->kind === RecurringLine::KIND_DEDUCTION);
        @endphp
        {{-- One table for everything payroll adds on its own: the company's scheduled incentives they qualify for,
             the incentives assigned to them for a span of dates, and their own repeating allowances. --}}
        <section id="pay-allowances" class="hris-card scroll-mt-28 overflow-hidden" aria-labelledby="pay-allowances-heading" data-assigned-benefits>
            <x-payroll.settings.head
                title="Allowances & incentives"
                heading-id="pay-allowances-heading"
                description="What payroll adds to their pay on its own."
            >
                @if ($assignReady && $assignableTypes !== [])
                    <flux:button variant="outline" icon="plus" wire:click="openAssign">Assign incentive</flux:button>
                @endif
                @if ($recurringReady)
                    <flux:button variant="outline" icon="plus" wire:click="addLine('INCENTIVE')">Add allowance</flux:button>
                @endif
            </x-payroll.settings.head>
            <div class="hris-table-wrap">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th>Line</th>
                            <th class="text-right">Amount</th>
                            <th>Runs</th>
                            <th>Tax</th>
                            <th>Set by</th>
                            <th>Status</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($benefits as $benefit)
                            <tr wire:key="benefit-{{ $loop->index }}">
                                <td>
                                    <p class="font-medium">{{ $benefit['name'] }}</p>
                                    <p class="text-xs text-muted-foreground">{{ $benefit['note'] }}</p>
                                </td>
                                <td class="num whitespace-nowrap text-right">{{ $benefit['amount'] }}</td>
                                <td>{{ $benefit['when'] }}</td>
                                <td>{{ $benefit['tax'] }}</td>
                                <td>@include('livewire.records.partials.pay-source', ['from' => 'company', 'status' => $status])</td>
                                <td>
                                    @if ($benefit['waiting'])
                                        <span class="pill pill-caution">Upcoming</span>
                                    @else
                                        <span class="pill pill-success">Active</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <flux:link :href="route('records.incentive-types')" wire:navigate class="text-sm font-medium">Open catalog</flux:link>
                                </td>
                            </tr>
                        @endforeach
                        @if ($assignReady)
                            @foreach ($assignments as $row)
                                <tr wire:key="assignment-{{ $row->id }}" data-assignment="{{ $row->id }}">
                                    <td>
                                        <p class="font-medium">{{ $row->type_name }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            Assigned · {{ $row->reason }}
                                            @if ($row->reference !== null)
                                                <span class="block">Ref. {{ $row->reference }}</span>
                                            @endif
                                        </p>
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        @if ($row->amount === null)
                                            <span class="text-muted-foreground">The type's</span>
                                        @else
                                            <span class="num">{{ Hris::peso($row->amount) }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        <span class="whitespace-nowrap">From <span class="num">{{ $row->starts_on->format('d M Y') }}</span></span>
                                        <span class="block text-xs text-muted-foreground">
                                            @if ($row->ends_on === null)
                                                No end date
                                            @else
                                                Until <span class="num">{{ $row->ends_on->format('d M Y') }}</span>
                                            @endif
                                        </span>
                                    </td>
                                    <td>{{ $incentiveTaxes[$row->type_id] ?? '—' }}</td>
                                    <td>
                                        @include('livewire.records.partials.pay-source', ['from' => 'own', 'status' => $status])
                                        <span class="mt-1 block text-xs text-muted-foreground">Recorded by {{ $row->prepared_by_name ?? 'System' }}</span>
                                        @if ($row->status === 'APPROVED' && $row->approved_by_name !== null && $row->approved_by_name !== $row->prepared_by_name)
                                            <span class="block text-xs text-muted-foreground">Approved by {{ $row->approved_by_name }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row->status === 'DRAFT')
                                            <span class="pill pill-caution">Awaiting approval</span>
                                        @elseif ($row->ends_on !== null && $row->ends_on->format('Y-m-d') < $today)
                                            <span class="pill pill-muted">Ended</span>
                                        @elseif ($row->starts_on->format('Y-m-d') > $today)
                                            <span class="pill pill-caution">Upcoming</span>
                                        @else
                                            <span class="pill pill-success">Active</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if ($row->status === 'DRAFT')
                                            <x-kit.row-actions :label="$row->type_name.' assignment'">
                                                <flux:menu.item icon="check" wire:click="approveAssignment('{{ $row->id }}')">Approve</flux:menu.item>
                                                <flux:menu.item icon="trash" variant="danger" wire:click="discardAssignment('{{ $row->id }}')">Discard draft</flux:menu.item>
                                            </x-kit.row-actions>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                        @foreach ($allowances as $line)
                            @include('livewire.records.partials.pay-line-row', ['line' => $line, 'status' => $status, 'withTax' => true])
                        @endforeach
                        @if ($benefits === [] && $allowances->isEmpty() && (! $assignReady || $assignments->isEmpty()))
                            <tr wire:key="allowances-empty">
                                <x-kit.empty-state :colspan="7" title="No allowance or incentive yet." hint="Add an allowance that repeats on every payroll." />
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
            {{-- Why nothing is assigned, or why nothing can be: the Assign incentive button is drawn only for a scheduled type. --}}
            @if (! $assignReady)
                <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">Incentives can be assigned once this installation's upgrade finishes.</p>
            @elseif ($assignableTypes === [])
                <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">
                    {{ $assignments->isEmpty() ? 'No incentive assigned.' : '' }} An incentive can be assigned once
                    <flux:link :href="route('records.incentive-types')" wire:navigate>Incentives &amp; Bonuses</flux:link>
                    lists one paid on a schedule.
                </p>
            @elseif ($assignments->isEmpty())
                <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">No incentive assigned. Assign incentive gives them a scheduled incentive for a span of dates.</p>
            @endif
        </section>

        <flux:modal wire:model.self="assignOpen" class="w-full max-w-lg" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'assign-benefit-heading')">
            {{-- The form, with every scheduled type, renders only while the dialog is open. --}}
            @if ($assignOpen)
            <form wire:submit="saveAssignment" class="space-y-4">
                <flux:heading size="lg" level="2" id="assign-benefit-heading">Assign incentive</flux:heading>
                <x-kit.select label="Incentive" wire:model="assignForm.type_id">
                    <flux:select.option value="">Choose an incentive</flux:select.option>
                    @foreach ($assignableTypes as $typeId => $typeName)
                        <flux:select.option value="{{ $typeId }}">{{ $typeName }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-2">
                        <flux:label for="assign-from">From</flux:label>
                        <x-kit.date-field id="assign-from" wire:model="assignForm.starts_on" aria-label="From" />
                    </div>
                    <div class="space-y-2">
                        <flux:label for="assign-to">To (optional)</flux:label>
                        <x-kit.date-field id="assign-to" wire:model="assignForm.ends_on" aria-label="To" />
                        <p class="text-xs text-muted-foreground">Leave blank for an open-ended assignment.</p>
                    </div>
                </div>
                <div class="space-y-2">
                    <flux:label for="assign-amount">Amount (optional)</flux:label>
                    <x-kit.money-input id="assign-amount" wire:model="assignForm.amount" aria-label="Amount" />
                    <p class="text-xs text-muted-foreground">Leave blank to pay the type's own amount.</p>
                </div>
                <div class="space-y-2">
                    <flux:label for="assign-reason">Reason</flux:label>
                    <flux:input id="assign-reason" wire:model="assignForm.reason" maxlength="160" placeholder="Why this employee receives it" aria-required="true" />
                </div>
                <div class="space-y-2">
                    <flux:label for="assign-reference">Reference (optional)</flux:label>
                    <flux:input id="assign-reference" wire:model="assignForm.reference" maxlength="120" placeholder="The memo or approval" />
                </div>
                @if ($assignError !== null)
                    <p role="alert" class="text-sm text-danger">{{ $assignError }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="outline" wire:click="$set('assignOpen', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
            @endif
        </flux:modal>

        <section id="pay-recurring" class="hris-card scroll-mt-28 overflow-hidden" aria-labelledby="pay-recurring-heading">
            <x-payroll.settings.head
                title="Loans & other deductions"
                heading-id="pay-recurring-heading"
                description="Loans and other amounts taken from their pay. A loan stops once it is paid off."
            >
                @if ($recurringReady)
                    <flux:button variant="outline" icon="plus" wire:click="addLine('DEDUCTION')">Add loan or deduction</flux:button>
                @endif
            </x-payroll.settings.head>
            @unless ($recurringReady)
                <p class="px-5 py-4 text-xs text-muted-foreground">Loans and allowances can be set up once this installation's upgrade finishes.</p>
            @else
                <div class="hris-table-wrap">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Line</th>
                                <th class="text-right">Per payroll</th>
                                <th class="text-right">Balance</th>
                                <th>Runs</th>
                                <th>Set by</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($loans as $line)
                                @include('livewire.records.partials.pay-line-row', ['line' => $line, 'status' => $status, 'withTax' => false])
                            @endforeach
                            @if ($loans->isEmpty())
                                <tr wire:key="lines-empty">
                                    <x-kit.empty-state :colspan="7" title="No loan or deduction yet." hint="Add a loan amortization or a deduction that repeats on every payroll." />
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            @endunless
            @if ($entriesReady && $entries->isNotEmpty())
                <div class="border-t border-border px-5 pt-4 pb-2">
                    <h3 class="text-sm font-semibold">Payments &amp; adjustments outside payroll</h3>
                    <p class="text-xs text-muted-foreground">A payment made straight to the lender lowers what is left; a balance adjustment from the lender's statement raises or lowers it.</p>
                </div>
                <div class="hris-table-wrap" data-loan-entries>
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Loan</th>
                                <th>Entry</th>
                                <th class="text-right">Amount</th>
                                <th>Date</th>
                                <th>Recorded by</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr wire:key="loan-entry-{{ $entry->id }}" data-loan-entry="{{ $entry->id }}">
                                    <td class="font-medium">{{ $entry->line_name }}</td>
                                    <td>
                                        {{ \App\Modules\Payroll\Application\Queries\RecurringLineEntryRecords::KINDS[$entry->kind] ?? $entry->kind }}
                                        <span class="block text-xs text-muted-foreground">
                                            {{ $entry->reason }}
                                            @if ($entry->reference !== null)
                                                · Ref. {{ $entry->reference }}
                                            @endif
                                        </span>
                                    </td>
                                    <td class="num text-right">{{ Hris::peso($entry->amount) }}</td>
                                    <td class="num whitespace-nowrap">{{ $entry->entry_date->format('d M Y') }}</td>
                                    <td>
                                        {{ $entry->prepared_by_name ?? 'System' }}
                                        @if ($entry->status === 'APPROVED' && $entry->approved_by_name !== null)
                                            <span class="block text-xs text-muted-foreground">Approved by {{ $entry->approved_by_name }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($entry->status === 'DRAFT')
                                            <span class="pill pill-caution">Awaiting approval</span>
                                        @else
                                            <span class="pill pill-success">Approved</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if ($entry->status === 'DRAFT')
                                            <x-kit.row-actions :label="$entry->line_name.' entry'">
                                                <flux:menu.item icon="check" wire:click="approveEntry('{{ $entry->id }}')">Approve</flux:menu.item>
                                                <flux:menu.item icon="trash" variant="danger" wire:click="discardEntry('{{ $entry->id }}')">Discard draft</flux:menu.item>
                                            </x-kit.row-actions>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <flux:modal wire:model.self="entryOpen" class="w-full max-w-lg" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'loan-entry-heading')">
            @if ($entryOpen)
            <form wire:submit="saveEntry" class="space-y-4">
                <flux:heading size="lg" level="2" id="loan-entry-heading">Record payment or adjustment</flux:heading>
                <x-kit.select label="Entry" wire:model.live="entryForm.kind">
                    @foreach (\App\Modules\Payroll\Application\Queries\RecurringLineEntryRecords::KINDS as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-2">
                        <flux:label for="entry-amount">Amount</flux:label>
                        <x-kit.money-input id="entry-amount" wire:model="entryForm.amount" aria-label="Amount" />
                        <p class="text-xs text-muted-foreground">{{ ($entryForm['kind'] ?? '') === 'ADJUSTMENT' ? 'Above zero when more is owed, below zero when less.' : 'What was paid straight to the lender.' }}</p>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="entry-date">Date</flux:label>
                        <x-kit.date-field id="entry-date" wire:model="entryForm.entry_date" aria-label="Date" />
                    </div>
                </div>
                <div class="space-y-2">
                    <flux:label for="entry-reason">Reason</flux:label>
                    <flux:input id="entry-reason" wire:model="entryForm.reason" maxlength="160" placeholder="Why the balance changes" aria-required="true" />
                </div>
                <div class="space-y-2">
                    <flux:label for="entry-reference">Reference (optional)</flux:label>
                    <flux:input id="entry-reference" wire:model="entryForm.reference" maxlength="120" placeholder="The receipt or statement" />
                </div>
                @if ($entryError !== null)
                    <p role="alert" class="text-sm text-danger">{{ $entryError }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="outline" wire:click="$set('entryOpen', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
            @endif
        </flux:modal>

        <section id="pay-tax-year" class="hris-card scroll-mt-28 overflow-hidden" aria-labelledby="pay-tax-year-heading" data-tax-year>
            <x-payroll.settings.head
                :title="'Tax year '.$taxYear['year']"
                heading-id="pay-tax-year-heading"
                description="Year-to-date taxable compensation and tax withheld."
            >
                @if ($balancesReady)
                    <flux:button variant="outline" icon="plus" wire:click="openBalance">Record balance</flux:button>
                @endif
            </x-payroll.settings.head>
            <dl class="grid gap-4 px-5 py-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Taxable compensation (YTD)</dt>
                    <dd class="num mt-1 text-lg font-semibold" data-tax-year-taxable>{{ Hris::peso($taxYear['taxable']) }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Tax withheld (YTD)</dt>
                    <dd class="num mt-1 text-lg font-semibold" data-tax-year-withheld>{{ Hris::peso($taxYear['withheld']) }}</dd>
                </div>
            </dl>
            @unless ($balancesReady)
                <p class="px-5 pb-4 text-xs text-muted-foreground">Balances can be recorded once this installation's upgrade finishes.</p>
            @endunless
            @if ($balancesReady && $taxBalances->isNotEmpty())
                <div class="hris-table-wrap" data-tax-balances>
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th>Kind</th>
                                <th class="text-right">Taxable compensation</th>
                                <th class="text-right">Tax withheld</th>
                                <th>Recorded by</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($taxBalances as $balance)
                                <tr wire:key="tax-balance-{{ $balance->id }}" data-tax-balance="{{ $balance->id }}">
                                    <td class="num">{{ $balance->tax_year }}</td>
                                    <td>
                                        <p class="font-medium">{{ \App\Modules\Payroll\Application\Queries\TaxYearRecords::KINDS[$balance->kind] ?? $balance->kind }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ $balance->reason }}
                                            @if ($balance->reference !== null)
                                                <span class="block">Ref. {{ $balance->reference }}</span>
                                            @endif
                                        </p>
                                    </td>
                                    <td class="num text-right">{{ Hris::peso($balance->taxable_income) }}</td>
                                    <td class="num text-right">{{ Hris::peso($balance->tax_withheld) }}</td>
                                    <td>
                                        {{ $balance->prepared_by_name ?? 'System' }}
                                        @if ($balance->status === 'APPROVED' && $balance->approved_by_name !== null)
                                            <span class="block text-xs text-muted-foreground">Approved by {{ $balance->approved_by_name }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($balance->status === 'DRAFT')
                                            <span class="pill pill-caution">Awaiting approval</span>
                                        @else
                                            <span class="pill pill-success">Approved</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if ($balance->status === 'DRAFT')
                                            <x-kit.row-actions :label="'Tax balance for '.$balance->tax_year">
                                                <flux:menu.item icon="check" wire:click="approveBalance('{{ $balance->id }}')">Approve</flux:menu.item>
                                                <flux:menu.item icon="trash" variant="danger" wire:click="discardBalance('{{ $balance->id }}')">Discard draft</flux:menu.item>
                                            </x-kit.row-actions>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <flux:modal wire:model.self="balanceOpen" class="w-full max-w-lg" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'tax-balance-heading')">
            {{-- The form, with both kinds, renders only while the dialog is open. --}}
            @if ($balanceOpen)
            <form wire:submit="saveBalance" class="space-y-4">
                <flux:heading size="lg" level="2" id="tax-balance-heading">Record tax balance</flux:heading>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-2">
                        <flux:label for="balance-year">Tax year</flux:label>
                        <flux:input id="balance-year" type="number" min="2000" max="2100" step="1" class:input="num" wire:model="balanceForm.tax_year" aria-label="Tax year" />
                    </div>
                    <x-kit.select label="Kind" wire:model="balanceForm.kind">
                        @foreach (\App\Modules\Payroll\Application\Queries\TaxYearRecords::KINDS as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-2">
                        <flux:label for="balance-taxable">Taxable compensation</flux:label>
                        <x-kit.money-input id="balance-taxable" wire:model="balanceForm.taxable_income" aria-label="Taxable compensation" />
                    </div>
                    <div class="space-y-2">
                        <flux:label for="balance-withheld">Tax withheld</flux:label>
                        <x-kit.money-input id="balance-withheld" wire:model="balanceForm.tax_withheld" aria-label="Tax withheld" />
                    </div>
                </div>
                <div class="space-y-2">
                    <flux:label for="balance-reason">Reason</flux:label>
                    <flux:input id="balance-reason" wire:model="balanceForm.reason" maxlength="160" placeholder="Where these figures come from" aria-required="true" />
                </div>
                <div class="space-y-2">
                    <flux:label for="balance-reference">Reference (optional)</flux:label>
                    <flux:input id="balance-reference" wire:model="balanceForm.reference" maxlength="120" placeholder="e.g. BIR Form 2316 from the previous employer" />
                </div>
                @if ($balanceError !== null)
                    <p role="alert" class="text-sm text-danger">{{ $balanceError }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="outline" wire:click="$set('balanceOpen', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
            @endif
        </flux:modal>

        @if ($recurringReady)
            <flux:modal
                wire:model.self="lineOpen"
                class="w-full max-w-lg"
                x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'line-heading')"
            >
                <form class="grid gap-4" wire:submit="saveLine">
                    @php
                        $locked = $editingLine?->posted ?? false;
                        $isDeduction = $lineKind === RecurringLine::KIND_DEDUCTION;
                        $dialogTitle = ($lineId !== null ? ($isDeduction ? 'Edit loan or deduction' : 'Edit allowance') : ($isDeduction ? 'Add loan or deduction' : 'Add allowance'))
                            .($settings !== null ? ' · '.EmployeeName::display($settings) : '');
                    @endphp
                    <flux:heading size="lg" id="line-heading">{{ $dialogTitle }}</flux:heading>
                    {{-- The card it opened from says which kind it is; the dialog never switches it. --}}
                    <div class="space-y-2">
                        <x-kit.select :label="$isDeduction ? 'Deduction' : 'Allowance'" wire:model="lineTypeId" :disabled="$locked">
                            <flux:select.option value="">Choose one</flux:select.option>
                            @foreach ($lineOptions as $option)
                                <flux:select.option value="{{ $option['id'] }}">{{ $option['name'] }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                        @unless ($locked)
                            <p class="text-xs text-muted-foreground">
                                @if ($isDeduction)
                                    Not on the list? Add its name in <flux:link :href="route('payroll.settings.deductions')" wire:navigate>Payroll Deductions</flux:link> first.
                                @else
                                    Not on the list? Add it in <flux:link :href="route('records.incentive-types')" wire:navigate>Incentives &amp; Bonuses</flux:link> first, typed per payroll.
                                @endif
                            </p>
                        @endunless
                    </div>
                    @if ($locked)
                        <p class="text-xs text-muted-foreground">Already paid on a payslip, so it keeps what it charges. End it and add a new line to charge something else.</p>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <flux:label for="line-amount">Amount per payroll</flux:label>
                            <x-kit.money-input id="line-amount" wire:model.live="lineAmount" aria-label="Amount per payroll" />
                        </div>
                        <x-kit.select label="On" wire:model.live="lineFrequency">
                            @foreach (RecurringLineText::FREQUENCIES as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                    </div>
                    @if ($lineKind === RecurringLine::KIND_DEDUCTION)
                        <div class="space-y-2">
                            <flux:label for="line-total">Total loan payable (optional)</flux:label>
                            <x-kit.money-input id="line-total" wire:model.live="lineTotal" aria-label="Total loan payable" />
                            <p class="text-xs text-muted-foreground">Deductions stop once this is paid. Leave blank if there's no balance.</p>
                        </div>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <flux:label for="line-from">Starts on</flux:label>
                            <x-kit.date-field id="line-from" wire:model.live="lineStartsOn" aria-label="Starts on" />
                            <p class="text-xs text-muted-foreground">The 1st or the 16th: the first payroll it is on.</p>
                        </div>
                        <div class="space-y-2">
                            <flux:label for="line-until">Last period starting on (optional)</flux:label>
                            <x-kit.date-field id="line-until" wire:model="lineEndsOn" aria-label="Last period starting on" />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="line-note">Note (optional)</flux:label>
                        <flux:input id="line-note" wire:model="lineNote" maxlength="160" placeholder="A loan number or the memo it rests on" />
                    </div>
                    @if ($payoff !== null)
                        <div class="rounded-md border border-border bg-muted px-4 py-3 text-sm" data-payoff>
                            <p class="font-medium">{{ $payoff['payrolls'] === 1 ? '1 payroll' : $payoff['payrolls'].' payrolls' }}, up to {{ Hris::peso($payoff['amount']) }} each</p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Paid off on the payroll from <span class="num">{{ PayrollPeriodText::day($payoff['last']) }}</span>, if none is skipped. A skipped payroll moves the last one later.
                            </p>
                        </div>
                    @endif
                    @if ($lineError !== null)
                        <p role="alert" class="text-sm text-danger">{{ $lineError }}</p>
                    @endif
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">Cancel</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary">{{ $lineId === null ? 'Add' : 'Save changes' }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal
                wire:model.self="endOpen"
                class="w-full max-w-md"
                x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'line-end-heading')"
            >
                <form class="grid gap-4" wire:submit="endLine">
                    <flux:heading size="lg" id="line-end-heading">End this loan or allowance</flux:heading>
                    <flux:text class="text-sm text-muted-foreground">No payroll period starting after this date proposes it again. What it paid stays on record.</flux:text>
                    <div class="space-y-2">
                        <flux:label for="line-end-day">Last period starting on</flux:label>
                        <x-kit.date-field id="line-end-day" wire:model="endLastDay" aria-label="Last period starting on" />
                    </div>
                    <div class="space-y-2">
                        <flux:label for="line-end-reason">Reason</flux:label>
                        <flux:input id="line-end-reason" wire:model="endReason" maxlength="160" placeholder="Settled outside payroll, allowance withdrawn…" />
                    </div>
                    @if ($endError !== null)
                        <p role="alert" class="text-sm text-danger">{{ $endError }}</p>
                    @endif
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">Cancel</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary">End line</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal
                wire:model.self="deleteOpen"
                class="w-full max-w-md"
                x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'line-delete-heading')"
            >
                <div class="space-y-4">
                    <flux:heading size="lg" id="line-delete-heading">Delete this loan or allowance?</flux:heading>
                    <flux:text>It was never paid on a payslip, so it is removed for good. Draft payrolls stop proposing it.</flux:text>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="outline">Cancel</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="deleteLine">Delete</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    @endunless
</div>
