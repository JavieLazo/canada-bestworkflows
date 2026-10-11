{{--
    Pay Basis & Rates — one form for one scope (the company rule, or an
    Employment status's override): the pay basis, the daily-rate method,
    the night window, the daily caps and the rates-by-day-type table, filled
    from the scope's version in force or a preset. Every save is a new dated
    version through the audited command. A blank table cell is priced "as
    derived"; its placeholder shows what that is. Nothing here prices a
    payslip: the sample is the method's own division at a sample salary.
--}}
@php
    use App\Domain\Payroll\PayRules;
    use App\Livewire\Records\PayRulesPage;
    use App\Modules\Payroll\Application\PayRulesStore;

    $cell = 'num h-8 w-full min-w-[6rem] rounded-md border border-border bg-background px-2.5 text-right text-sm placeholder:text-muted-foreground/70 focus:outline-none focus:ring-2 focus:ring-ring disabled:bg-muted/40';
    $flagged = [];
    foreach ($shortfalls as $line) {
        $flagged[$line['type'].'.'.$line['cell']] = true;
    }
    if ($invalidCell !== null) {
        $flagged[$invalidCell] = true;
    }
    $daily = $formBasis === PayRules::BASIS_DAILY;
    $company = $scope === PayRulesStore::COMPANY;
    $inForceId = $inForce?->id;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Pay Basis &amp; Rates</h1>
        <p class="mt-1 text-sm text-muted-foreground">How a day, an hour of overtime and a night hour are priced. Every change takes effect from a date; finalized payslips keep the version they were paid with.</p>
    </div>

    <div class="rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground">
        @if (! $ready)
            Payroll prices from the reference rules until this installation's upgrade finishes; versions can be saved then.
        @else
            Saved changes apply to draft payrolls whose period starts on or after the effective date and need a fresh review before finalization. Finalized payslips stay unchanged.
        @endif
        @unless ($canManage)
            <p class="mt-2">Your role can view these rules. Saving a version requires Records Management access to all data.</p>
        @endunless
    </div>

    <section class="hris-card p-5">
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
        <p class="mt-3 text-xs text-muted-foreground">
            @if ($company)
                The company rule prices every employee whose employment status has no override of its own.
            @elseif ($inForce === null && $latest === null)
                No override yet: this status follows the company rule. The form starts from it; saving starts an override.
            @elseif ($inForce === null)
                This status's override starts on <span class="num">{{ $latest->effective_from->format('d M Y') }}</span>; until then it follows the company rule, which the form shows.
            @else
                This status's override, in place of the company rule.
            @endif
            @if ($canManage && $ready)
                A preset fills in every figure below. Adjust anything before saving.
            @endif
        </p>
    </section>

    <div class="grid gap-4 xl:grid-cols-3">
        <section class="hris-card min-w-0 p-5">
            <h2 class="text-lg font-bold">Pay basis</h2>
            <p class="mt-2 text-sm text-muted-foreground">What the monthly salary covers.</p>
            <div class="mt-5 space-y-4">
                <x-kit.select label="Basis" wire:model.live="formBasis" :disabled="! $canManage">
                    <flux:select.option value="{{ PayRules::BASIS_MONTHLY }}">Monthly-paid</flux:select.option>
                    <flux:select.option value="{{ PayRules::BASIS_DAILY }}">Daily-paid</flux:select.option>
                </x-kit.select>
                <p class="text-xs text-muted-foreground">Monthly-paid: the salary covers every scheduled day, holidays included; only absences, late and undertime are deducted. Daily-paid: days worked and paid leave are paid, and an unworked holiday earns what its Not worked cell says.</p>
                <div class="flex items-center gap-3 rounded-md border border-border bg-muted/40 p-4">
                    <flux:switch id="rates-holiday-rule" wire:model.live="formHolidayNeedsAttendance" :disabled="! $canManage || ! $daily" />
                    <div>
                        <flux:label for="rates-holiday-rule">Holiday pay needs attendance the day before</flux:label>
                        <p class="text-xs text-muted-foreground">Daily-paid only. Off: an unworked holiday is paid regardless.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="hris-card min-w-0 p-5">
            <h2 class="text-lg font-bold">Daily rate</h2>
            <p class="mt-2 text-sm text-muted-foreground">How a day's pay is derived from the monthly salary. An entered Rate Per Day on the employee record is used as it stands.</p>
            <div class="mt-5 space-y-4">
                <x-kit.select label="Method" wire:model.live="formMethod" :disabled="! $canManage">
                    <flux:select.option value="{{ PayRules::METHOD_MONTH }}">The month's working days</flux:select.option>
                    <flux:select.option value="{{ PayRules::METHOD_FACTOR }}">Yearly factor (261, 313, 365…)</flux:select.option>
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
                @if ($sample !== null)
                    <p class="rounded-md border border-border bg-muted px-4 py-3 text-sm">At ₱30,000{{ $sample['note'] !== '' ? ', '.$sample['note'] : '' }}: <strong class="num">{{ $sample['daily'] }}</strong> a day, <strong class="num">{{ $sample['hourly'] }}</strong> an hour on an 8-hour shift.</p>
                @else
                    <p class="rounded-md border border-border bg-muted px-4 py-3 text-sm text-muted-foreground">Enter the {{ $formMethod === PayRules::METHOD_FACTOR ? 'days a year' : 'divisor' }} to see a sample at ₱30,000.</p>
                @endif
                <p class="text-xs text-muted-foreground">A yearly factor or a fixed divisor gives one day rate for every month. The month's working days follow the shift roster.</p>
            </div>
        </section>

        <section class="hris-card min-w-0 p-5">
            <h2 class="text-lg font-bold">Night work</h2>
            <p class="mt-2 text-sm text-muted-foreground">The hours that earn the night rate.</p>
            <div class="mt-5 space-y-4">
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
                    <p class="text-xs text-muted-foreground">Scheduled hours between these times earn the night hour rate, up to the night cap below. A window that ends before its start runs into the next day.</p>
                @endif
                <div class="flex items-center gap-3 rounded-md border border-border bg-muted/40 p-4">
                    <flux:switch id="rates-night-ot" wire:model.live="formNightOnOvertime" :disabled="! $canManage" />
                    <div>
                        <flux:label for="rates-night-ot">Night pay on overtime hours</flux:label>
                        <p class="text-xs text-muted-foreground">On: overtime worked inside the night window also earns the night add-on.</p>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <div>
                <h2 class="text-lg font-bold">Rates by day type</h2>
                <p class="text-sm text-muted-foreground">Percent of the day's rate for a worked day (a worked rest day is paid by the hour: its percent on the hourly rate for each hour worked); of the hourly rate for an overtime hour and a night hour. The three outlined cells are the base rates the rest derive from; a blank cell is priced as shown in grey.</p>
            </div>
        </header>
        <div class="hris-table-wrap hris-table-scroll">
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
                                    $bad = isset($flagged["{$type}.{$col}"]);
                                @endphp
                                <td class="text-right">
                                    @if ($type === 'ORDINARY' && $col === 'worked')
                                        <span class="num">100</span>
                                    @else
                                        <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="formMatrix.{{ $type }}.{{ $col }}" placeholder="{{ $derived[$type][$col] ?? '' }}" aria-label="{{ $name }} {{ $colName }}" aria-invalid="{{ $bad ? 'true' : 'false' }}" @if ($base) aria-required="true" title="A base rate: the other cells derive from it" @endif class="{{ $cell }} {{ $bad ? 'border-danger!' : ($base ? 'border-2 border-primary/60' : '') }}" @disabled(! $canManage) />
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
                                    <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="formMatrix.{{ $type }}.unworked" placeholder="0" aria-label="{{ $name }} not worked" aria-invalid="{{ isset($flagged[$type.'.unworked']) ? 'true' : 'false' }}" class="{{ $cell }} {{ isset($flagged[$type.'.unworked']) ? 'border-danger!' : '' }}" @disabled(! $canManage) />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="grid gap-4 border-t border-border px-5 py-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="space-y-2">
                <flux:label for="rates-ot-cap">Overtime hours a day at most</flux:label>
                <flux:input id="rates-ot-cap" type="number" min="0" max="24" step="0.5" class:input="num" wire:model.live.debounce.400ms="formOvertimeMax" :disabled="! $canManage" aria-label="Overtime hours a day at most" />
            </div>
            <div class="space-y-2">
                <flux:label for="rates-night-cap">Night hours a day at most</flux:label>
                <flux:input id="rates-night-cap" type="number" min="0" max="24" step="0.5" class:input="num" wire:model.live.debounce.400ms="formNightMax" :disabled="! $canManage" aria-label="Night hours a day at most" />
            </div>
            <div class="space-y-2">
                <flux:label for="rates-rest-cap">Rest-day hours a day at most</flux:label>
                <flux:input id="rates-rest-cap" type="number" min="0" max="24" step="0.5" class:input="num" wire:model.live.debounce.400ms="formRestMax" :disabled="! $canManage" aria-label="Rest-day hours a day at most" />
            </div>
            <div class="space-y-2">
                <flux:label for="rates-holiday-ot">Holiday overtime (% of the holiday rate)</flux:label>
                <flux:input id="rates-holiday-ot" type="number" min="0" max="1000" step="0.01" class:input="num" wire:model.live.debounce.400ms="formHolidayOvertime" :disabled="! $canManage" aria-label="Holiday overtime as a percent of the holiday rate" />
            </div>
        </div>
        <div class="space-y-1 border-t border-border px-5 py-3 text-xs text-muted-foreground">
            <p>A worked holiday left blank is paid its own percent from the Holidays list; its overtime hour that percent times the holiday overtime rate; a holiday on a rest day also times the worked rest day. A night hour left blank is the worked rate plus the ordinary night add-on.</p>
            <p>Two regular holidays on one day are priced only with a figure in those two rows; left blank, payroll holds a payroll with such a day until one is entered.</p>
            <p>Not worked is what an unworked scheduled holiday earns under Daily-paid. An ordinary or rest day is never paid unworked, and under Monthly-paid the salary already covers a scheduled holiday.</p>
        </div>
        @if ($figuresError !== null && ! str_starts_with($figuresError, 'Daily rate:') && ! str_starts_with($figuresError, 'Night work:'))
            <p role="alert" class="border-t border-danger/20 bg-danger-soft px-5 py-3 text-sm text-danger">{{ $figuresError }}</p>
        @endif
        @if ($shortfalls !== [])
            <div role="status" class="border-t border-warning/20 bg-warning-soft px-5 py-3 text-sm text-foreground">
                @foreach ($shortfalls as $line)
                    <p>{{ $line['text'] }}</p>
                @endforeach
                @if ($canManage)
                    <p class="mt-1 text-xs text-muted-foreground">A rate below the usual minimum can still be saved; the reason you give is kept in the audit trail.</p>
                @endif
            </div>
        @endif
    </section>

    @if ($canManage && $ready)
        <section class="hris-card p-5">
            <form class="space-y-4" wire:submit="save">
                <div class="flex flex-wrap items-end gap-4">
                    <div class="w-full max-w-xs space-y-2">
                        <flux:label for="rates-from">Effective from</flux:label>
                        <x-kit.date-field id="rates-from" wire:model.live="formEffectiveFrom" aria-label="Rates effective from" />
                    </div>
                    <div class="w-full min-w-[12rem] flex-1 space-y-2">
                        <flux:label for="rates-reason">Reason</flux:label>
                        <flux:input id="rates-reason" wire:model="formReason" maxlength="160" placeholder="Why this version is needed" aria-required="true" />
                    </div>
                    <div class="flex items-center gap-3">
                        @if ($dirty)
                            <span class="pill pill-caution">Unsaved changes</span>
                        @endif
                        <flux:button type="submit" variant="primary" icon="arrow-down-tray">Save as new version</flux:button>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">A version takes effect on the 1st or the 16th, the first day of a payroll period, so no payroll is priced under two versions. Every version carries a reason, kept in the audit trail.</p>
                @if ($formError !== null)
                    <p role="alert" class="text-xs text-danger">{{ $formError }}</p>
                @elseif ($cutoffNote !== null)
                    <p role="status" class="text-xs text-warning">{{ $cutoffNote }}</p>
                @endif
                <p class="text-xs text-muted-foreground">
                    @if ($loaded !== null && $inForceId !== null && $loaded->id !== $inForceId && $loaded->effective_from->format('Y-m-d') <= $today)
                        Showing the superseded version from <span class="num">{{ $loaded->effective_from->format('d M Y') }}</span>; saving starts a new version with these figures.
                    @elseif ($loaded !== null && $loaded->effective_from->format('Y-m-d') > $today)
                        Showing the upcoming version from <span class="num">{{ $loaded->effective_from->format('d M Y') }}</span>; saving starts another version with these figures.
                    @endif
                    @if ($inForce !== null)
                        Current version in use since <span class="num">{{ $inForce->effective_from->format('d M Y') }}</span>{{ $upcoming !== null ? '; a version dated '.$upcoming->effective_from->format('d M Y').' takes effect then' : '' }}.
                    @elseif ($latest !== null)
                        The first version takes effect on <span class="num">{{ $latest->effective_from->format('d M Y') }}</span>.
                    @elseif (! $company && $companyInForce !== null)
                        The company rule in use since <span class="num">{{ $companyInForce->effective_from->format('d M Y') }}</span>.
                    @endif
                    @if ($effectiveText !== '')
                        Saving starts a new version on <span class="num">{{ $effectiveText }}</span>; draft payrolls from that date are recalculated and need a fresh review.
                    @else
                        Choose the date the new version takes effect.
                    @endif
                </p>
            </form>
        </section>
    @endif

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <h2 class="text-lg font-bold">Versions</h2>
        </header>
        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Effective from</th>
                        <th>Reason</th>
                        <th>Added by</th>
                        <th>Status</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($versions->isEmpty())
                        <tr wire:key="empty">
                            <x-kit.empty-state colspan="5" :title="$company ? 'No version yet.' : 'No override yet. This status follows the company rule.'" />
                        </tr>
                    @endif
                    @foreach ($versions as $row)
                        @php
                            $rowFrom = $row->effective_from->format('Y-m-d');
                            $rowLoaded = $loaded !== null && $loaded->id === $row->id;
                        @endphp
                        <tr wire:key="version-{{ $row->id }}" @if ($rowLoaded) aria-current="true" @endif>
                            <td class="num whitespace-nowrap">{{ $row->effective_from->format('d M Y') }}</td>
                            <td class="text-muted-foreground">{{ $row->reason ?? '—' }}</td>
                            <td>
                                {{ $row->created_by_name ?? 'System' }}
                                <span class="num text-muted-foreground">· {{ $row->created_at->setTimezone(\App\Support\ManilaClock::TZ)->format('d M Y') }}</span>
                            </td>
                            <td>
                                @if ($rowFrom > $today)
                                    <span class="pill pill-caution">Upcoming</span>
                                @elseif ($row->id === $inForceId)
                                    <span class="pill pill-success">Current</span>
                                @else
                                    <span class="pill pill-muted">Superseded</span>
                                @endif
                                @if ($rowLoaded)
                                    <span class="pill pill-primary">In the form</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @unless ($rowLoaded)
                                    <x-kit.row-actions :label="'Version from '.$row->effective_from->format('d M Y')">
                                        <flux:menu.item icon="eye" wire:click="loadVersion('{{ $row->id }}')">View in the form</flux:menu.item>
                                    </x-kit.row-actions>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">A saved version's figures never change. To change a figure, save a new version with the date it takes effect.</p>
    </section>

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
