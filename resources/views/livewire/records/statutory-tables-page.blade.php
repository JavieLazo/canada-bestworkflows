{{--
    Contribution & Tax Tables — one tab per table (SSS, PhilHealth, Pag-IBIG,
    Withholding Tax): its figures, what they deduct at sample amounts, and
    the dialogs whoever manages the Payroll Settings gets: the figures edited
    (with a live preview), and one table or all of them confirmed through a
    later date. Nothing here prices a payslip:
    the samples call the engine's own functions on the figures.
--}}
@php
    use App\Domain\Payroll\StatutorySchedule;
    use App\Livewire\Records\StatutoryTablesPage;
    use App\Modules\Payroll\Application\StatutoryTables;

    $tabClass = static fn (bool $on): string => 'inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all '.($on ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground');
    $cell = 'num h-8 w-full min-w-[6rem] rounded-md border border-border bg-card px-2.5 text-right text-sm focus:outline-none focus:ring-2 focus:ring-ring';
    $withholding = $tab === StatutorySchedule::WITHHOLDING_TAX;
@endphp
<div class="space-y-6">
    @include('livewire.payroll.partials.settings-header', [
        'activeView' => 'tables',
        'title' => 'Contribution & Tax Tables',
        'description' => 'The SSS, PhilHealth, Pag-IBIG and withholding tax figures payroll computes from.',
    ])

    {{-- Only what needs reading: the card under it already says how far each table is confirmed. --}}
    @if (! $ready || $confirmedThrough === null || $confirmationLapsed || ! $canManage)
        <x-payroll.notice tone="note" class="rounded-lg border px-4 py-3">
            @if (! $ready)
                <p>Payroll uses the built-in figures until this installation's upgrade finishes; the tables can be confirmed and edited then.</p>
            @elseif ($confirmedThrough === null)
                <p>Payroll cannot run until every table has figures.</p>
            @elseif ($confirmationLapsed)
                <p><strong class="text-foreground">The {{ implode(', ', $confirmationEndsWith) }} {{ count($confirmationEndsWith) === 1 ? 'table was' : 'tables were' }} last confirmed through {{ StatutoryTables::day($confirmedThrough) }}.</strong> Later payrolls still run, with a reminder to check the figures.</p>
            @endif
            @unless ($canManage)
                <p>Your role can view these tables. Changing or confirming the figures requires Payroll access to all data.</p>
            @endunless
        </x-payroll.notice>
    @endif

    {{-- ONE card for the four tables. Its head holds the table tabs and Confirm all tables, so the tabs read as
         this card's own and not as a second row of the page switch above; its body is the chosen table's figures
         beside their check. The withholding figures are two three-column tables, so that tab gives the figures
         the wider side. --}}
    <section class="hris-card overflow-hidden" data-statutory-tables>
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
            <div class="inline-flex h-auto flex-wrap items-center justify-start rounded-lg bg-muted p-1 text-muted-foreground" role="tablist" aria-label="Tables">
                @foreach ($tabs as $kind => $name)
                    <button type="button" role="tab" aria-selected="{{ $tab === $kind ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $kind }}')" class="{{ $tabClass($tab === $kind) }}">
                        {{ $name }}
                    </button>
                @endforeach
            </div>
            @if ($canManage && $ready)
                <flux:button variant="outline" size="sm" icon="calendar" wire:click="openConfirmAll">Confirm All Tables</flux:button>
            @endif
        </header>

        <div class="grid xl:grid-cols-6">
            <section class="min-w-0 p-5 {{ $withholding ? 'xl:col-span-4' : 'xl:col-span-3' }}">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-bold">{{ $label }} Figures</h2>
                    @if ($canManage && $ready)
                        <div class="ml-auto flex flex-wrap items-center gap-2">
                            @if ($shown !== null)
                                <flux:button variant="outline" size="sm" icon="calendar" wire:click="openConfirm('{{ $shown->id }}')">Confirm Figures</flux:button>
                            @endif
                            <flux:button variant="primary" size="sm" icon="pencil" wire:click="openAdd">Edit Figures</flux:button>
                        </div>
                    @endif
                </div>
                <p class="mt-2 text-sm text-muted-foreground">
                    @if ($shown === null)
                        The built-in figures, until they are edited.
                    @else
                        Confirmed through <span class="num">{{ $shown->covered_through->format('d M Y') }}</span>: the figures were checked to hold until then. Later payrolls show a reminder.
                    @endif
                </p>
                @if ($withholding && $bracketRows !== null)
                    @foreach (['semi_monthly' => 'Semi-monthly', 'annual' => 'Annual'] as $list => $heading)
                        <h3 class="mt-5 text-sm font-semibold">{{ $heading }}</h3>
                        <div class="hris-table-wrap mt-2">
                            <table class="hris-table">
                                <thead><tr><th>Over</th><th class="text-right">Fixed Tax</th><th class="text-right">Rate on the Excess</th></tr></thead>
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

            <section class="min-w-0 border-t border-border p-5 xl:border-l xl:border-t-0 {{ $withholding ? 'xl:col-span-2' : 'xl:col-span-3' }}">
                <h2 class="text-base font-semibold">Check Against Sample {{ $withholding ? 'Amounts' : 'Salaries' }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">What these figures {{ $withholding ? 'withhold' : 'deduct each month' }} at sample amounts.</p>
                <div class="hris-table-wrap mt-4">
                    <table class="hris-table">
                        @if ($withholding)
                            <thead><tr><th>Taxable Compensation</th><th>Table</th><th class="text-right">Tax</th></tr></thead>
                            <tbody>
                                @foreach ($samples as $sample)
                                    <tr>
                                        <td class="num">{{ $sample['amount'] }}</td>
                                        <td class="whitespace-nowrap">{{ $sample['list'] === 'annual' ? 'Annual' : 'Semi-monthly' }}</td>
                                        <td class="num text-right">{{ $sample['tax'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @else
                            <thead><tr><th>Monthly Salary</th><th class="text-right">Employee</th><th class="text-right">Employer</th><th class="text-right">Per Half-Month, Employee</th></tr></thead>
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
    </section>

    {{-- The dialogs exist only for a viewer who may write. The long one keeps its footer in view (the leave rule dialog's pattern: bg-inherit down the chain from the <dialog>). --}}
    @if ($canManage)
    <flux:modal
        wire:model.self="dialogOpen"
        class="w-full {{ $withholding ? 'max-w-5xl' : 'max-w-2xl' }} scroll-pb-32"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'statutory-version-heading')"
    >
        <form class="space-y-4 bg-inherit" wire:submit="save">
            <div class="space-y-2">
                <flux:heading size="lg" id="statutory-version-heading">Edit {{ $label }} Figures</flux:heading>
                @include('livewire.payroll.partials.settings-applies-note', ['class' => 'text-sm text-muted-foreground'])
            </div>
            <div class="space-y-2">
                <flux:label for="statutory-reference">Reference (optional)</flux:label>
                <flux:input id="statutory-reference" wire:model="formReference" maxlength="160" placeholder="Where these figures came from" aria-label="Reference" />
            </div>

            @if ($withholding)
                <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
                    @foreach (['semi_monthly' => 'Semi-monthly', 'annual' => 'Annual'] as $list => $heading)
                        <div class="min-w-0 space-y-2">
                            <flux:label>{{ $heading }}</flux:label>
                            <div class="hris-table-wrap">
                                <table class="hris-table">
                                    <thead><tr><th>Over</th><th>Fixed Tax</th><th>Rate on the Excess (%)</th><th class="w-10"><span class="sr-only">Remove</span></th></tr></thead>
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
                            <flux:button variant="ghost" size="sm" icon="plus" wire:click="addBracket('{{ $list }}')">Add Bracket</flux:button>
                        </div>
                    @endforeach
                </div>
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
                        <p class="font-medium">Preview at {{ $preview['amount'] }} taxable compensation, semi-monthly: tax <span class="num">{{ $preview['tax'] }}</span>.</p>
                    @else
                        <p class="font-medium">Preview at {{ $preview['amount'] }} a month: employee <span class="num">{{ $preview['employee'] }}</span>, employer <span class="num">{{ $preview['employer'] }}</span>.</p>
                    @endif
                </div>
            @endif

            <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-col gap-2 border-t border-border bg-inherit px-6 py-3">
                @if ($formError !== null && $dialogOpen)
                    <p role="alert" class="text-xs text-danger">{{ $formError }}</p>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="outline" wire:click="$set('dialogOpen', false)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" icon="check">Save Figures</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        wire:model.self="confirmOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'statutory-confirm-heading')"
    >
        <form class="space-y-4" wire:submit="saveConfirm">
            <div class="space-y-2">
                <flux:heading size="lg" id="statutory-confirm-heading">Confirm {{ $label }} Figures</flux:heading>
                <flux:text>Records that the figures were checked and still hold. They don't change. Payrolls past the confirmed date show a reminder.</flux:text>
                <flux:text>Now confirmed through <span class="num">{{ $confirming?->covered_through->format('d M Y') }}</span>.</flux:text>
            </div>
            <div class="space-y-2">
                <flux:label for="statutory-confirm-through">Confirmed Through</flux:label>
                <x-kit.date-field id="statutory-confirm-through" wire:model="formConfirmThrough" aria-label="Confirmed through" />
            </div>
            @if ($formError !== null && $confirmOpen)
                <p role="alert" class="text-xs text-danger">{{ $formError }}</p>
            @endif
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="outline" wire:click="$set('confirmOpen', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="check">Confirm Figures</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        wire:model.self="confirmAllOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'statutory-confirm-all-heading')"
    >
        <form class="space-y-4" wire:submit="saveConfirmAll" data-confirm-all>
            <div class="space-y-2">
                <flux:heading size="lg" id="statutory-confirm-all-heading">Confirm All Tables</flux:heading>
                <flux:text>Records that the SSS, PhilHealth, Pag-IBIG and Withholding Tax figures were checked and still hold. They don't change. Payrolls past the confirmed date show a reminder.</flux:text>
            </div>
            <div class="space-y-2">
                <flux:label for="statutory-confirm-all-through">Confirmed Through</flux:label>
                <x-kit.date-field id="statutory-confirm-all-through" wire:model="formConfirmThrough" aria-label="Confirmed through" />
            </div>
            @if ($formError !== null && $confirmAllOpen)
                <p role="alert" class="text-xs text-danger">{{ $formError }}</p>
            @endif
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="outline" wire:click="$set('confirmAllOpen', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="check">Confirm All</flux:button>
            </div>
        </form>
    </flux:modal>

    @endif
</div>
