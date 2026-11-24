{{--
    One employee's pay, on one page: Pay at a glance (their salary and the
    rules that price their days, each figure marked Company, their status
    or Own, how often they are paid and their province), Tax and Statutory
    Deductions (what payroll needs to deduct CPP, EI and income tax, edited
    in a dialog by an all-data PAYROLL holder), This Year So Far (the
    year's posted payslips against each yearly limit), Percentage
    Deductions while the company lists any (edited in place), Allowances &
    incentives (ONE table: the company benefits they qualify for, the
    incentives assigned to them and their own recurring allowances) and
    Loans & other deductions (their own recurring deductions). A row of
    links to the cards, drawn as the system's view switch, sits under the employee's name
    and holds under the app's top bar while the page scrolls. "Set by" is
    the column
    that says whose a line is; "From" is only ever a date. The cards'
    buttons are all outline: no card's action outranks another's.
--}}
@php
    use App\Livewire\Records\Support\EmployeeRates;
    use App\Livewire\Records\Support\Hris;
    use App\Livewire\Records\Support\RecurringLineText;
    use App\Modules\Payroll\Application\PayrollPeriodText;
    use App\Modules\Payroll\Application\Queries\RecurringLineRecords;
    use App\Modules\Payroll\Domain\Recurring\RecurringLine;
    use App\Support\EmployeeName;

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
                @foreach (array_filter([
                    'pay-glance' => 'Pay at a Glance',
                    'pay-tax' => 'Tax and Deductions',
                    'pay-year' => 'This Year So Far',
                    'pay-contributions' => $percentDeductions !== [] || $percentRows !== [] ? 'Percentage Deductions' : null,
                    'pay-allowances' => 'Allowances & Incentives',
                    'pay-recurring' => 'Loans & Other Deductions',
                ]) as $anchor => $anchorLabel)
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
            <x-payroll.settings.head title="Pay at a Glance" heading-id="pay-glance-heading">
                <x-slot:note>
                    <span class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                        Each figure says where it comes from:
                        @include('livewire.records.partials.pay-source', ['from' => 'company', 'status' => $status]) the company settings,
                        @include('livewire.records.partials.pay-source', ['from' => 'status', 'status' => $status]) their employment status,
                        @include('livewire.records.partials.pay-source', ['from' => 'own', 'status' => $status]) set for them alone.
                    </span>
                </x-slot:note>
                @unless ($hourlyEditing)
                    <flux:button variant="outline" icon="pencil" wire:click="startEditHourly">Edit Hourly Rate</flux:button>
                @endunless
                @if ($salaryReady)
                    <flux:button variant="outline" icon="pencil" wire:click="openSalaryChange">Change Salary</flux:button>
                @endif
            </x-payroll.settings.head>
            @unless ($salaryReady)
                <p class="border-b border-border px-5 py-3 text-xs text-muted-foreground">The salary can be changed here once this installation's upgrade finishes. Until then it is changed on Employee Details.</p>
            @endunless
            <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2 xl:grid-cols-4" data-salary-rates>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Monthly Rate</dt>
                    <dd class="mt-1">
                        <span class="num text-lg font-semibold">{{ $glance['monthly'] === null ? 'Not set' : Hris::money($glance['monthly']) }}</span>
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
                    <dt class="{{ $dt }}">Daily Rate</dt>
                    <dd class="mt-1">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span @class(['text-lg font-semibold', 'num' => str_starts_with($glance['daily']['text'], '$')])>{{ $glance['daily']['text'] }}</span>
                            @include('livewire.records.partials.pay-source', ['from' => $glance['daily']['from'], 'status' => $status])
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ $glance['daily']['note'] }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Hourly Rate</dt>
                    <dd class="mt-1">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span @class(['text-lg font-semibold', 'num' => str_starts_with($glance['hourly']['text'], '$')])>{{ $glance['hourly']['text'] }}</span>
                            @include('livewire.records.partials.pay-source', ['from' => $glance['hourly']['from'], 'status' => $status])
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ $glance['hourly']['note'] }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Days, Overtime and Night Hours</dt>
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
                                {{ $ownRates ? 'Open Own Rates' : 'Set Own Rates' }}
                            </flux:link>
                        @endif
                    </dd>
                </div>
                <div class="min-w-0" data-glance-paid>
                    <dt class="{{ $dt }}">Paid</dt>
                    <dd class="mt-1">
                        <span class="text-lg font-semibold">{{ \App\Livewire\Records\Support\DeductionTablesText::payFrequency($tax['pays']) }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ $tax['pays'] }} pays this year, on the company's pay schedule.</span>
                    </dd>
                </div>
                <div class="min-w-0" data-glance-province>
                    <dt class="{{ $dt }}">Province of Employment</dt>
                    <dd class="mt-1">
                        <span class="text-lg font-semibold">{{ $tax['provinceName'] }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground">Decides which provincial tax applies.</span>
                    </dd>
                </div>
            </dl>

            @if ($hourlyEditing)
                <form class="grid gap-3 border-t border-border p-5 sm:grid-cols-2" wire:submit="saveHourly">
                    <div class="space-y-2">
                        <flux:label for="pay-hourly">Hourly Rate Override</flux:label>
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
                <flux:heading size="lg" level="2" id="salary-change-heading">Change Salary</flux:heading>
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

        {{-- What payroll needs to know about the employee to deduct the pension plan, the insurance and income tax.
             Read here; changed in the dialog, which shows a sample pay before anything is saved. --}}
        @php
            $taxDetails = $tax['details'];
            $claim = static fn (?float $amount, ?float $basic): array => $amount === null
                ? ['text' => $basic === null ? 'Not set up' : Hris::money($basic), 'basic' => true]
                : ['text' => Hris::money($amount), 'basic' => false];
            $federalClaim = $claim($taxDetails->federalClaim, $tax['basic']['federal']);
            $provincialClaim = $claim($taxDetails->provincialClaim, $tax['basic']['provincial']);
        @endphp
        <section id="pay-tax" class="hris-card scroll-mt-28 overflow-hidden" aria-labelledby="pay-tax-heading" data-tax-details-card>
            <x-payroll.settings.head
                title="Tax and Statutory Deductions"
                heading-id="pay-tax-heading"
                description="What the system needs to know about this employee to deduct CPP, EI and income tax."
            >
                @if ($tax['ready'])
                    <flux:button variant="outline" icon="pencil" wire:click="openTaxDetails">Edit</flux:button>
                @endif
            </x-payroll.settings.head>
            @unless ($tax['ready'])
                <p class="border-b border-border px-5 py-3 text-xs text-muted-foreground">These details can be edited once this installation's upgrade finishes. Until then every employee is on the basic amounts.</p>
            @endunless
            <dl class="grid gap-x-6 gap-y-5 p-5 sm:grid-cols-2 xl:grid-cols-3">
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Federal Claim Amount</dt>
                    <dd class="mt-1">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="num text-lg font-semibold" data-federal-claim>{{ $federalClaim['text'] }}</span>
                            @if ($federalClaim['basic'])
                                <span class="pill pill-muted">Basic Amount</span>
                            @endif
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">From the employee's federal TD1 form. Yearly income up to this amount is not taxed.</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">{{ $tax['provinceName'] }} Claim Amount</dt>
                    <dd class="mt-1">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="num text-lg font-semibold" data-provincial-claim>{{ $provincialClaim['text'] }}</span>
                            @if ($provincialClaim['basic'])
                                <span class="pill pill-muted">Basic Amount</span>
                            @endif
                        </span>
                        <span class="mt-1 block text-xs text-muted-foreground">From the employee's {{ $tax['provinceName'] }} TD1 form.</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Extra Tax Each Pay</dt>
                    <dd class="mt-1">
                        <span @class(['text-lg font-semibold', 'num' => $taxDetails->additionalTax > 0.0]) data-extra-tax>{{ $taxDetails->additionalTax > 0.0 ? Hris::money($taxDetails->additionalTax) : 'None' }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground">An employee can ask for more tax to be deducted each pay.</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Canada Pension Plan</dt>
                    <dd class="mt-1">
                        <span class="text-lg font-semibold" data-deduct-pension>{{ $taxDetails->deductPension ? 'Deducted' : 'Not Deducted' }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground">Turn off only for an employee who is exempt, such as under 18 or over 70.</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Employment Insurance</dt>
                    <dd class="mt-1">
                        <span class="text-lg font-semibold" data-deduct-insurance>{{ $taxDetails->deductInsurance ? 'Deducted' : 'Not Deducted' }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground">Turn off only for an employee whose work is not insurable.</span>
                    </dd>
                </div>
            </dl>
            <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">A new employee starts with the basic amounts, with CPP and EI deducted. Change them only when the employee gives a TD1 form that says otherwise.</p>
        </section>

        <flux:modal wire:model.self="taxOpen" class="w-full max-w-xl" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'tax-details-heading')">
            {{-- The form and its sample render only while the dialog is open. --}}
            @if ($taxOpen)
            <form wire:submit="saveTaxDetails" class="space-y-5" data-tax-details-form>
                <div class="space-y-1">
                    <flux:heading size="lg" level="2" id="tax-details-heading">Edit Tax Details</flux:heading>
                    <p class="text-sm text-muted-foreground">{{ EmployeeName::display($settings) }} · Copy these from the employee's TD1 forms. Leave the basic amounts if no form was given.</p>
                </div>

                <div class="space-y-2">
                    <x-kit.select label="Province of Employment" wire:model.live="taxForm.province">
                        @foreach ($tax['provinces'] as $code => $name)
                            <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                    <p class="text-xs text-muted-foreground">Where the employee reports for work. Only Alberta is set up for now.</p>
                </div>

                @foreach ([
                    'federal' => ['legend' => 'Federal Claim Amount', 'form' => 'TD1', 'basic' => $tax['formBasic']['federal'] ?? null, 'hint' => 'The Total Claim Amount on the federal TD1 form. A higher amount means less tax each pay.'],
                    'provincial' => ['legend' => $tax['formProvinceName'].' Claim Amount', 'form' => $tax['formProvinceName'].' TD1', 'basic' => $tax['formBasic']['provincial'] ?? null, 'hint' => 'The Total Claim Amount on the employee\'s '.$tax['formProvinceName'].' TD1 form.'],
                ] as $side => $field)
                    <fieldset class="min-w-0 space-y-2" wire:key="tax-claim-{{ $side }}">
                        <legend class="text-sm font-medium">{{ $field['legend'] }}</legend>
                        <div class="grid gap-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" wire:model.live="taxForm.{{ $side }}_mode" value="basic" class="accent-primary" />
                                <span>Basic amount @if ($field['basic'] !== null)<span class="num text-muted-foreground">{{ Hris::money($field['basic']) }}</span>@endif</span>
                            </label>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="radio" wire:model.live="taxForm.{{ $side }}_mode" value="form" class="accent-primary" />
                                    <span>Amount from the {{ $field['form'] }} form</span>
                                </label>
                                <div class="w-40">
                                    <x-kit.money-input id="tax-{{ $side }}-claim" wire:model.live="taxForm.{{ $side }}_claim" aria-label="{{ $field['legend'] }} from the {{ $field['form'] }} form" :disabled="($taxForm[$side.'_mode'] ?? 'basic') !== 'form'" />
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ $field['hint'] }}</p>
                    </fieldset>
                @endforeach

                <div class="space-y-2">
                    <flux:label for="tax-extra">Extra Tax Each Pay<span class="ms-1 font-normal text-muted-foreground">(optional)</span></flux:label>
                    <div class="w-40">
                        <x-kit.money-input id="tax-extra" wire:model.live="taxForm.additional_tax" aria-label="Extra tax each pay" />
                    </div>
                    <p class="text-xs text-muted-foreground">Fill this only if the employee asked for more tax to be taken off each pay.</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:checkbox wire:model.live="taxForm.deduct_pension" label="Deduct CPP" description="Turn off only if the employee is exempt." />
                    <flux:checkbox wire:model.live="taxForm.deduct_insurance" label="Deduct EI" description="Turn off only if the work is not insurable." />
                </div>

                <section class="rounded-md border border-border" aria-labelledby="tax-sample-heading" data-tax-sample>
                    <div class="border-b border-border px-4 py-3">
                        <h3 id="tax-sample-heading" class="text-sm font-bold">Sample: A Pay of <span class="num">{{ Hris::money($tax['samplePay']) }}</span></h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">What these details deduct, so you can check before saving.</p>
                    </div>
                    <div class="hris-table-wrap">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th scope="col">Deduction</th>
                                    <th scope="col" class="text-right">Now</th>
                                    <th scope="col" class="text-right">After Saving</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (['federal' => 'Federal Tax', 'provincial' => $tax['formProvinceName'].' Tax', 'pension' => 'CPP', 'insurance' => 'EI'] as $sampleKey => $sampleLabel)
                                    @php
                                        $now = $tax['sampleNow'][$sampleKey] ?? null;
                                        $after = $tax['sampleAfter'][$sampleKey] ?? null;
                                    @endphp
                                    <tr wire:key="tax-sample-{{ $sampleKey }}">
                                        <td>{{ $sampleLabel }}</td>
                                        <td class="num text-right" data-sample-now="{{ $sampleKey }}">{{ $now === null ? '—' : Hris::money($now) }}</td>
                                        <td @class(['num text-right', 'font-semibold' => $after !== null && $now !== null && abs($after - $now) >= 0.005]) data-sample-after="{{ $sampleKey }}">{{ $after === null ? '—' : Hris::money($after) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                @if ($taxError !== null)
                    <p role="alert" class="text-sm text-danger">{{ $taxError }}</p>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                    <p class="text-xs text-muted-foreground">The change is used from the next payroll. Finalized payrolls stay as they are.</p>
                    <div class="flex gap-2">
                        <flux:button type="button" variant="outline" wire:click="$set('taxOpen', false)">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">Save</flux:button>
                    </div>
                </div>
            </form>
            @endif
        </flux:modal>

        {{-- The year's posted payslips, added up: where each contribution stands against the most an employee pays in a year. --}}
        @php
            $year = $tax['year'];
            $meters = [
                'pension' => ['label' => 'CPP Paid', 'name' => 'CPP', 'paid' => $year['pension'], 'max' => $year['pension_max']],
                'insurance' => ['label' => 'EI Paid', 'name' => 'EI', 'paid' => $year['insurance'], 'max' => $year['insurance_max']],
            ];
        @endphp
        <section id="pay-year" class="hris-card scroll-mt-28 overflow-hidden" aria-labelledby="pay-year-heading" data-year-so-far>
            <x-payroll.settings.head
                :title="'This Year So Far ('.$year['year'].')'"
                heading-id="pay-year-heading"
                description="Totals from the payrolls paid this year. CPP and EI stop when they reach the most an employee pays in a year."
            />
            <div class="grid gap-x-6 gap-y-5 p-5 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($meters as $meterKey => $meter)
                    @php
                        $share = $meter['max'] > 0.0 ? (int) min(100, floor($meter['paid'] / $meter['max'] * 100)) : 0;
                        $left = max(0.0, $meter['max'] - $meter['paid']);
                    @endphp
                    <div class="min-w-0" wire:key="year-{{ $meterKey }}" data-year-meter="{{ $meterKey }}">
                        <p class="{{ $dt }}">{{ $meter['label'] }}</p>
                        <p class="mt-1"><span class="num text-lg font-semibold">{{ Hris::money($meter['paid']) }}</span> <span class="num text-sm text-muted-foreground">of {{ Hris::money($meter['max']) }}</span></p>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-muted" role="img" aria-label="{{ $share }} percent of the yearly limit">
                            <div class="h-full rounded-full bg-primary" style="width: {{ $share }}%"></div>
                        </div>
                        <p class="mt-2 text-xs text-muted-foreground">
                            @if ($left <= 0.0)
                                {{ $meter['name'] }} has reached the yearly limit. No more is deducted this year.
                            @else
                                <span class="num">{{ Hris::money($left) }}</span> left before {{ $meter['name'] }} stops for the year.
                            @endif
                        </p>
                        @if ($meterKey === 'pension' && $year['second_pension'] > 0.0)
                            <p class="mt-1 text-xs text-muted-foreground">Second CPP contribution: <span class="num">{{ Hris::money($year['second_pension']) }}</span> of <span class="num">{{ Hris::money($year['second_pension_max']) }}</span>.</p>
                        @endif
                    </div>
                @endforeach
                <div class="min-w-0" data-year-tax>
                    <p class="{{ $dt }}">Income Tax Deducted</p>
                    <p class="num mt-1 text-lg font-semibold">{{ Hris::money($year['federal'] + $year['provincial']) }}</p>
                    <p class="mt-2 text-xs text-muted-foreground">Federal <span class="num">{{ Hris::money($year['federal']) }}</span> · {{ $tax['provinceName'] }} <span class="num">{{ Hris::money($year['provincial']) }}</span></p>
                </div>
                <div class="min-w-0" data-year-gross>
                    <p class="{{ $dt }}">Gross Pay</p>
                    <p class="num mt-1 text-lg font-semibold">{{ Hris::money($year['gross']) }}</p>
                    <p class="mt-2 text-xs text-muted-foreground">{{ $year['pays'] === 0 ? 'No pays yet this year.' : ($year['pays'] === 1 ? '1 pay so far.' : $year['pays'].' pays so far.') }}</p>
                </div>
            </div>
            <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">These totals are what the year-end T4 slip is made from.</p>
        </section>

        {{-- The company's percentage deductions, at the listed rate or a rate of the employee's own. --}}
        @if ($percentDeductions !== [] || $percentRows !== [])
        <section id="pay-contributions" class="hris-card scroll-mt-28 overflow-hidden" aria-labelledby="pay-contributions-heading" data-contributions-card>
            <x-payroll.settings.head
                title="Percentage Deductions"
                heading-id="pay-contributions-heading"
                description="Company deductions taken as a percentage of pay, at the listed rate or a rate of this employee's own."
            >
                @if (! $editing && $govReady)
                    <flux:button variant="outline" icon="pencil" wire:click="startEdit">Edit</flux:button>
                @endif
            </x-payroll.settings.head>

            @unless ($govReady)
                <p class="border-b border-border px-5 py-3 text-xs text-muted-foreground">These rates can be edited once this installation's upgrade finishes. Until then the listed rates apply.</p>
            @endunless

            @unless ($editing)
                <div class="hris-table-wrap">
                    <table class="hris-table">
                        <thead><tr><th>Deduction</th><th>How It Is Deducted</th><th>Set By</th></tr></thead>
                        <tbody>
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
                                            <span class="block text-xs text-muted-foreground"><span class="num">{{ Hris::money($deduction['amount']) }}</span> this pay</span>
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
                    <fieldset class="min-w-0 rounded-md border border-border p-4">
                        <legend class="px-1 text-sm font-semibold">Percentage Deductions</legend>
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

                    @include('livewire.payroll.partials.settings-applies-note')

                    @if ($formError !== null)
                        <p role="alert" class="text-sm text-danger">{{ $formError }}</p>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
                        <flux:button variant="outline" icon="arrow-uturn-left" wire:click="useStandard">Reset to Listed Rates</flux:button>
                        <div class="flex gap-2">
                            <flux:button variant="ghost" icon="x-mark" wire:click="cancelEdit">Cancel</flux:button>
                            <flux:button type="submit" variant="primary">Save</flux:button>
                        </div>
                    </div>
                </form>
            @endunless
        </section>
        @endif

        @php
            $allowances = $lines->filter(static fn ($line): bool => $line->kind === RecurringLine::KIND_INCENTIVE);
            $loans = $lines->filter(static fn ($line): bool => $line->kind === RecurringLine::KIND_DEDUCTION);
        @endphp
        {{-- One table for everything payroll adds on its own: the company's scheduled incentives they qualify for,
             the incentives assigned to them for a span of dates, and their own repeating allowances. --}}
        <section id="pay-allowances" class="hris-card scroll-mt-28 overflow-hidden" aria-labelledby="pay-allowances-heading" data-assigned-benefits>
            <x-payroll.settings.head
                title="Allowances & Incentives"
                heading-id="pay-allowances-heading"
                description="What payroll adds to their pay on its own."
            >
                @if ($assignReady && $assignableTypes !== [])
                    <flux:button variant="outline" icon="plus" wire:click="openAssign">Assign Incentive</flux:button>
                @endif
                @if ($recurringReady)
                    <flux:button variant="outline" icon="plus" wire:click="addLine('INCENTIVE')">Add Allowance</flux:button>
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
                            <th>Set By</th>
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
                                    <flux:link :href="route('records.incentive-types')" wire:navigate class="text-sm font-medium">Open Catalog</flux:link>
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
                                            <span class="num">{{ Hris::money($row->amount) }}</span>
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
                                                <flux:menu.item icon="trash" variant="danger" wire:click="discardAssignment('{{ $row->id }}')">Discard Draft</flux:menu.item>
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
                <flux:heading size="lg" level="2" id="assign-benefit-heading">Assign Incentive</flux:heading>
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
                title="Loans & Other Deductions"
                heading-id="pay-recurring-heading"
                description="Loans and other amounts taken from their pay. A loan stops once it is paid off."
            >
                @if ($recurringReady)
                    <flux:button variant="outline" icon="plus" wire:click="addLine('DEDUCTION')">Add Loan or Deduction</flux:button>
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
                                <th class="text-right">Per Payroll</th>
                                <th class="text-right">Balance</th>
                                <th>Runs</th>
                                <th>Set By</th>
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
                    <h3 class="text-sm font-semibold">Payments &amp; Adjustments Outside Payroll</h3>
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
                                <th>Recorded By</th>
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
                                    <td class="num text-right">{{ Hris::money($entry->amount) }}</td>
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
                                                <flux:menu.item icon="trash" variant="danger" wire:click="discardEntry('{{ $entry->id }}')">Discard Draft</flux:menu.item>
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
                <flux:heading size="lg" level="2" id="loan-entry-heading">Record Payment or Adjustment</flux:heading>
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
                            <flux:label for="line-amount">Amount per Payroll</flux:label>
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
                            <flux:label for="line-total">Total Loan Payable (optional)</flux:label>
                            <x-kit.money-input id="line-total" wire:model.live="lineTotal" aria-label="Total loan payable" />
                            <p class="text-xs text-muted-foreground">Deductions stop once this is paid. Leave blank if there's no balance.</p>
                        </div>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <flux:label for="line-from">Starts On</flux:label>
                            <x-kit.date-field id="line-from" wire:model.live="lineStartsOn" aria-label="Starts on" />
                            <p class="text-xs text-muted-foreground">The 1st or the 16th: the first payroll it is on.</p>
                        </div>
                        <div class="space-y-2">
                            <flux:label for="line-until">Last Period Starting on (optional)</flux:label>
                            <x-kit.date-field id="line-until" wire:model="lineEndsOn" aria-label="Last period starting on" />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="line-note">Note (optional)</flux:label>
                        <flux:input id="line-note" wire:model="lineNote" maxlength="160" placeholder="A loan number or the memo it rests on" />
                    </div>
                    @if ($payoff !== null)
                        <div class="rounded-md border border-border bg-muted px-4 py-3 text-sm" data-payoff>
                            <p class="font-medium">{{ $payoff['payrolls'] === 1 ? '1 payroll' : $payoff['payrolls'].' payrolls' }}, up to {{ Hris::money($payoff['amount']) }} each</p>
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
                        <flux:button type="submit" variant="primary">{{ $lineId === null ? 'Add' : 'Save Changes' }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            <flux:modal
                wire:model.self="endOpen"
                class="w-full max-w-md"
                x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'line-end-heading')"
            >
                <form class="grid gap-4" wire:submit="endLine">
                    <flux:heading size="lg" id="line-end-heading">End This Loan or Allowance</flux:heading>
                    <flux:text class="text-sm text-muted-foreground">No payroll period starting after this date proposes it again. What it paid stays on record.</flux:text>
                    <div class="space-y-2">
                        <flux:label for="line-end-day">Last Period Starting On</flux:label>
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
                        <flux:button type="submit" variant="primary">End Line</flux:button>
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
