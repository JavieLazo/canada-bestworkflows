{{--
    Contribution & Tax Tables — one tab per table (SSS, PhilHealth, Pag-IBIG,
    Withholding Tax): its dated versions newest first, the figures of the
    version in view, what they deduct at sample amounts, and the dialogs
    whoever manages the Payroll Settings gets: a new version from a date
    (with a live preview), the same dialog to correct a version that has not
    taken effect, a confirmation to withdraw one, and the latest version's
    figures confirmed through a later date. Nothing here prices a payslip:
    the samples call the engine's own functions on the figures.
--}}
@php
    use App\Domain\Payroll\StatutorySchedule;
    use App\Livewire\Records\StatutoryTablesPage;
    use App\Modules\Payroll\Application\StatutoryTables;

    $tabClass = static fn (bool $on): string => 'inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all '.($on ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground');
    $cell = 'num h-8 w-full min-w-[6rem] rounded-md border border-border bg-background px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring';
    $withholding = $tab === StatutorySchedule::WITHHOLDING_TAX;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Contribution &amp; Tax Tables</h1>
        <p class="mt-1 text-sm text-muted-foreground">The SSS, PhilHealth, Pag-IBIG and withholding tax tables payroll computes from, each by the date it took effect.</p>
    </div>

    @include('livewire.payroll.partials.settings-switch', ['activeView' => 'tables'])

    <div class="rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground">
        @if (! $ready)
            Payroll prices from the built-in figures until this installation's upgrade finishes; the tables can be viewed, confirmed and added then.
        @elseif ($confirmedThrough !== null && $confirmationLapsed)
            Payroll computes from the version in force on a period's first day, and keeps using the latest version until a new one starts.
            <strong class="text-foreground">The {{ implode(', ', $confirmationEndsWith) }} {{ count($confirmationEndsWith) === 1 ? 'table was' : 'tables were' }} last confirmed through {{ StatutoryTables::day($confirmedThrough) }}</strong>: payrolls past that date still run, with a reminder to check the figures, until the table is confirmed or given a new version.
        @elseif ($confirmedThrough !== null)
            Payroll computes from the version in force on a period's first day, and keeps using the latest version until a new one starts. The {{ implode(', ', $confirmationEndsWith) }} {{ count($confirmationEndsWith) === 1 ? 'table is' : 'tables are' }} confirmed through <strong class="text-foreground">{{ StatutoryTables::day($confirmedThrough) }}</strong>; a payroll past that date shows a reminder to check the figures.
        @else
            Payroll cannot run until every table has a version.
        @endif
        A new version applies to draft payrolls whose period starts on or after its effective date and needs a fresh review. Finalized payslips keep the figures they were paid with.
        @unless ($canManage)
            <p class="mt-2">Your role can view these tables. Adding, correcting or confirming a version requires Payroll access to all data.</p>
        @endunless
    </div>

    <div class="inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground" role="tablist" aria-label="Tables">
        @foreach ($tabs as $kind => $name)
            <button type="button" role="tab" aria-selected="{{ $tab === $kind ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $kind }}')" class="{{ $tabClass($tab === $kind) }}">
                {{ $name }}
            </button>
        @endforeach
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <h2 class="text-lg font-bold">{{ $label }} versions</h2>
            @if ($canManage && $ready)
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <flux:button variant="primary" icon="plus" wire:click="openAdd">Add new version</flux:button>
                </div>
            @endif
        </header>
        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Effective from</th>
                        <th>Confirmed through</th>
                        <th>Reference</th>
                        <th>Added by</th>
                        <th>Status</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($versions->isEmpty())
                        <tr wire:key="empty">
                            <x-kit.empty-state colspan="6" title="No {{ $label }} table version yet." />
                        </tr>
                    @endif
                    @foreach ($versions as $row)
                        @php
                            $rowFrom = $row->effective_from->format('Y-m-d');
                            $rowInForce = $inForce !== null && $row->id === $inForce->id;
                            $rowShown = $shown !== null && $row->id === $shown->id;
                            $rowUpcoming = $rowFrom > $today;
                            $rowMenu = (! $rowShown) || ($canManage && $ready && ($loop->first || $rowUpcoming));
                        @endphp
                        <tr wire:key="version-{{ $row->id }}" @if ($rowShown) aria-current="true" class="bg-primary-soft/40" @endif>
                            <td class="num whitespace-nowrap">{{ $row->effective_from->format('d M Y') }}</td>
                            <td class="num whitespace-nowrap">{{ $row->covered_through->format('d M Y') }}</td>
                            <td class="text-muted-foreground">{{ $row->reference ?? '—' }}</td>
                            <td>
                                {{ $row->created_by_name ?? 'System' }}
                                <span class="num text-muted-foreground">· {{ $row->created_at->setTimezone(\App\Support\ManilaClock::TZ)->format('d M Y') }}</span>
                            </td>
                            <td>
                                @if ($rowFrom > $today)
                                    <span class="pill pill-caution">Upcoming</span>
                                @elseif ($rowInForce && $row->covered_through->format('Y-m-d') < $today)
                                    <span class="pill pill-caution">Check due</span>
                                @elseif ($rowInForce)
                                    <span class="pill pill-success">Current</span>
                                @else
                                    <span class="pill pill-muted">Superseded</span>
                                @endif
                                @if ($rowShown)
                                    <span class="pill pill-primary">In view</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($rowMenu)
                                    <x-kit.row-actions :label="$label.' version from '.$row->effective_from->format('d M Y')">
                                        @unless ($rowShown)
                                            <flux:menu.item icon="eye" wire:click="viewFigures('{{ $row->id }}')">View figures</flux:menu.item>
                                        @endunless
                                        @if ($canManage && $ready && $loop->first)
                                            <flux:menu.item icon="calendar" wire:click="openConfirm('{{ $row->id }}')">Confirm figures</flux:menu.item>
                                        @endif
                                        @if ($canManage && $ready && $rowUpcoming)
                                            <flux:menu.item icon="pencil-square" wire:click="openEdit('{{ $row->id }}')">Correct version</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="openWithdraw('{{ $row->id }}')">Withdraw version</flux:menu.item>
                                        @endif
                                    </x-kit.row-actions>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">A version in force never changes. To change a figure, add a new version with the date it takes effect; the version before it stays in force until then. A version that has not taken effect yet can still be corrected or withdrawn.</p>
    </section>

    {{-- The withholding figures are two three-column tables, so that tab gives the figures card the wider column. --}}
    <div class="grid gap-4 xl:grid-cols-3">
        <section class="hris-card min-w-0 p-5 {{ $withholding ? 'sm:col-span-2' : 'xl:col-span-1' }}">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-bold">Figures{{ $shown !== null ? ' — version from '.$shown->effective_from->format('d M Y') : '' }}</h2>
                @if ($shown !== null && $shown->effective_from->format('Y-m-d') > $today)
                    <span class="pill pill-caution">Upcoming</span>
                @elseif ($shown !== null && $inForce !== null && $shown->id === $inForce->id)
                    <span class="pill pill-success">Current</span>
                @elseif ($shown !== null)
                    <span class="pill pill-muted">Superseded</span>
                @endif
                @if ($viewingId !== null && $inForce !== null && $shown?->id !== $inForce->id)
                    <flux:button variant="ghost" size="sm" wire:click="showCurrent">Show current version</flux:button>
                @endif
            </div>
            <p class="mt-2 text-sm text-muted-foreground">
                @if ($shown === null)
                    The engine's built-in figures, until a version is added.
                @else
                    {{ $label }}, in force from <span class="num">{{ $shown->effective_from->format('d M Y') }}</span>
                    @if ($shownNext !== null)
                        until the version from <span class="num">{{ $shownNext->effective_from->format('d M Y') }}</span> starts;
                    @else
                        onward;
                    @endif
                    figures confirmed through <span class="num">{{ $shown->covered_through->format('d M Y') }}</span>.
                @endif
            </p>
            @if ($withholding && $bracketRows !== null)
                @foreach (['semi_monthly' => 'Per cut-off (half a month)', 'annual' => 'Per year'] as $list => $heading)
                    <h3 class="mt-5 text-sm font-semibold">{{ $heading }}</h3>
                    <div class="hris-table-wrap mt-2">
                        <table class="hris-table">
                            <thead><tr><th>Over</th><th class="text-right">Fixed tax</th><th class="text-right">Rate on the excess</th></tr></thead>
                            <tbody>
                                @foreach ($bracketRows[$list] ?? [] as [$over, $base, $rate])
                                    <tr>
                                        <td class="num">{{ \App\Livewire\Records\Support\Hris::peso((float) $over) }}</td>
                                        <td class="num text-right">{{ \App\Livewire\Records\Support\Hris::peso((float) $base) }}</td>
                                        <td class="num text-right">{{ StatutoryTablesPage::percentText($rate) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
                <p class="mt-3 text-xs text-muted-foreground">Nothing is withheld up to the first amount. Above it, the tax is the fixed tax of the highest bracket reached plus the rate on the excess over that bracket's amount.</p>
            @else
                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    @foreach ($figureRows as [$name, $value])
                        <div>
                            <dt class="text-sm text-muted-foreground">{{ $name }}</dt>
                            <dd class="num mt-1 text-lg font-semibold">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </section>

        <section class="hris-card min-w-0 p-5 {{ $withholding ? 'xl:col-span-1' : 'sm:col-span-2' }}">
            <h2 class="text-base font-semibold">Check against sample {{ $withholding ? 'amounts' : 'salaries' }}</h2>
            <p class="mt-1 text-sm text-muted-foreground">What these figures {{ $withholding ? 'withhold' : 'deduct each month' }} at sample amounts.</p>
            <div class="hris-table-wrap mt-4">
                <table class="hris-table">
                    @if ($withholding)
                        <thead><tr><th>Taxable pay</th><th>Table</th><th class="text-right">Tax</th></tr></thead>
                        <tbody>
                            @foreach ($samples as $sample)
                                <tr>
                                    <td class="num">{{ $sample['amount'] }}</td>
                                    <td class="whitespace-nowrap">{{ $sample['list'] === 'annual' ? 'Per year' : 'Per cut-off' }}</td>
                                    <td class="num text-right">{{ $sample['tax'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @else
                        <thead><tr><th>Monthly salary</th><th class="text-right">Employee</th><th class="text-right">Employer</th><th class="text-right">Per half-month, employee</th></tr></thead>
                        <tbody>
                            @foreach ($samples as $sample)
                                <tr>
                                    <td class="num">{{ $sample['amount'] }}</td>
                                    <td class="num text-right">{{ $sample['employee'] }}</td>
                                    <td class="num text-right">{{ $sample['employer'] }}</td>
                                    <td class="num text-right">{{ $sample['half'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endif
                </table>
            </div>
        </section>
    </div>

    {{-- The dialogs exist only for a viewer who may write. --}}
    @if ($canManage)
    <flux:modal
        wire:model.self="dialogOpen"
        class="w-full max-w-lg"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'statutory-version-heading')"
    >
        <div class="space-y-4">
            <div class="space-y-2">
                @if ($editing !== null)
                    <flux:heading size="lg" id="statutory-version-heading">Correct {{ $label }} version from {{ $editing->effective_from->format('d M Y') }}</flux:heading>
                    <flux:text>This version has not taken effect, so its date and figures can still be corrected in place. Draft payrolls reaching it are recalculated and need a fresh review.</flux:text>
                @else
                    <flux:heading size="lg" id="statutory-version-heading">New {{ $label }} version</flux:heading>
                    <flux:text>Applies to payroll periods starting on or after the effective date and stays in force until a later version starts. Pre-filled from the {{ $latest !== null ? 'version from '.$latest->effective_from->format('d M Y') : 'built-in figures' }}.</flux:text>
                @endif
            </div>
            <form class="space-y-4" wire:submit="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <flux:label for="statutory-from">Effective from</flux:label>
                        <x-kit.date-field id="statutory-from" wire:model="formEffectiveFrom" aria-label="Effective from" />
                    </div>
                    <div class="space-y-2">
                        <flux:label for="statutory-through">Confirmed through</flux:label>
                        <x-kit.date-field id="statutory-through" wire:model="formCoveredThrough" aria-label="Confirmed through" />
                    </div>
                </div>
                <div class="space-y-2">
                    <flux:label for="statutory-reference">Reference (optional)</flux:label>
                    <flux:input id="statutory-reference" wire:model="formReference" maxlength="160" placeholder="Where these figures came from" aria-label="Reference" />
                </div>

                @if ($withholding)
                    @foreach (['semi_monthly' => 'Per cut-off (half a month)', 'annual' => 'Per year'] as $list => $heading)
                        <div class="space-y-2">
                            <flux:label>{{ $heading }}</flux:label>
                            <div class="hris-table-wrap">
                                <table class="hris-table">
                                    <thead><tr><th>Over</th><th>Fixed tax</th><th>Rate on the excess (%)</th><th class="w-10"><span class="sr-only">Remove</span></th></tr></thead>
                                    <tbody>
                                        @foreach ($formBrackets[$list] ?? [] as $i => $row)
                                            <tr wire:key="bracket-{{ $list }}-{{ $i }}">
                                                <td><input type="text" inputmode="decimal" wire:model.live.debounce.400ms="formBrackets.{{ $list }}.{{ $i }}.0" aria-label="{{ $heading }} bracket {{ $i + 1 }} over" class="{{ $cell }}" /></td>
                                                <td><input type="text" inputmode="decimal" wire:model.live.debounce.400ms="formBrackets.{{ $list }}.{{ $i }}.1" aria-label="{{ $heading }} bracket {{ $i + 1 }} fixed tax" class="{{ $cell }}" /></td>
                                                <td><input type="text" inputmode="decimal" wire:model.live.debounce.400ms="formBrackets.{{ $list }}.{{ $i }}.2" aria-label="{{ $heading }} bracket {{ $i + 1 }} rate" class="{{ $cell }}" /></td>
                                                <td class="text-right">
                                                    <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeBracket('{{ $list }}', {{ $i }})" :disabled="count($formBrackets[$list] ?? []) <= 1" aria-label="Remove {{ $heading }} bracket {{ $i + 1 }}" />
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <flux:button variant="ghost" size="sm" icon="plus" wire:click="addBracket('{{ $list }}')">Add bracket</flux:button>
                        </div>
                    @endforeach
                @else
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($figureSpecs as [$key, $name, $shape])
                            <div class="space-y-2" wire:key="figure-{{ $key }}">
                                <flux:label for="statutory-figure-{{ $key }}">{{ $name }}</flux:label>
                                @if ($shape === 'percent')
                                    <flux:input id="statutory-figure-{{ $key }}" type="number" min="0" max="100" step="0.0001" class:input="num" wire:model.live.debounce.400ms="formFigures.{{ $key }}" :aria-label="$name" />
                                @else
                                    <x-kit.money-input id="statutory-figure-{{ $key }}" wire:model.live="formFigures.{{ $key }}" :aria-label="$name" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($preview !== null)
                    <div class="rounded-md border border-border bg-muted/40 p-4 text-sm">
                        @if (isset($preview['error']))
                            <p class="text-danger">{{ $preview['error'] }}</p>
                        @elseif ($withholding)
                            <p class="font-medium">Preview at {{ $preview['amount'] }} taxable per cut-off: tax <span class="num">{{ $preview['tax'] }}</span>.</p>
                        @else
                            <p class="font-medium">Preview at {{ $preview['amount'] }} a month: employee <span class="num">{{ $preview['employee'] }}</span>, employer <span class="num">{{ $preview['employer'] }}</span>.</p>
                        @endif
                        <p class="mt-1 text-xs text-muted-foreground">The preview recalculates as you type.</p>
                    </div>
                @endif

                @if ($formError !== null && $dialogOpen)
                    <p class="text-xs text-danger">{{ $formError }}</p>
                @endif

                <p class="text-xs text-muted-foreground">Saving records who {{ $editing !== null ? 'corrected' : 'added' }} the version and when. Draft payrolls on or after the effective date are recalculated and need a fresh review.</p>
                <flux:button type="submit" variant="primary" :icon="$editing !== null ? 'check' : 'plus'">{{ $editing !== null ? 'Save correction' : 'Save new version' }}</flux:button>
            </form>
        </div>
    </flux:modal>

    <flux:modal
        wire:model.self="confirmOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'statutory-confirm-heading')"
    >
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg" id="statutory-confirm-heading">Confirm {{ $label }} figures</flux:heading>
                <flux:text>The version from <span class="num">{{ $confirming?->effective_from->format('d M Y') }}</span> is confirmed through <span class="num">{{ $confirming?->covered_through->format('d M Y') }}</span>. Confirming records that the figures were checked and still hold; payrolls through the new date then run without a reminder. The figures stay the same.</flux:text>
            </div>
            <form class="space-y-4" wire:submit="saveConfirm">
                <div class="space-y-2">
                    <flux:label for="statutory-confirm-through">Confirmed through</flux:label>
                    <x-kit.date-field id="statutory-confirm-through" wire:model="formConfirmThrough" aria-label="Confirmed through" />
                </div>
                @if ($formError !== null && $confirmOpen)
                    <p class="text-xs text-danger">{{ $formError }}</p>
                @endif
                <flux:button type="submit" variant="primary" icon="check">Confirm figures</flux:button>
            </form>
        </div>
    </flux:modal>

    {{-- Withdraw: a two-step delete of a version that has not taken effect; the parameter-less confirm acts on the staged id alone. --}}
    <flux:modal
        wire:model.self="withdrawOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'statutory-withdraw-heading')"
    >
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg" id="statutory-withdraw-heading">Withdraw the {{ $label }} version from {{ $withdrawing?->effective_from->format('d M Y') }}?</flux:heading>
                <flux:text>It has not taken effect, and no payroll has used it. Withdrawing deletes it; the version before it stays in force. Nothing has been changed yet.</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" icon="trash" wire:click="confirmWithdraw">Withdraw version</flux:button>
            </div>
        </div>
    </flux:modal>
    @endif
</div>
