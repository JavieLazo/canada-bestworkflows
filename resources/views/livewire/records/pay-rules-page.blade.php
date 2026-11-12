{{--
    Pay Basis & Rates — one form for one scope (the company rule, an
    Employment status's override, or one employee's own rates, opened from
    Employee Pay Settings): a card choosing whose rules the form holds, then
    ONE frame of six parts, each under a band naming it — the pay basis, the
    daily-rate method, the night window, the rates-by-day-type table, the
    daily caps and the other rates — filled from the scope's rules in use or
    a preset. EACH SCOPE HAS ONE SET OF
    RULES: Save replaces them through the audited command and they apply at
    once to every draft payroll — no date, reason, version list or approval.
    A blank table cell pays "as derived"; its placeholder shows the percent.
    Nothing here prices a payslip: the sample is the method's own division at
    a sample salary.
--}}
@php
    use App\Domain\Payroll\PayRules;
    use App\Livewire\Records\PayRulesPage;
    use App\Modules\Payroll\Application\PayRulesStore;

    $cell = 'num h-8 w-full min-w-[6rem] rounded-md border border-border bg-card px-2.5 text-right text-sm placeholder:text-muted-foreground/70 focus:outline-none focus:ring-2 focus:ring-ring disabled:bg-muted/40';
    // Below the usual minimum is a warning and can be saved; only the invalid cell is an error.
    $low = [];
    foreach ($shortfalls as $line) {
        $low[$line['type'].'.'.$line['cell']] = true;
    }
    $cellState = static fn (string $key): string => $key === $invalidCell ? 'border-danger!' : (isset($low[$key]) ? 'border-warning!' : '');
    $daily = $formBasis === PayRules::BASIS_DAILY;
    $company = $scope === PayRulesStore::COMPANY;
    // An employee with rules of their own; otherwise the inherited rules pay them.
    $ownInForce = $employee !== null && $inForce !== null && $inForce->params !== null;
    // The form's frame, as the Application for Leave draws its own: a band names a part, the body under it holds
    // the part's controls.
    $band = 'flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 border-b border-border bg-muted px-5 py-2 text-xs font-bold uppercase tracking-wide';
    $bandNote = 'text-xs font-normal normal-case tracking-normal text-muted-foreground';
    $bodyLast = 'px-5 py-5';
    $body = $bodyLast.' border-b border-border';
