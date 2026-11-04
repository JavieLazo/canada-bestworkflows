{{--
    One employee's pay, on one page: Pay at a glance (their rates and the
    rules that price their days, each figure marked Company, their status
    or Own), Contributions & tax (read as payroll applies them and, for an
    all-data PAYROLL holder, edited in place with a reason), Allowances &
    benefits (the company benefits they qualify for beside their own
    recurring allowances) and Loans & other deductions (their recurring
    deductions beside the percentage deductions that reach everyone).
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
    <div class="space-y-2">
        <flux:link :href="route('records.employee-pay-settings')" wire:navigate class="text-sm">
            ← Employee Pay Settings
        </flux:link>
        @if ($settings !== null)
            <h1 class="text-2xl font-bold tracking-tight">{{ EmployeeName::display($settings) }}</h1>
            <p class="text-sm text-muted-foreground">
                <span class="num">{{ $settings->employee_code }}</span>@if ($settings->employee_id_no) · Employee ID <span class="num">{{ $settings->employee_id_no }}</span>@endif
                · {{ $settings->employment_status ?: 'No employment status' }}
                @unless ($settings->is_active) · Inactive @endunless
            </p>
        @else
            <h1 class="text-2xl font-bold tracking-tight">Employee Pay Settings</h1>
        @endif
    </div>

    @unless ($canRead)
        <section class="hris-card px-5 py-6">
            <p class="text-sm text-muted-foreground">
                Employee pay settings are pay data. They are shown to payroll officers with all-data access to Payroll.
            </p>
        </section>
    @else
        @php
            $source = $rates['source'];
            $next = $rates['next'];
            $ownRates = $source === EmployeeRates::OWN || $next !== null;
        @endphp
        <section class="hris-card p-5" aria-labelledby="pay-glance-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="pay-glance-heading" class="text-base font-bold">Pay at a glance</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Each figure says where it comes from:
                        @include('livewire.records.partials.pay-source', ['from' => 'company', 'status' => $status]) the company settings,
                        @include('livewire.records.partials.pay-source', ['from' => 'status', 'status' => $status]) their employment status,
                        @include('livewire.records.partials.pay-source', ['from' => 'own', 'status' => $status]) set for them alone.
                    </p>
                </div>
                @unless ($hourlyEditing)
                    <button type="button" wire:click="startEditHourly" class="text-sm font-medium text-primary hover:underline">Edit hourly rate</button>
                @endunless
            </div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Monthly rate</dt>
                    <dd class="mt-1 text-sm">
                        <span class="num">{{ $glance['monthly'] === null ? 'Not set' : Hris::peso($glance['monthly']) }}</span>
                        <span class="block text-xs text-muted-foreground">From Employee Details ·
                            <flux:link :href="route('employees.show', ['employee' => $settings->id])" wire:navigate>Change</flux:link>
                        </span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Daily rate</dt>
                    <dd class="mt-1 text-sm">
                        <span @class(['num' => str_starts_with($glance['daily']['text'], '₱')])>{{ $glance['daily']['text'] }}</span>
                        @include('livewire.records.partials.pay-source', ['from' => $glance['daily']['from'], 'status' => $status])
                        <span class="block text-xs text-muted-foreground">{{ $glance['daily']['note'] }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Hourly rate</dt>
                    <dd class="mt-1 text-sm">
                        <span @class(['num' => str_starts_with($glance['hourly']['text'], '₱')])>{{ $glance['hourly']['text'] }}</span>
                        @include('livewire.records.partials.pay-source', ['from' => $glance['hourly']['from'], 'status' => $status])
                        <span class="block text-xs text-muted-foreground">{{ $glance['hourly']['note'] }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Days, overtime and night hours</dt>
                    <dd class="mt-1 text-sm">
                        @if ($source === EmployeeRates::OWN)
                            Their own rates
                        @elseif ($source === EmployeeRates::STATUS)
                            The {{ $status }} override
                        @else
                            The company rule
                        @endif
                        @include('livewire.records.partials.pay-source', ['from' => $glance['rules']['from'], 'status' => $status])
                        <span class="block text-xs text-muted-foreground">{{ $glance['rules']['text'] }} · {{ $glance['rules']['note'] }}</span>
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
                <form class="mt-4 grid gap-3 border-t border-border pt-4 sm:grid-cols-2" wire:submit="saveHourly">
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

        <section class="hris-card overflow-hidden" aria-labelledby="pay-salary-heading" data-salary-card>
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 id="pay-salary-heading" class="text-base font-bold">Salary</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        The Rate Per Month and Rate Per Day in force from each date. A change dated today or later applies on its date;
                        one dated in the past waits for approval. Payroll prices each day at the salary in force on it; finalized payslips keep what they paid.
                    </p>
                </div>
                @if ($salaryReady)
                    <flux:button variant="primary" icon="plus" wire:click="openSalaryChange">Record salary change</flux:button>
                @endif
            </header>
            @unless ($salaryReady)
                <p class="px-5 py-4 text-xs text-muted-foreground">Salary changes can be recorded once this installation's upgrade finishes. Until then the rates on the record apply.</p>
            @endunless
            @if ($salaryReady && $salaryVersions->isNotEmpty())
                <div class="hris-table-wrap" data-salary-versions>
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Effective from</th>
                                <th class="text-right">Rate Per Month</th>
                                <th class="text-right">Rate Per Day</th>
                                <th>Reason</th>
                                <th>Recorded by</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($salaryVersions as $version)
                                <tr wire:key="salary-version-{{ $version->id }}">
                                    <td class="num whitespace-nowrap">{{ $version->effective_from->format('d M Y') }}</td>
                                    <td class="num text-right">{{ $version->monthly_rate === null ? '—' : Hris::peso($version->monthly_rate) }}</td>
                                    <td class="num text-right">{{ $version->daily_rate === null ? '—' : Hris::peso($version->daily_rate) }}</td>
                                    <td class="text-muted-foreground">
                                        {{ $version->reason }}
                                        @if ($version->reference !== null)
                                            <span class="block text-xs">Ref. {{ $version->reference }}</span>
                                        @endif
                                        @if ($version->review_note !== null)
                                            <span class="block text-xs">Note: {{ $version->review_note }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $version->prepared_by_name ?? 'System' }}
                                        <span class="num text-muted-foreground">· {{ $version->created_at->setTimezone(\App\Support\ManilaClock::TZ)->format('d M Y') }}</span>
                                        @if ($version->status !== 'PENDING' && $version->approved_by_name !== null)
                                            <span class="block text-xs text-muted-foreground">{{ $version->status === 'REJECTED' ? 'Rejected' : 'Approved' }} by {{ $version->approved_by_name }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($version->status === 'PENDING')
                                            <span class="pill pill-caution">Awaiting approval</span>
                                        @elseif ($version->status === 'REJECTED')
                                            <span class="pill pill-danger">Rejected</span>
                                        @elseif ($version->effective_from->format('Y-m-d') > $today)
                                            <span class="pill pill-caution">Upcoming</span>
                                        @elseif ($salaryInForceId !== null && $version->id === $salaryInForceId)
                                            <span class="pill pill-success">Current</span>
                                        @else
                                            <span class="pill pill-muted">Superseded</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if ($version->status === 'PENDING')
                                            <x-kit.row-actions :label="'Salary change from '.$version->effective_from->format('d M Y')">
                                                <flux:menu.item icon="check" wire:click="openSalaryReview('{{ $version->id }}')">Review</flux:menu.item>
                                            </x-kit.row-actions>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif ($salaryReady)
                <p class="px-5 py-4 text-sm text-muted-foreground">No salary version yet: the rates on the record apply.</p>
            @endif
        </section>

        <flux:modal wire:model.self="salaryOpen" class="w-full max-w-lg" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'salary-change-heading')">
            <form wire:submit="saveSalaryChange" class="space-y-4">
                <flux:heading size="lg" level="2" id="salary-change-heading">Record salary change</flux:heading>
                <div class="space-y-2">
                    <flux:label for="salary-from">Takes effect on</flux:label>
                    <x-kit.date-field id="salary-from" wire:model="salaryForm.effective_from" aria-label="Takes effect on" />
                    <p class="text-xs text-muted-foreground">Today or later applies on that date. A past date waits for approval; payslips already finalized are not changed.</p>
                </div>
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
                <div class="space-y-2">
                    <flux:label for="salary-reason">Reason</flux:label>
                    <flux:input id="salary-reason" wire:model="salaryForm.reason" maxlength="160" placeholder="Why the salary changes" aria-required="true" />
                </div>
                <div class="space-y-2">
                    <flux:label for="salary-reference">Reference (optional)</flux:label>
                    <flux:input id="salary-reference" wire:model="salaryForm.reference" maxlength="120" placeholder="The memo, appointment or notice" />
                </div>
                @if ($salaryError !== null)
                    <p role="alert" class="text-sm text-danger">{{ $salaryError }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="outline" wire:click="$set('salaryOpen', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Record</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model.self="salaryReviewOpen" class="w-full max-w-md" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'salary-review-heading')">
            <div class="space-y-4">
                <flux:heading size="lg" level="2" id="salary-review-heading">Review salary change</flux:heading>
                <p class="text-sm text-muted-foreground">This change is dated in the past. Approving makes it the record's rates now and the salary payroll prices those days at; payslips already finalized keep what they paid.</p>
                <div class="space-y-2">
                    <flux:label for="salary-review-note">Note (optional)</flux:label>
                    <flux:input id="salary-review-note" wire:model="salaryReviewNote" maxlength="500" />
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button type="button" variant="outline" wire:click="$set('salaryReviewOpen', false)">Cancel</flux:button>
                    <flux:button type="button" variant="outline" wire:click="rejectSalaryChange">Reject</flux:button>
                    <flux:button type="button" variant="primary" wire:click="approveSalaryChange">Approve</flux:button>
                </div>
            </div>
        </flux:modal>

        <section class="hris-card overflow-hidden" aria-labelledby="pay-contributions-heading" data-contributions-card>
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 id="pay-contributions-heading" class="text-base font-bold">Contributions &amp; tax</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        How SSS, PhilHealth, Pag-IBIG and withholding tax are deducted for this employee, and their own rate on each percentage deduction.
                        A change is a dated version: prepared here, approved, then picked up by draft payrolls from its date; finalized payrolls keep what they paid.
                    </p>
                </div>
                @if (! $editing && $govReady)
                    <flux:button variant="primary" icon="plus" wire:click="startEdit">Prepare a version</flux:button>
                @endif
            </header>

            @unless ($govReady)
                <p class="px-5 py-4 text-xs text-muted-foreground">Versions can be prepared once this installation's upgrade finishes. Until then the settings on the record apply.</p>
            @endunless

            @unless ($editing)
                <p class="px-5 py-3 text-xs text-muted-foreground" data-gov-in-force>
                    @if ($govInForce !== null)
                        In force since <span class="num">{{ $govInForce->effective_from->format('d M Y') }}</span>
                        · approved by {{ $govInForce->approved_by_name ?? 'an unnamed account' }}
                        · {{ $govInForce->reason }}
                    @else
                        No approved version yet: the settings on the record apply.
                    @endif
                </p>
                <div class="hris-table-wrap">
                    <table class="hris-table">
                        <thead><tr><th>Deduction</th><th>How it is deducted</th><th>From</th></tr></thead>
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
                                            Not a member (no employee share)
                                        @else
                                            Own rate {{ Hris::moneyText($deduction['own']) }}% · listed: {{ $deduction['detail'] }}
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
                    @foreach (GovSettings::CONTRIBUTIONS as $key => $name)
                        @php $on = (bool) ($form["{$key}_deduct"] ?? false); @endphp
                        <fieldset class="rounded-md border border-border p-4" wire:key="edit-{{ $key }}">
                            <legend class="px-1 text-sm font-semibold">{{ $name }}</legend>
                            <div class="flex items-center gap-3">
                                <flux:switch id="pay-{{ $key }}-deduct" wire:model.live="form.{{ $key }}_deduct" />
                                <flux:label for="pay-{{ $key }}-deduct">Deduct {{ $name }}</flux:label>
                            </div>
                            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                <div class="space-y-2">
                                    <flux:label for="pay-{{ $key }}-ee">Fixed employee share</flux:label>
                                    <x-kit.money-input id="pay-{{ $key }}-ee" wire:model="form.{{ $key }}_fixed_ee" aria-label="Fixed {{ $name }} employee share" :disabled="! $on" />
                                </div>
                                <div class="space-y-2">
                                    <flux:label for="pay-{{ $key }}-er">Fixed employer share</flux:label>
                                    <x-kit.money-input id="pay-{{ $key }}-er" wire:model="form.{{ $key }}_fixed_er" aria-label="Fixed {{ $name }} employer share" :disabled="! $on" />
                                </div>
                                <x-kit.select label="Deducted" wire:model="form.{{ $key }}_mode" :disabled="! $on">
                                    @foreach (GovInput::MODES as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </x-kit.select>
                            </div>
                            <p class="mt-2 text-xs text-muted-foreground">A fixed share is the month's figure in place of the table's; leave it blank to use the table.</p>
                        </fieldset>
                    @endforeach

                    @php
                        $taxOn = (bool) ($form['tax_deduct'] ?? false);
                        $method = (string) ($form['tax_method'] ?? GovInput::METHOD_TABLE);
                    @endphp
                    <fieldset class="rounded-md border border-border p-4">
                        <legend class="px-1 text-sm font-semibold">Withholding tax</legend>
                        <div class="flex items-center gap-3">
                            <flux:switch id="pay-tax-deduct" wire:model.live="form.tax_deduct" />
                            <flux:label for="pay-tax-deduct">Deduct withholding tax</flux:label>
                        </div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <x-kit.select label="Worked out as" wire:model.live="form.tax_method" :disabled="! $taxOn">
                                @foreach (GovInput::METHODS as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                            <div class="space-y-2">
                                @if ($method === GovInput::METHOD_FIXED)
                                    <flux:label for="pay-tax-amount">Tax per payslip</flux:label>
                                    <x-kit.money-input id="pay-tax-amount" wire:model="form.tax_amount" aria-label="Tax per payslip" :disabled="! $taxOn" />
                                @elseif ($method === GovInput::METHOD_PERCENT)
                                    <flux:label for="pay-tax-amount">Percent of taxable pay</flux:label>
                                    <flux:input id="pay-tax-amount" type="number" min="0" max="100" step="0.01" class:input="num" wire:model="form.tax_amount" aria-label="Percent of taxable pay" :disabled="! $taxOn" />
                                @elseif ($method === GovInput::METHOD_WHOLE_YEAR)
                                    <x-kit.select label="Remainder spread" wire:model="form.tax_spread" :disabled="! $taxOn">
                                        @foreach (GovInput::SPREADS as $value => $label)
                                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </x-kit.select>
                                    <p class="text-xs text-muted-foreground">The whole year's tax is estimated from pay posted so far and the payrolls left; the year's last payroll settles the exact remainder and refunds any excess.</p>
                                @endif
                            </div>
                        </div>
                    </fieldset>

                    @if ($percentRows !== [])
                        <fieldset class="rounded-md border border-border p-4">
                            <legend class="px-1 text-sm font-semibold">Percentage deductions</legend>
                            <p class="text-xs text-muted-foreground">The employee share this employee pays on each percentage deduction. Blank uses the listed rate; 0 means they are not a member. The employer share stays as listed.</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                @foreach ($percentRows as $rowId => $row)
                                    <div class="space-y-2" wire:key="percent-{{ $rowId }}">
                                        <flux:label for="pay-percent-{{ $rowId }}">{{ $row['name'] }} <span class="font-normal text-muted-foreground">· listed: {{ $row['detail'] }}</span></flux:label>
                                        <flux:input id="pay-percent-{{ $rowId }}" type="number" min="0" max="100" step="0.01" class:input="num" wire:model="form.percent.{{ $rowId }}" aria-label="{{ $row['name'] }} employee share percent" placeholder="Listed rate" />
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-2">
                            <flux:label for="pay-from">Takes effect on</flux:label>
                            <x-kit.date-field id="pay-from" wire:model.live="effectiveFrom" aria-label="Takes effect on" />
                            <p class="text-xs text-muted-foreground">The 1st or the 16th: the first payroll it applies to, after every approved version.</p>
                        </div>
                        <div class="space-y-2">
                            <flux:label for="pay-reason">Reason</flux:label>
                            <flux:input id="pay-reason" wire:model="reason" maxlength="160" placeholder="Why this employee is paid differently" />
                        </div>
                    </div>

                    @if ($formError !== null)
                        <p role="alert" class="text-sm text-danger">{{ $formError }}</p>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
                        <flux:button variant="ghost" wire:click="useStandard">Use standard settings</flux:button>
                        <div class="flex gap-2">
                            <flux:button variant="ghost" icon="x-mark" wire:click="cancelEdit">Cancel</flux:button>
                            <flux:button type="submit" variant="primary">Save draft</flux:button>
                        </div>
                    </div>
                </form>
            @endunless

            @if ($govReady && $govVersions->isNotEmpty())
                <div class="hris-table-wrap border-t border-border" data-gov-versions>
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Effective from</th>
                                <th>Reason</th>
                                <th>Prepared by</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($govVersions as $version)
                                @php
                                    $versionFrom = $version->effective_from->format('Y-m-d');
                                    $versionDraft = $version->status === 'DRAFT';
                                @endphp
                                <tr wire:key="gov-version-{{ $version->id }}">
                                    <td class="num whitespace-nowrap">{{ $version->effective_from->format('d M Y') }}</td>
                                    <td class="text-muted-foreground">{{ $version->reason }}</td>
                                    <td>
                                        {{ $version->prepared_by_name ?? 'System' }}
                                        <span class="num text-muted-foreground">· {{ $version->created_at->setTimezone(\App\Support\ManilaClock::TZ)->format('d M Y') }}</span>
                                        @if (! $versionDraft)
                                            <span class="block text-xs text-muted-foreground">Approved by {{ $version->approved_by_name ?? 'an unnamed account' }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($versionDraft)
                                            <span class="pill pill-caution">Awaiting approval</span>
                                        @elseif ($versionFrom > $today)
                                            <span class="pill pill-caution">Upcoming</span>
                                        @elseif ($govInForce !== null && $version->id === $govInForce->id)
                                            <span class="pill pill-success">Current</span>
                                        @else
                                            <span class="pill pill-muted">Superseded</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        @if ($versionDraft)
                                            <x-kit.row-actions :label="'Draft from '.$version->effective_from->format('d M Y')">
                                                <flux:menu.item icon="check" wire:click="approveVersion('{{ $version->id }}')">Approve</flux:menu.item>
                                                <flux:menu.item icon="trash" variant="danger" wire:click="discardVersion('{{ $version->id }}')">Discard draft</flux:menu.item>
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

        @php
            $allowances = $lines->filter(static fn ($line): bool => $line->kind === RecurringLine::KIND_INCENTIVE);
            $loans = $lines->filter(static fn ($line): bool => $line->kind === RecurringLine::KIND_DEDUCTION);
        @endphp
        <section class="hris-card overflow-hidden" aria-labelledby="pay-allowances-heading">
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 id="pay-allowances-heading" class="text-base font-bold">Allowances &amp; benefits</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        What payroll adds to their pay on its own. Company benefits come from Incentives &amp; Bonuses; their own lines repeat until they end.
                    </p>
                </div>
                @if ($recurringReady)
                    <flux:button variant="primary" icon="plus" wire:click="addLine('INCENTIVE')">Add allowance</flux:button>
                @endif
            </header>
            <div class="hris-table-wrap">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th>Line</th>
                            <th class="text-right">Per payroll</th>
                            <th>When</th>
                            <th>Tax</th>
                            <th>From</th>
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
                                <td class="text-right">
                                    <flux:link :href="route('records.incentive-types')" wire:navigate class="text-sm font-medium">Open catalog</flux:link>
                                </td>
                            </tr>
                        @endforeach
                        @foreach ($allowances as $line)
                            @include('livewire.records.partials.pay-line-row', ['line' => $line, 'status' => $status, 'withTax' => true])
                        @endforeach
                        @if ($benefits === [] && $allowances->isEmpty())
                            <tr wire:key="allowances-empty">
                                <x-kit.empty-state :colspan="6" title="No allowance or benefit reaches them yet." hint="Add an allowance that repeats on every payroll, or set up a benefit in Incentives & Bonuses." />
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </section>

        <section class="hris-card overflow-hidden" aria-labelledby="pay-assigned-heading" data-assigned-benefits>
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 id="pay-assigned-heading" class="text-base font-bold">Benefits assigned</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        A scheduled benefit given to this employee for a span of dates, at the type's amount or one of their own.
                        A draft waits for approval before a payroll proposes it.
                    </p>
                </div>
                @if ($assignReady && $assignableTypes !== [])
                    <flux:button variant="primary" icon="plus" wire:click="openAssign">Assign benefit</flux:button>
                @endif
            </header>
            @unless ($assignReady)
                <p class="px-5 py-4 text-xs text-muted-foreground">Benefits can be assigned once this installation's upgrade finishes.</p>
            @endunless
            @if ($assignReady && $assignments->isNotEmpty())
                <div class="hris-table-wrap" data-assignment-rows>
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Benefit</th>
                                <th>From</th>
                                <th>To</th>
                                <th class="text-right">Amount</th>
                                <th>Recorded by</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($assignments as $row)
                                <tr wire:key="assignment-{{ $row->id }}" data-assignment="{{ $row->id }}">
                                    <td>
                                        <p class="font-medium">{{ $row->type_name }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ $row->reason }}
                                            @if ($row->reference !== null)
                                                <span class="block">Ref. {{ $row->reference }}</span>
                                            @endif
                                        </p>
                                    </td>
                                    <td class="num whitespace-nowrap">{{ $row->starts_on->format('d M Y') }}</td>
                                    <td class="num whitespace-nowrap">{{ $row->ends_on === null ? 'Open' : $row->ends_on->format('d M Y') }}</td>
                                    <td class="num text-right">{{ $row->amount === null ? "The type's" : Hris::peso($row->amount) }}</td>
                                    <td>
                                        {{ $row->prepared_by_name ?? 'System' }}
                                        @if ($row->status === 'APPROVED' && $row->approved_by_name !== null)
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
                        </tbody>
                    </table>
                </div>
            @elseif ($assignReady)
                <p class="px-5 py-4 text-sm text-muted-foreground">No benefit assigned: the company rules in Incentives &amp; Bonuses decide what they receive.</p>
            @endif
        </section>

        <flux:modal wire:model.self="assignOpen" class="w-full max-w-lg" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'assign-benefit-heading')">
            {{-- The form, with every scheduled type, renders only while the dialog is open. --}}
            @if ($assignOpen)
            <form wire:submit="saveAssignment" class="space-y-4">
                <flux:heading size="lg" level="2" id="assign-benefit-heading">Assign benefit</flux:heading>
                <x-kit.select label="Benefit" wire:model="assignForm.type_id">
                    <flux:select.option value="">Choose a benefit</flux:select.option>
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
                    <flux:button type="submit" variant="primary">Save draft</flux:button>
                </div>
            </form>
            @endif
        </flux:modal>

        <section class="hris-card overflow-hidden" aria-labelledby="pay-tax-year-heading" data-tax-year>
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 id="pay-tax-year-heading" class="text-base font-bold">Tax year {{ $taxYear['year'] }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        What the year's finalized payslips and approved balances already carry. An opening balance or a previous employer's figures
                        wait for approval before the year counts them.
                    </p>
                </div>
                @if ($balancesReady)
                    <flux:button variant="primary" icon="plus" wire:click="openBalance">Record balance</flux:button>
                @endif
            </header>
            <dl class="grid gap-4 px-5 py-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted-foreground">Taxable pay so far</dt>
                    <dd class="num text-lg font-semibold" data-tax-year-taxable>{{ Hris::peso($taxYear['taxable']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Tax withheld so far</dt>
                    <dd class="num text-lg font-semibold" data-tax-year-withheld>{{ Hris::peso($taxYear['withheld']) }}</dd>
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
                                <th class="text-right">Taxable income</th>
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
                        <flux:label for="balance-taxable">Taxable income</flux:label>
                        <x-kit.money-input id="balance-taxable" wire:model="balanceForm.taxable_income" aria-label="Taxable income" />
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
                    <flux:input id="balance-reference" wire:model="balanceForm.reference" maxlength="120" placeholder="The certificate or register" />
                </div>
                @if ($balanceError !== null)
                    <p role="alert" class="text-sm text-danger">{{ $balanceError }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="outline" wire:click="$set('balanceOpen', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save draft</flux:button>
                </div>
            </form>
            @endif
        </flux:modal>

        <section class="hris-card overflow-hidden" aria-labelledby="pay-recurring-heading">
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 id="pay-recurring-heading" class="text-base font-bold">Loans &amp; other deductions</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Everything else taken from their pay. A loan with a total stops once it is paid off; each finalized payslip pays it down.
                    </p>
                </div>
                @if ($recurringReady)
                    <flux:button variant="primary" icon="plus" wire:click="addLine('DEDUCTION')">Add loan or deduction</flux:button>
                @endif
            </header>
            @unless ($recurringReady)
                <p class="px-5 py-4 text-xs text-muted-foreground">Recurring lines can be set up once this installation's upgrade finishes.</p>
            @else
                <div class="hris-table-wrap">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Line</th>
                                <th class="text-right">Per payroll</th>
                                <th class="text-right">Left to pay</th>
                                <th>Runs</th>
                                <th>From</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($loans as $line)
                                @include('livewire.records.partials.pay-line-row', ['line' => $line, 'status' => $status, 'withTax' => false])
                            @endforeach
                            @foreach ($percentDeductions as $deduction)
                                <tr wire:key="percent-{{ $deduction['id'] }}">
                                    <td>
                                        <p class="font-medium">{{ $deduction['name'] }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            @if ($deduction['own'] === null)
                                                {{ $deduction['detail'] }}
                                            @elseif ($deduction['own'] <= 0.0)
                                                Not a member · listed: {{ $deduction['detail'] }}
                                            @else
                                                Own rate {{ Hris::moneyText($deduction['own']) }}% · listed: {{ $deduction['detail'] }}
                                            @endif
                                        </p>
                                    </td>
                                    <td class="num whitespace-nowrap text-right">{{ $deduction['amount'] === null ? 'Varies' : Hris::peso($deduction['amount']) }}</td>
                                    <td class="text-right text-muted-foreground">—</td>
                                    <td class="text-sm">Everyone, no end date</td>
                                    <td>@include('livewire.records.partials.pay-source', ['from' => $deduction['own'] === null ? 'company' : 'own', 'status' => $status])</td>
                                    <td class="text-right">
                                        <flux:link :href="route('payroll.settings.deductions')" wire:navigate class="text-sm font-medium">Open rule</flux:link>
                                    </td>
                                </tr>
                            @endforeach
                            @if ($loans->isEmpty() && $percentDeductions === [])
                                <tr wire:key="lines-empty">
                                    <x-kit.empty-state :colspan="6" title="No recurring line yet." hint="Add a loan amortization or a deduction that repeats on every payroll." />
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            @endunless
            @if ($entriesReady && $entries->isNotEmpty())
                <div class="border-t border-border px-5 pt-4 pb-2">
                    <h3 class="text-sm font-semibold">Payments &amp; adjustments outside payroll</h3>
                    <p class="text-xs text-muted-foreground">A payment made straight to the lender lowers what is left; a balance adjustment from the lender's statement raises or lowers it. Each waits for approval.</p>
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
                    <flux:button type="submit" variant="primary">Save draft</flux:button>
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
                        $dialogTitle = ($lineId !== null ? 'Edit recurring line' : ($isDeduction ? 'Add loan or deduction' : 'Add allowance'))
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
                            <flux:label for="line-total">Total to pay off (optional)</flux:label>
                            <x-kit.money-input id="line-total" wire:model.live="lineTotal" aria-label="Total to pay off" />
                            <p class="text-xs text-muted-foreground">A loan's balance: the last payroll deducts only what is left, and the line stops once it is paid. Leave it blank for a deduction with no balance.</p>
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
                    <flux:heading size="lg" id="line-end-heading">End this recurring line</flux:heading>
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
                    <flux:heading size="lg" id="line-delete-heading">Delete this recurring line?</flux:heading>
                    <flux:text>It was never paid on a payslip, so it is removed for good. Draft payrolls stop proposing it.</flux:text>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="outline">Cancel</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" wire:click="deleteLine">Delete</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    @endunless
</div>
