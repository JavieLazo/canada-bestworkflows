{{--
    Contribution & Tax Tables — the Canada Pension Plan, Employment Insurance
    and income tax figures, READ-ONLY: they are set by law and ship with the
    system, so the page has no edit control and no "not set" state. One set
    of figures shows at a time (the one in use, or one that starts later);
    the four cards at the top pick between the contributions and the income
    tax, and each table says what it deducts from a sample pay the reader
    types. The samples call the calculator payroll itself uses.
--}}
@php
    use App\Livewire\Records\ContributionTablesPage;
    use App\Livewire\Records\Support\DeductionTablesText as T;
    use App\Support\Currency;

    $pension = $shown->pension;
    $insurance = $shown->insurance;
    $federal = $shown->federal;
    $contributions = $tab === ContributionTablesPage::TAB_CONTRIBUTIONS;
    $every = strtolower($sample['every']);
    $tile = static fn (bool $on): string => 'block w-full rounded-lg bg-card p-4 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary '.($on ? 'border-2 border-primary' : 'border border-border hover:border-primary/40');
    $term = 'text-xs font-medium uppercase tracking-wide text-muted-foreground';
    $figure = 'mt-1 text-base font-bold';
    $help = 'mt-0.5 text-sm text-muted-foreground';
    $switch = static fn (bool $on): string => 'inline-flex items-center gap-2 whitespace-nowrap rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary '.($on ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground');
@endphp
<div class="space-y-6" data-contribution-tables>
    @include('livewire.payroll.partials.settings-header', [
        'activeView' => 'tables',
        'title' => 'Contribution & Tax Tables',
        'description' => 'The Canada Pension Plan, Employment Insurance and income tax figures that payroll deducts from each employee.',
    ])

    <x-payroll.notice tone="info" class="rounded-lg border px-4 py-3" data-read-only-notice>
        <p><strong class="text-foreground">These figures are set by law and cannot be changed here.</strong> They come from the Canada Revenue Agency. When the government publishes new figures, they are added in a system update before the day they start.</p>
    </x-payroll.notice>

    <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="font-semibold">Figures For</span>
        <div class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg bg-muted p-1 text-muted-foreground" role="group" aria-label="Year">
            @foreach ($sets as $set)
                @php $isShown = $set->effectiveFrom === $shown->effectiveFrom; @endphp
                <button
                    type="button"
                    wire:click="$set('from', '{{ $set->effectiveFrom === $inUse->effectiveFrom ? '' : $set->effectiveFrom }}')"
                    aria-pressed="{{ $isShown ? 'true' : 'false' }}"
                    class="{{ $switch($isShown) }}"
                    data-figures-set="{{ $set->effectiveFrom }}"
                >
                    {{ T::year($set) }}
                    @if ($set->effectiveFrom === $inUse->effectiveFrom)
                        <span class="pill pill-success">In Use</span>
                    @elseif ($set->effectiveFrom > $inUse->effectiveFrom)
                        <span class="pill pill-info">Starts {{ T::startsOn($set) }}</span>
                    @else
                        <span class="pill pill-muted">Past</span>
                    @endif
                </button>
            @endforeach
        </div>
        <span class="text-muted-foreground">A payroll uses the figures in force on its pay date.</span>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <button type="button" wire:click="$set('tab', '{{ ContributionTablesPage::TAB_CONTRIBUTIONS }}')" aria-pressed="{{ $contributions ? 'true' : 'false' }}" class="{{ $tile($contributions) }}" data-table-tile="pension">
            <span class="block text-base font-bold">Canada Pension Plan</span>
            <span class="mt-2 block text-sm">Employee {{ T::percent($pension['rate']) }} · Employer {{ T::percent($pension['rate']) }}</span>
            <span class="mt-0.5 block text-sm text-muted-foreground">Up to <span class="num">{{ Currency::format($pension['max_contribution']) }}</span> a year</span>
        </button>
        <button type="button" wire:click="$set('tab', '{{ ContributionTablesPage::TAB_CONTRIBUTIONS }}')" aria-pressed="{{ $contributions ? 'true' : 'false' }}" class="{{ $tile($contributions) }}" data-table-tile="insurance">
            <span class="block text-base font-bold">Employment Insurance</span>
            <span class="mt-2 block text-sm">Employee {{ T::percent($insurance['rate']) }} · Employer {{ T::percent($insurance['rate'] * $insurance['employer_multiple']) }}</span>
            <span class="mt-0.5 block text-sm text-muted-foreground">Up to <span class="num">{{ Currency::format($insurance['max_premium']) }}</span> a year</span>
        </button>
        <button type="button" wire:click="$set('tab', '{{ ContributionTablesPage::TAB_TAX }}')" aria-pressed="{{ $contributions ? 'false' : 'true' }}" class="{{ $tile(! $contributions) }}" data-table-tile="federal">
            <span class="block text-base font-bold">Federal Income Tax</span>
            <span class="mt-2 block text-sm">{{ T::bracketSummary($federal['brackets']) }}</span>
            <span class="mt-0.5 block text-sm text-muted-foreground">Basic amount <span class="num">{{ Currency::format($federal['basic_amount']['max']) }}</span></span>
        </button>
        <button type="button" wire:click="$set('tab', '{{ ContributionTablesPage::TAB_TAX }}')" aria-pressed="{{ $contributions ? 'false' : 'true' }}" class="{{ $tile(! $contributions) }}" data-table-tile="provincial">
            <span class="block text-base font-bold">{{ $provinceName }} Income Tax</span>
            <span class="mt-2 block text-sm">{{ T::bracketSummary($provincial['brackets']) }}</span>
            <span class="mt-0.5 block text-sm text-muted-foreground">Basic amount <span class="num">{{ Currency::format($provincial['basic_amount']) }}</span></span>
        </button>
    </div>

    @if ($contributions)
        <section class="hris-card overflow-hidden" aria-labelledby="pension-heading" data-pension-figures>
            <x-payroll.settings.head title="Canada Pension Plan (CPP) Figures" heading-id="pension-heading" description="What is deducted for CPP from each pay. The employer pays the same amount again.">
                <span class="pill pill-muted"><flux:icon.lock-closed variant="micro" class="size-3.5" />Read-Only</span>
            </x-payroll.settings.head>
            <div class="grid xl:grid-cols-5">
                <dl class="grid gap-5 p-5 sm:grid-cols-2 xl:col-span-3 xl:grid-cols-3">
                    <div>
                        <dt class="{{ $term }}">Employee Pays</dt>
                        <dd class="{{ $figure }}">{{ T::percent($pension['rate']) }}</dd>
                        <dd class="{{ $help }}">Of the pay counted. Deducted from pay.</dd>
                    </div>
                    <div>
                        <dt class="{{ $term }}">Employer Pays</dt>
                        <dd class="{{ $figure }}">{{ T::percent($pension['rate']) }}</dd>
                        <dd class="{{ $help }}">The same amount as the employee. Not deducted from pay.</dd>
                    </div>
                    <div>
                        <dt class="{{ $term }}">Pay Not Counted</dt>
                        <dd class="{{ $figure }}"><span class="num">{{ Currency::format($pension['exemption']) }}</span> a year</dd>
                        <dd class="{{ $help }}"><span class="num">{{ Currency::format($sample['exemption']) }}</span> of each {{ $every }} pay is left out before the rate is applied.</dd>
                    </div>
                    <div>
                        <dt class="{{ $term }}">Highest Earnings Counted</dt>
                        <dd class="{{ $figure }} num">{{ Currency::format($pension['ceiling']) }}</dd>
                        <dd class="{{ $help }}">In one year. Earnings above this are not counted here.</dd>
                    </div>
                    <div>
                        <dt class="{{ $term }}">Most an Employee Pays in a Year</dt>
                        <dd class="{{ $figure }} num">{{ Currency::format($pension['max_contribution']) }}</dd>
                        <dd class="{{ $help }}">When an employee reaches this, CPP stops until the next year.</dd>
                    </div>
                    <div>
                        <dt class="{{ $term }}">Second CPP (CPP2)</dt>
                        <dd class="{{ $figure }}">{{ T::percent($pension['second_rate']) }}</dd>
                        <dd class="{{ $help }}">Only on earnings between <span class="num">{{ Currency::format($pension['ceiling']) }}</span> and <span class="num">{{ Currency::format($pension['second_ceiling']) }}</span> in a year. At most <span class="num">{{ Currency::format($pension['max_second_contribution']) }}</span>.</dd>
                    </div>
                </dl>
                <section class="border-t border-border bg-muted/40 p-5 xl:col-span-2 xl:border-l xl:border-t-0" aria-labelledby="pension-sample-heading" data-pension-sample>
                    <h3 id="pension-sample-heading" class="text-sm font-bold">Try a Sample Pay</h3>
                    <p class="mt-1 text-sm text-muted-foreground">Type a {{ $every }} pay to see what these figures deduct.</p>
                    <flux:field class="mt-3">
                        <flux:label for="sample-pay-pension">{{ $sample['every'] }} Pay</flux:label>
                        <x-kit.money-input id="sample-pay-pension" wire:model.live.debounce.400ms="samplePay" aria-label="{{ $sample['every'] }} pay" />
                    </flux:field>
                    <dl class="mt-4 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
                        <dt class="text-muted-foreground">Pay counted</dt>
                        <dd class="num text-right">{{ Currency::format($sample['counted']) }}</dd>
                        <dt class="text-muted-foreground">Employee pays this pay</dt>
                        <dd class="num text-right font-bold" data-sample-pension>{{ Currency::format($sample['pension']) }}</dd>
                        <dt class="text-muted-foreground">Employer pays this pay</dt>
                        <dd class="num text-right">{{ Currency::format($sample['employer_pension']) }}</dd>
                    </dl>
                </section>
            </div>
            @if ($next !== null)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border px-5 py-3 text-sm" data-next-change>
                    <span class="pill pill-info">From {{ T::startsOn($next) }}</span>
                    <p class="min-w-0 flex-1 basis-80 text-muted-foreground">The rate becomes {{ T::percent($next->pension['rate']) }} for the employee and for the employer, on earnings up to <span class="num">{{ Currency::format($next->pension['ceiling']) }}</span>. The most an employee pays in the year becomes <span class="num">{{ Currency::format($next->pension['max_contribution']) }}</span>. Second CPP covers earnings up to <span class="num">{{ Currency::format($next->pension['second_ceiling']) }}</span>, at most <span class="num">{{ Currency::format($next->pension['max_second_contribution']) }}</span>.</p>
                </div>
            @endif
        </section>

        <section class="hris-card overflow-hidden" aria-labelledby="insurance-heading" data-insurance-figures>
            <x-payroll.settings.head title="Employment Insurance (EI) Figures" heading-id="insurance-heading" description="What is deducted for EI from each pay. The employer pays {{ rtrim(rtrim(number_format($insurance['employer_multiple'], 2), '0'), '.') }} times the employee's amount.">
                <span class="pill pill-muted"><flux:icon.lock-closed variant="micro" class="size-3.5" />Read-Only</span>
            </x-payroll.settings.head>
            <div class="grid xl:grid-cols-5">
                <dl class="grid gap-5 p-5 sm:grid-cols-2 xl:col-span-3">
                    <div>
                        <dt class="{{ $term }}">Employee Pays</dt>
                        <dd class="{{ $figure }}">{{ T::percent($insurance['rate']) }}</dd>
                        <dd class="{{ $help }}">Of the pay for the period. Deducted from pay.</dd>
                    </div>
                    <div>
                        <dt class="{{ $term }}">Employer Pays</dt>
                        <dd class="{{ $figure }}">{{ T::percent($insurance['rate'] * $insurance['employer_multiple']) }}</dd>
                        <dd class="{{ $help }}">{{ rtrim(rtrim(number_format($insurance['employer_multiple'], 2), '0'), '.') }} times what the employee pays. Not deducted from pay.</dd>
                    </div>
                    <div>
                        <dt class="{{ $term }}">Highest Earnings Counted</dt>
                        <dd class="{{ $figure }} num">{{ Currency::format($insurance['max_insurable']) }}</dd>
                        <dd class="{{ $help }}">In one year. Earnings above this are not counted.</dd>
                    </div>
                    <div>
                        <dt class="{{ $term }}">Most an Employee Pays in a Year</dt>
                        <dd class="{{ $figure }} num">{{ Currency::format($insurance['max_premium']) }}</dd>
                        <dd class="{{ $help }}">When an employee reaches this, EI stops until the next year.</dd>
                    </div>
                </dl>
                <section class="border-t border-border bg-muted/40 p-5 xl:col-span-2 xl:border-l xl:border-t-0" aria-labelledby="insurance-sample-heading" data-insurance-sample>
                    <h3 id="insurance-sample-heading" class="text-sm font-bold">On a {{ $sample['every'] }} Pay of <span class="num">{{ Currency::format($sample['pay']) }}</span></h3>
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
                        <dt class="text-muted-foreground">Employee pays this pay</dt>
                        <dd class="num text-right font-bold" data-sample-insurance>{{ Currency::format($sample['insurance']) }}</dd>
                        <dt class="text-muted-foreground">Employer pays this pay</dt>
                        <dd class="num text-right">{{ Currency::format($sample['employer_insurance']) }}</dd>
                    </dl>
                </section>
            </div>
            @if ($next !== null)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border px-5 py-3 text-sm">
                    <span class="pill pill-info">From {{ T::startsOn($next) }}</span>
                    <p class="min-w-0 flex-1 basis-80 text-muted-foreground">The employee rate becomes {{ T::percent($next->insurance['rate']) }}, on earnings up to <span class="num">{{ Currency::format($next->insurance['max_insurable']) }}</span>. The most an employee pays in the year becomes <span class="num">{{ Currency::format($next->insurance['max_premium']) }}</span>.</p>
                </div>
            @endif
        </section>
    @else
        <section class="hris-card grid overflow-hidden xl:grid-cols-5" aria-labelledby="tax-how-heading" data-tax-how>
            <div class="p-5 xl:col-span-3">
                <h2 id="tax-how-heading" class="text-base font-bold">How Income Tax Is Worked Out</h2>
                <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm">
                    <li>The system turns the pay for the period into a yearly amount. A {{ $every }} pay is multiplied by {{ $sample['pays'] }}.</li>
                    <li>It applies the federal tax brackets and the {{ $provinceName }} tax brackets to that yearly amount.</li>
                    <li>It takes off the amounts that are not taxed: the employee's claim amounts, and credit for the CPP and EI they pay.</li>
                    <li>It divides the yearly tax by {{ $sample['pays'] }} to get the tax for this pay.</li>
                </ol>
            </div>
            <section class="border-t border-border bg-muted/40 p-5 xl:col-span-2 xl:border-l xl:border-t-0" aria-labelledby="tax-sample-heading" data-tax-sample>
                <h3 id="tax-sample-heading" class="text-sm font-bold">Try a Sample Pay</h3>
                <p class="mt-1 text-sm text-muted-foreground">For an employee who claims only the basic amounts.</p>
                <flux:field class="mt-3">
                    <flux:label for="sample-pay-tax">{{ $sample['every'] }} Pay</flux:label>
                    <x-kit.money-input id="sample-pay-tax" wire:model.live.debounce.400ms="samplePay" aria-label="{{ $sample['every'] }} pay" />
                </flux:field>
                <dl class="mt-4 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm">
                    <dt class="text-muted-foreground">Pay for the year</dt>
                    <dd class="num text-right">{{ Currency::format($sample['yearly']) }}</dd>
                    <dt class="text-muted-foreground">Federal tax this pay</dt>
                    <dd class="num text-right font-bold" data-sample-federal>{{ Currency::format($sample['federal']) }}</dd>
                    <dt class="text-muted-foreground">{{ $provinceName }} tax this pay</dt>
                    <dd class="num text-right font-bold" data-sample-provincial>{{ Currency::format($sample['provincial']) }}</dd>
                </dl>
            </section>
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-2">
            <section class="hris-card overflow-hidden" aria-labelledby="federal-heading" data-federal-figures>
                <x-payroll.settings.head title="Federal Income Tax, {{ T::year($shown) }}" heading-id="federal-heading" description="The same in every province." flush>
                    <span class="pill pill-muted"><flux:icon.lock-closed variant="micro" class="size-3.5" />Read-Only</span>
                </x-payroll.settings.head>
                <div class="hris-table-wrap">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th scope="col">Taxable Income for the Year</th>
                                <th scope="col" class="!text-right">Tax Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (T::brackets($federal['brackets']) as $row)
                                <tr>
                                    <td class="num">{{ $row['range'] }}</td>
                                    <td class="num text-right font-bold">{{ $row['rate'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <dl class="grid gap-5 border-t border-border p-5 sm:grid-cols-2">
                    <div>
                        <dt class="{{ $term }}">Basic Personal Amount</dt>
                        <dd class="{{ $figure }} num">{{ Currency::format($federal['basic_amount']['max']) }}</dd>
                        <dd class="{{ $help }}">Income up to this amount is not taxed. It is smaller for yearly income above <span class="num">{{ T::dollars($federal['basic_amount']['reduce_from']) }}</span>, down to <span class="num">{{ Currency::format($federal['basic_amount']['min']) }}</span>.</dd>
                    </div>
                    <div>
                        <dt class="{{ $term }}">Canada Employment Amount</dt>
                        <dd class="{{ $figure }} num">{{ Currency::format($federal['employment_amount']) }}</dd>
                        <dd class="{{ $help }}">An extra amount that is not taxed, for every employee.</dd>
                    </div>
                </dl>
                @if ($next !== null)
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border px-5 py-3 text-sm">
                        <span class="pill pill-info">From {{ T::startsOn($next) }}</span>
                        <p class="min-w-0 flex-1 basis-64 text-muted-foreground">The brackets start at <span class="num">{{ T::thresholds($next->federal['brackets']) }}</span>. The basic personal amount becomes <span class="num">{{ Currency::format($next->federal['basic_amount']['max']) }}</span>.</p>
                    </div>
                @endif
            </section>

            <section class="hris-card overflow-hidden" aria-labelledby="provincial-heading" data-provincial-figures>
                <x-payroll.settings.head title="{{ $provinceName }} Income Tax, {{ T::year($shown) }}" heading-id="provincial-heading" description="For employees whose province of employment is {{ $provinceName }}." flush>
                    <span class="pill pill-muted"><flux:icon.lock-closed variant="micro" class="size-3.5" />Read-Only</span>
                </x-payroll.settings.head>
                <div class="hris-table-wrap">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th scope="col">Taxable Income for the Year</th>
                                <th scope="col" class="!text-right">Tax Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (T::brackets($provincial['brackets']) as $row)
                                <tr>
                                    <td class="num">{{ $row['range'] }}</td>
                                    <td class="num text-right font-bold">{{ $row['rate'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <dl class="grid gap-5 border-t border-border p-5 sm:grid-cols-2">
                    <div>
                        <dt class="{{ $term }}">Basic Personal Amount</dt>
                        <dd class="{{ $figure }} num">{{ Currency::format($provincial['basic_amount']) }}</dd>
                        <dd class="{{ $help }}">Income up to this amount is not taxed by {{ $provinceName }}.</dd>
                    </div>
                </dl>
                @if ($next !== null && $next->covers($province))
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border px-5 py-3 text-sm">
                        <span class="pill pill-info">From {{ T::startsOn($next) }}</span>
                        <p class="min-w-0 flex-1 basis-64 text-muted-foreground">The brackets start at <span class="num">{{ T::thresholds($next->province($province)['brackets']) }}</span>. The basic personal amount becomes <span class="num">{{ Currency::format($next->province($province)['basic_amount']) }}</span>.</p>
                    </div>
                @endif
            </section>
        </div>

        <p class="text-sm text-muted-foreground">Only {{ $provinceName }} is set up for now. Other provinces can be added later as more tables of the same kind.</p>
    @endif
</div>