@endphp
<div class="space-y-6">
    @if ($employee !== null)
        <flux:link :href="route('records.employee-pay-settings.show', ['employee' => $employee->id])" wire:navigate class="text-sm">
            ← {{ $employeeName }}'s pay settings
        </flux:link>
    @endif

    @include('livewire.payroll.partials.settings-header', [
        'activeView' => 'rates',
        'title' => 'Pay Basis & Rates',
        'description' => 'How a day, an overtime hour and a night hour are paid.',
    ])

    @if (! $ready || ! $canManage)
        <x-payroll.notice tone="note" class="rounded-lg border px-4 py-3">
            @unless ($ready)
                <p>Payroll uses the built-in rules until this installation's upgrade finishes; they can be saved then.</p>
            @endunless
            @unless ($canManage)
                <p>Your role can view these rules. Saving them requires Payroll access to all data.</p>
            @endunless
        </x-payroll.notice>
    @endif

    {{-- WHOSE rules the form holds: the choice, the presets that fill it, and one line saying what a save reaches. --}}
    <section class="hris-card p-5" data-rates-scope>
        <div class="flex flex-wrap items-end gap-4">
            <x-kit.select label="Applies to" wire:model.live="scope" class="w-full max-w-xs">
                @foreach ($scopes as $option)
                    <flux:select.option value="{{ $option['id'] }}">{{ $option['name'] }}{{ $option['override'] ? ' (override)' : '' }}</flux:select.option>
                @endforeach
            </x-kit.select>
            @if ($canManage && $ready)
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <span class="text-sm text-muted-foreground">Fill in from a preset:</span>
                    <flux:button variant="outline" icon="document-text" wire:click="applyPreset('{{ PayRules::PRESET_STANDARD }}')">Standard</flux:button>
                    <flux:button variant="outline" icon="building-library" wire:click="applyPreset('{{ PayRules::PRESET_GOVERNMENT }}')">Government</flux:button>
                </div>
            @endif
        </div>
        {{-- Applies to is not a filter: it picks WHOSE rules the form below holds and saves. --}}
        <p class="mt-3 text-sm" data-rates-editing>
            <span class="font-semibold">Editing:
                @if ($company)
                    the company rule.
                @elseif ($employee !== null)
                    {{ $employeeName }}'s rates.
                @else
                    the {{ collect($scopes)->firstWhere('id', $scope)['name'] ?? 'employment status' }} override.
                @endif
            </span>
            <span class="text-muted-foreground">
            @if ($company)
                Applies to every employee whose employment status has no rates of its own.
            @elseif ($employee !== null)
                @if ($ownInForce)
                    {{ $employeeName }}'s own rates, in place of {{ $inheritedName }}.
                @else
                    {{ $employeeName }} follows {{ $inheritedName }}, which the form shows. Saving gives this employee rates of their own.
                @endif
            @elseif ($inForce === null)
                This status follows the company rule, which the form shows. Saving gives it rates of its own.
            @else
                This status's own rates, in place of the company rule.
            @endif
            </span>
        </p>
    </section>

    {{-- ONE ruled frame for the whole form, every part the same pattern: a band naming it with its one line, then
         its controls. The parts keep the order a rule is worked out in — what the salary covers, what a day and
         an hour are worth, the night hours, then the rates and limits applied to them — at every width. --}}
    <section class="hris-card overflow-hidden" data-rates-form>
        <div class="{{ $band }}" data-rates-part>
            <h2>Pay basis</h2>
            <p class="{{ $bandNote }}">What the monthly salary covers.</p>
        </div>
        <div class="{{ $body }}">
            <div class="grid min-w-0 gap-x-6 gap-y-4 lg:grid-cols-2 lg:items-start">
                <div class="min-w-0 space-y-2">
                    <x-kit.select label="Basis" wire:model.live="formBasis" :disabled="! $canManage">
                        <flux:select.option value="{{ PayRules::BASIS_MONTHLY }}">Monthly-paid</flux:select.option>
                        <flux:select.option value="{{ PayRules::BASIS_DAILY }}">Daily-paid</flux:select.option>
                    </x-kit.select>
                    <p class="text-xs text-muted-foreground">Monthly-paid: the salary covers every scheduled day, holidays included. Daily-paid: only days worked and paid leave are paid.</p>
                </div>
                <div class="flex min-w-0 items-center gap-3 rounded-md border border-border bg-muted/40 p-4">
                    <flux:switch id="rates-holiday-rule" wire:model.live="formHolidayNeedsAttendance" :disabled="! $canManage || ! $daily" />
                    <div>
                        <flux:label for="rates-holiday-rule">Holiday pay needs attendance the day before</flux:label>
                        <p class="text-xs text-muted-foreground">Daily-paid only. Off: an unworked holiday is paid regardless.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="{{ $band }}" data-rates-part>
            <h2>Daily rate</h2>
            <p class="{{ $bandNote }}">How a day's pay comes from the monthly salary. A Rate Per Day on the employee record is used as it is.</p>
        </div>
        <div class="{{ $body }}">
            <div class="grid min-w-0 gap-x-6 gap-y-4 lg:grid-cols-2 lg:items-start">
                <div class="min-w-0 space-y-4">
                    <x-kit.select label="Method" wire:model.live="formMethod" :disabled="! $canManage">
                        <flux:select.option value="{{ PayRules::METHOD_MONTH }}">The month's working days</flux:select.option>
                        <flux:select.option value="{{ PayRules::METHOD_FACTOR }}">Annual factor (261, 313, 365…)</flux:select.option>
                        <flux:select.option value="{{ PayRules::METHOD_DIVISOR }}">Fixed divisor</flux:select.option>
                    </x-kit.select>
                    @if ($formMethod !== PayRules::METHOD_MONTH)
                        @php $divisorError = $figuresError !== null && str_starts_with($figuresError, 'Daily rate:'); @endphp
                        <div class="space-y-2">
                            <flux:label for="rates-divisor">{{ $formMethod === PayRules::METHOD_FACTOR ? 'Days a year' : 'Divisor' }}</flux:label>
                            <flux:input id="rates-divisor" type="number" min="1" step="0.01" class:input="num" wire:model.live.debounce.400ms="formDivisor" :disabled="! $canManage" :aria-label="$formMethod === PayRules::METHOD_FACTOR ? 'Days a year' : 'Divisor'" aria-invalid="{{ $divisorError ? 'true' : 'false' }}" />
                            @if ($divisorError)
                                <p class="text-xs text-danger">{{ $figuresError }}</p>
                            @endif
                        </div>
                    @endif
                    <p class="text-xs text-muted-foreground">Working days vary by month. A factor or divisor gives one rate all year.</p>
                </div>
                <div class="min-w-0 space-y-4">
                    <div class="grid gap-3 sm:grid-cols-2" data-rate-steps>
                        <div class="space-y-2">
                            <flux:label for="rates-hourly-hours">Hours for the hourly rate</flux:label>
                            <flux:input id="rates-hourly-hours" type="number" min="1" max="24" step="0.5" class:input="num" placeholder="Shift's paid hours" wire:model.live.debounce.400ms="formHourlyHours" :disabled="! $canManage" aria-label="Hours for the hourly rate" />
                            <p class="text-xs text-muted-foreground">Blank: each shift's own paid hours. A figure divides the day by it on every shift.</p>
                        </div>
                        <div class="space-y-2">
                            <x-kit.select label="Round each rate step to" wire:model.live="formRounding" :disabled="! $canManage">
                                <flux:select.option value="">No rounding</flux:select.option>
                                <flux:select.option value="0">Whole pesos</flux:select.option>
                                <flux:select.option value="1">1 decimal</flux:select.option>
                                <flux:select.option value="2">2 decimals (centavos)</flux:select.option>
                                <flux:select.option value="3">3 decimals</flux:select.option>
                                <flux:select.option value="4">4 decimals</flux:select.option>
                            </x-kit.select>
                            <p class="text-xs text-muted-foreground">Each rate is rounded before it is multiplied.</p>
                        </div>
                    </div>
                    @if ($figuresError !== null && (str_starts_with($figuresError, 'Hours for the hourly rate:') || str_starts_with($figuresError, 'Rounding:')))
                        <p class="text-xs text-danger">{{ $figuresError }}</p>
                    @endif
                </div>
                {{-- What the method and the two steps come to, under both of them. --}}
                <div class="min-w-0 lg:col-span-2">
                    @if ($sample !== null)
                        <p class="rounded-md border border-border bg-muted px-4 py-3 text-sm">At ₱30,000{{ $sample['note'] !== '' ? ', '.$sample['note'] : '' }}: <strong class="num">{{ $sample['daily'] }}</strong> a day, <strong class="num">{{ $sample['hourly'] }}</strong> an hour on {{ trim($formHourlyHours) === '' ? 'an 8-hour shift' : $sample['hours'].' hours' }}.</p>
                    @else
                        <p class="rounded-md border border-border bg-muted px-4 py-3 text-sm text-muted-foreground">Enter the {{ $formMethod === PayRules::METHOD_FACTOR ? 'days a year' : 'divisor' }} to see a sample at ₱30,000.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="{{ $band }}" data-rates-part>
            <h2>Night work</h2>
            <p class="{{ $bandNote }}">The hours that earn the night rate.</p>
        </div>
        <div class="{{ $body }}">
            <div class="grid min-w-0 gap-x-6 gap-y-4 lg:grid-cols-2 lg:items-start">
                <div class="min-w-0 space-y-2">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-2">
                            <flux:label>From</flux:label>
                            <x-kit.time-field wire:model.live="formNightStart" aria-label="Night work from" :disabled="! $canManage" />
                        </div>
                        <div class="space-y-2">
                            <flux:label>To</flux:label>
                            <x-kit.time-field wire:model.live="formNightEnd" aria-label="Night work to" :disabled="! $canManage" />
                        </div>
                    </div>
                    @if ($figuresError !== null && str_starts_with($figuresError, 'Night work:'))
                        <p class="text-xs text-danger">{{ $figuresError }}</p>
                    @else
                        <p class="text-xs text-muted-foreground">Scheduled hours in this window earn the night rate, up to the daily cap.</p>
                    @endif
                </div>
                <div class="flex min-w-0 items-center gap-3 rounded-md border border-border bg-muted/40 p-4">
                    <flux:switch id="rates-night-ot" wire:model.live="formNightOnOvertime" :disabled="! $canManage" />
                    <div>
                        <flux:label for="rates-night-ot">Night pay on overtime hours</flux:label>
                        <p class="text-xs text-muted-foreground">On: overtime worked inside the night hours also earns the night premium.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="border-b border-border">
            <div class="{{ $band }}" data-rates-part>
                <h2>Rates by day type</h2>
            </div>
            <div class="px-5 py-4">
                    <p class="text-sm text-muted-foreground">A percent of the day's rate for a worked day, and of the hourly rate for an overtime or night hour. A blank cell pays the figure shown in grey.</p>
                    {{-- What the cell borders mean, beside the cells that carry them. --}}
                    <p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground" data-rates-legend>
                        <span class="inline-flex items-center gap-1.5"><span class="inline-block size-3 rounded-sm border-2 border-primary/60" aria-hidden="true"></span>Base rate: the other cells are worked out from it</span>
                        <span class="inline-flex items-center gap-1.5"><span class="inline-block size-3 rounded-sm border border-warning" aria-hidden="true"></span>Below the usual minimum</span>
                    </p>
            </div>
            {{-- The warnings sit above the table, where the cells they name are in view with them. --}}
            @if ($shortfalls !== [])
                <x-payroll.notice tone="warning" class="border-t px-5 py-3">
                    @foreach ($shortfalls as $line)
                        <p>{{ $line['text'] }}</p>
                    @endforeach
                    @if ($canManage)
                        <p class="mt-1 text-xs text-muted-foreground">A rate below the usual minimum can still be saved.</p>
                    @endif
                </x-payroll.notice>
            @endif
            <div class="hris-table-wrap hris-table-scroll border-t border-border">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th>Day type</th>
                            <th class="text-right">Worked day</th>
                            <th class="text-right">Overtime hour</th>
                            <th class="text-right">Night hour</th>
                            <th class="text-right">Not worked</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dayTypes as $type => $name)
                            @php
                                $rest = str_ends_with($type, '_REST');
                            @endphp
                            <tr wire:key="rate-{{ $type }}">
                                <td class="font-medium">{{ $name }}</td>
                                @foreach (['worked' => 'worked day', 'overtime' => 'overtime hour', 'night' => 'night hour'] as $col => $colName)
                                    @php
                                        $base = in_array("{$type}.{$col}", PayRulesPage::BASE_CELLS, true);
                                        $state = $cellState("{$type}.{$col}");
                                    @endphp
                                    <td class="text-right">
                                        @if ($type === 'ORDINARY' && $col === 'worked')
                                            <span class="num">100</span>
                                        @else
                                            <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="formMatrix.{{ $type }}.{{ $col }}" placeholder="{{ $derived[$type][$col] ?? '' }}" aria-label="{{ $name }} {{ $colName }}" aria-invalid="{{ "{$type}.{$col}" === $invalidCell ? 'true' : 'false' }}" @if ($base) aria-required="true" title="A base rate: the other cells derive from it" @endif class="{{ $cell }} {{ $state !== '' ? $state : ($base ? 'border-2 border-primary/60' : '') }}" @disabled(! $canManage) />
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-right">
                                    @if ($type === 'ORDINARY')
                                        <span class="text-muted-foreground" title="An unworked ordinary workday is an absence and is never paid">Not paid</span>
                                    @elseif ($rest)
                                        <span class="text-muted-foreground" title="An unworked rest day earns nothing">—</span>
                                    @elseif (! $daily)
                                        <span class="text-muted-foreground" title="Under Monthly-paid the salary already covers a scheduled holiday">In salary</span>
                                    @else
                                        <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="formMatrix.{{ $type }}.unworked" placeholder="0" aria-label="{{ $name }} not worked" aria-invalid="{{ $type.'.unworked' === $invalidCell ? 'true' : 'false' }}" class="{{ $cell }} {{ $cellState($type.'.unworked') }}" @disabled(! $canManage) />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-border bg-muted/40 px-5 py-2.5 text-xs text-muted-foreground">Blank holiday cells use the Holidays list. Double holidays need a typed rate, or a payroll with one cannot be finalized.</p>
        </div>

        {{-- The figures that are not cells of the table, in two named parts: the part's line carries what its fields
             share, so each label stays short enough for three across. The aria-labels keep the whole phrase. --}}
        <div data-rates-extra>
            <div class="{{ $band }}" data-rates-part>
                <h2 id="rates-limits-heading">Daily limits</h2>
                <p class="{{ $bandNote }}">The most hours a day that are paid at each rate.</p>
            </div>
            <div class="{{ $body }}" role="group" aria-labelledby="rates-limits-heading">
                <div class="grid min-w-0 gap-4 sm:grid-cols-3">
                    <div class="space-y-2">
                        <flux:label for="rates-ot-cap">Overtime hours</flux:label>
                        <flux:input id="rates-ot-cap" type="number" min="0" max="24" step="0.5" class:input="num" wire:model.live.debounce.400ms="formOvertimeMax" :disabled="! $canManage" aria-label="Overtime hours a day at most" />
                    </div>
                    <div class="space-y-2">
                        <flux:label for="rates-night-cap">Night hours</flux:label>
                        <flux:input id="rates-night-cap" type="number" min="0" max="24" step="0.5" class:input="num" wire:model.live.debounce.400ms="formNightMax" :disabled="! $canManage" aria-label="Night hours a day at most" />
                    </div>
                    <div class="space-y-2">
                        <flux:label for="rates-rest-cap">Rest-day hours</flux:label>
                        <flux:input id="rates-rest-cap" type="number" min="0" max="24" step="0.5" class:input="num" wire:model.live.debounce.400ms="formRestMax" :disabled="! $canManage" aria-label="Rest-day hours a day at most" />
                    </div>
                </div>
            </div>
            <div class="{{ $band }}" data-rates-part>
                <h2 id="rates-other-heading">Other rates and checks</h2>
                <p class="{{ $bandNote }}">Two overtime rates outside the table, and the take-home check.</p>
            </div>
            <div class="{{ $bodyLast }}" role="group" aria-labelledby="rates-other-heading">
                <div class="grid min-w-0 gap-4 sm:grid-cols-3">
                    <div class="space-y-2">
                        <flux:label for="rates-holiday-ot">Holiday overtime (%)</flux:label>
                        <flux:input id="rates-holiday-ot" type="number" min="0" max="1000" step="0.01" class:input="num" wire:model.live.debounce.400ms="formHolidayOvertime" :disabled="! $canManage" aria-label="Holiday overtime as a percent of the holiday rate" />
                        <p class="text-xs text-muted-foreground">Of the holiday rate.</p>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="rates-seaport-ot">Seaport overtime (%)</flux:label>
                        <flux:input id="rates-seaport-ot" type="number" min="0" max="1000" step="0.01" class:input="num" placeholder="Day's overtime rate" wire:model.live.debounce.400ms="formSeaportRate" :disabled="! $canManage" aria-label="Seaport overtime hour as a percent of the hourly rate" />
                        <p class="text-xs text-muted-foreground">Of the hourly rate, for days approved as seaport overtime, whatever the day type. Blank: the day's own overtime rate. No daily cap.</p>
                    </div>
                    <div class="space-y-2">
                        <flux:label for="rates-take-home">Minimum take-home pay</flux:label>
                        <x-kit.money-input id="rates-take-home" wire:model.live.debounce.400ms="formMinimumTakeHome" :disabled="! $canManage" aria-label="Minimum take-home pay" placeholder="No minimum" />
                        <p class="text-xs text-muted-foreground">Flags a draft with net pay below this. Nothing is cut automatically.</p>
                    </div>
                </div>
            </div>
        </div>
        @if ($figuresError !== null && ! str_starts_with($figuresError, 'Daily rate:') && ! str_starts_with($figuresError, 'Night work:') && ! str_starts_with($figuresError, 'Hours for the hourly rate:') && ! str_starts_with($figuresError, 'Rounding:'))
            <x-payroll.notice tone="danger" class="border-t px-5 py-3">{{ $figuresError }}</x-payroll.notice>
        @endif
    </section>

    @if ($canManage && $ready)
        {{-- An employee's own rates are removed in one step: they follow the general rules again. --}}
        @if ($ownInForce)
            <section class="hris-card flex flex-wrap items-center gap-3 px-5 py-4">
                <p class="flex-1 text-sm text-muted-foreground">{{ $employeeName }} has rates of their own. Removing them returns this employee to {{ $inheritedName }}.</p>
                <flux:button variant="outline" icon="arrow-uturn-left" wire:click="returnToGeneral" wire:confirm="Remove {{ $employeeName }}'s own rates?">Return to {{ $inheritedName }}</flux:button>
            </section>
        @endif

        {{-- Stays in view while the long form scrolls under it, above the table's sticky head. --}}
        <form class="hris-card sticky bottom-4 z-10 space-y-2 px-5 py-3 shadow-lg" wire:submit="save" data-rates-save>
            <div class="flex flex-wrap items-center gap-3">
                @include('livewire.payroll.partials.settings-applies-note', ['class' => 'min-w-0 flex-1 text-sm text-muted-foreground'])
                @if ($dirty)
                    <span class="pill pill-caution">Unsaved changes</span>
                @endif
                <flux:button type="submit" variant="primary" icon="check">Save</flux:button>
            </div>
            @if ($formError !== null)
                <p role="alert" class="text-xs text-danger">{{ $formError }}</p>
            @endif
        </form>
    @endif

    {{-- Applies to was changed while the form holds unsaved edits. --}}
    <flux:modal
        wire:model.self="scopeConfirmOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'rates-scope-heading')"
    >
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg" id="rates-scope-heading">Discard the unsaved changes?</flux:heading>
                <flux:text>Switching to {{ $pendingScopeName !== '' ? $pendingScopeName : 'another scope' }} reloads the form; the figures you changed here have not been saved.</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button variant="outline" wire:click="keepEditing">Keep editing</flux:button>
                <flux:button variant="primary" wire:click="discardAndSwitch">Discard and switch</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
