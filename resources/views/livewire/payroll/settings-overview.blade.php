{{--
    Payroll → Payroll Settings: what payroll uses today, and where to change
    it. Needs attention first (each item links to where it is fixed), then
    the four company-wide settings, then the employees paid differently.
    Read-only; the pay data parts show only to an all-data PAYROLL holder.
--}}
@php
    use App\Livewire\Payroll\SettingsOverview;
    use App\Livewire\Records\Support\EmployeeRates;
    use App\Modules\Payroll\Application\PayrollPeriodText;

    $dt = 'text-xs font-medium uppercase tracking-wide text-muted-foreground';
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Payroll Settings</h1>
            <p class="mt-1 text-sm text-muted-foreground">What payroll uses today, and where to change it.</p>
        </div>
        @if ($payData)
            <div class="w-full max-w-xs">
                <livewire:kit.employee-picker
                    name="settings-employee"
                    placeholder="Find an employee's pay settings"
                    aria-label="Find an employee's pay settings"
                    wire:key="settings-employee-picker"
                />
            </div>
        @endif
    </div>

    @include('livewire.payroll.partials.settings-switch', ['activeView' => 'overview'])

    <section class="hris-card p-5" aria-labelledby="attention-heading" data-needs-attention>
        <h2 id="attention-heading" class="text-base font-bold">Needs attention</h2>
        @if ($attention === [])
            <p class="mt-2 text-sm text-muted-foreground">Nothing needs attention. The tables cover the coming payrolls and nothing is about to change.</p>
        @else
            <ul class="mt-2 divide-y divide-border">
                @foreach ($attention as $item)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="attention-{{ $loop->index }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ $item['title'] }}</p>
                            <p class="text-xs text-muted-foreground">{{ $item['detail'] }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="pill {{ $item['tier'] }}">{{ $item['pill'] }}</span>
                            <flux:link :href="$item['href']" wire:navigate class="text-sm font-medium">{{ $item['link'] }}</flux:link>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="hris-card overflow-hidden" aria-labelledby="tables-heading">
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 id="tables-heading" class="text-base font-bold">Contribution &amp; Tax Tables</h2>
                    <p class="mt-1 text-sm text-muted-foreground">The version each deduction is computed from today.</p>
                </div>
                <flux:link :href="route('records.contribution-tables')" wire:navigate class="text-sm font-medium">Open</flux:link>
            </header>
            @unless ($tablesReady)
                <p class="px-5 py-4 text-sm text-muted-foreground">The tables can be edited once this installation's upgrade finishes; payroll uses the built-in figures until then.</p>
            @else
                <div class="hris-table-wrap">
                    <table class="hris-table">
                        <thead><tr><th>Table</th><th>In force from</th><th>Confirmed through</th><th>Status</th></tr></thead>
                        <tbody>
                            @foreach ($tables as $table)
                                <tr wire:key="table-{{ $table['kind'] }}">
                                    <td class="font-medium">{{ $table['label'] }}</td>
                                    <td class="num whitespace-nowrap">{{ $table['current'] ? PayrollPeriodText::day($table['current']->effective_from->format('Y-m-d')) : '—' }}</td>
                                    <td class="num whitespace-nowrap">{{ $table['through'] ? PayrollPeriodText::day($table['through']->format('Y-m-d')) : '—' }}</td>
                                    <td>
                                        @if ($table['daysLeft'] === null)
                                            <span class="pill pill-danger">No version</span>
                                        @elseif ($table['daysLeft'] < 0)
                                            <span class="pill pill-caution">Check due</span>
                                        @elseif ($table['daysLeft'] <= SettingsOverview::TABLE_WARNING_DAYS)
                                            <span class="pill pill-caution">{{ $table['daysLeft'] === 1 ? '1 day left' : $table['daysLeft'].' days left' }}</span>
                                        @else
                                            <span class="pill pill-success">Confirmed</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endunless
        </section>

        <section class="hris-card p-5" aria-labelledby="rates-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="rates-heading" class="text-base font-bold">Pay Basis &amp; Rates</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Who is priced by which rules today.</p>
                </div>
                <flux:link :href="route('records.pay-rates')" wire:navigate class="text-sm font-medium">Open</flux:link>
            </div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Company rule</dt>
                    <dd class="mt-1 text-sm">
                        {{ EmployeeRates::summary($rates['company']?->params) }}
                        @if ($rates['company'] !== null)
                            <span class="block text-xs text-muted-foreground">Since <span class="num">{{ PayrollPeriodText::day($rates['company']->effective_from->format('Y-m-d')) }}</span></span>
                        @endif
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Employment status overrides</dt>
                    <dd class="mt-1 text-sm">
                        @forelse ($rates['statuses'] as $status)
                            <span class="block">{{ $status['name'] }}
                                @if ($status['current'] !== null)
                                    <span class="text-xs text-muted-foreground">· since <span class="num">{{ PayrollPeriodText::day($status['current']->effective_from->format('Y-m-d')) }}</span></span>
                                @else
                                    <span class="text-xs text-muted-foreground">· from <span class="num">{{ PayrollPeriodText::day($status['next']?->effective_from->format('Y-m-d') ?? '') }}</span></span>
                                @endif
                            </span>
                        @empty
                            <span class="text-muted-foreground">None: every status follows the company rule</span>
                        @endforelse
                    </dd>
                </div>
                @if ($rates['own'] !== null)
                    <div class="min-w-0">
                        <dt class="{{ $dt }}">Employees with rates of their own</dt>
                        <dd class="mt-1 text-sm">{{ $rates['own'] === 0 ? 'None' : $rates['own'] }}</dd>
                    </div>
                @endif
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Next change</dt>
                    <dd class="mt-1 text-sm">
                        @forelse ($rates['changes'] as $change)
                            <span class="block">{{ $change['what'] }} <span class="text-xs text-muted-foreground">· from <span class="num">{{ PayrollPeriodText::day($change['from']->format('Y-m-d')) }}</span></span></span>
                        @empty
                            <span class="text-muted-foreground">None scheduled</span>
                        @endforelse
                    </dd>
                </div>
            </dl>
        </section>

        <section class="hris-card p-5" aria-labelledby="deductions-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="deductions-heading" class="text-base font-bold">Payroll Deductions</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Every deduction column on the payroll, by how its amount is found.</p>
                </div>
                <flux:link :href="route('payroll.settings.deductions')" wire:navigate class="text-sm font-medium">Open</flux:link>
            </div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Worked out by payroll</dt>
                    <dd class="mt-1 text-sm">{{ $deductions['computed'] === [] ? 'None' : implode(', ', $deductions['computed']) }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Percentage of pay</dt>
                    <dd class="mt-1 text-sm">
                        @forelse ($deductions['percent'] as $row)
                            <span class="block">{{ $row['name'] }} <span class="block text-xs text-muted-foreground">{{ $row['detail'] }}</span></span>
                        @empty
                            <span class="text-muted-foreground">None</span>
                        @endforelse
                    </dd>
                </div>
                <div class="min-w-0 sm:col-span-2">
                    <dt class="{{ $dt }}">Loans and other amounts</dt>
                    <dd class="mt-1 text-sm">
                        {{ $deductions['typed'] === [] ? 'None' : implode(', ', array_column($deductions['typed'], 'name')) }}
                        <span class="block text-xs text-muted-foreground">
                            Set per employee as a recurring line, or typed on the payroll
                            @if ($payData)
                                · {{ $deductions['running'] === 1 ? '1 recurring line is running' : $deductions['running'].' recurring lines are running' }}
                            @endif
                        </span>
                    </dd>
                </div>
            </dl>
        </section>

        <section class="hris-card p-5" aria-labelledby="benefits-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="benefits-heading" class="text-base font-bold">Incentives &amp; Bonuses</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Benefits payroll proposes on its own, and those typed per payroll.</p>
                </div>
                <flux:link :href="route('records.incentive-types')" wire:navigate class="text-sm font-medium">Open</flux:link>
            </div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Proposed by payroll</dt>
                    <dd class="mt-1 text-sm">
                        @forelse ($benefits['scheduled'] as $row)
                            <span class="block">{{ $row['name'] }} <span class="block text-xs text-muted-foreground">{{ $row['detail'] }}</span></span>
                        @empty
                            <span class="text-muted-foreground">None</span>
                        @endforelse
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="{{ $dt }}">Typed or set per employee</dt>
                    <dd class="mt-1 text-sm">
                        @forelse ($benefits['typed'] as $row)
                            <span class="block">{{ $row['name'] }} <span class="block text-xs text-muted-foreground">{{ $row['detail'] }}</span></span>
                        @empty
                            <span class="text-muted-foreground">None</span>
                        @endforelse
                    </dd>
                </div>
            </dl>
        </section>
    </div>

    @if ($payData)
        <section class="hris-card overflow-hidden" aria-labelledby="differs-heading">
            <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 id="differs-heading" class="text-base font-bold">Employees paid differently</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Active employees whose pay does not come from the company settings alone.</p>
                </div>
                <flux:link :href="route('records.employee-pay-settings')" wire:navigate class="text-sm font-medium">All employees</flux:link>
            </header>
            <div class="hris-table-wrap">
                <table class="hris-table">
                    <thead><tr><th>Employee</th><th>What differs</th><th class="text-right">Action</th></tr></thead>
                    <tbody>
                        @forelse ($differs['rows'] as $row)
                            <tr wire:key="differs-{{ $row['id'] }}">
                                <td>
                                    <p class="font-medium">{{ $row['name'] }}</p>
                                    <p class="text-xs text-muted-foreground">{{ $row['status'] ?: 'No employment status' }}</p>
                                </td>
                                <td class="text-sm">{{ implode(' · ', $row['differs']) }}</td>
                                <td class="text-right">
                                    <flux:link :href="route('records.employee-pay-settings.show', ['employee' => $row['id']])" wire:navigate class="text-sm font-medium">Pay settings</flux:link>
                                </td>
                            </tr>
                        @empty
                            <tr wire:key="differs-empty">
                                <x-kit.empty-state :colspan="3" title="Everyone is paid by the company settings." hint="An employee's own settings, rates or recurring lines would list them here." />
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($differs['total'] > count($differs['rows']))
                <p class="border-t border-border px-5 py-3 text-xs text-muted-foreground">
                    Showing {{ count($differs['rows']) }} of {{ $differs['total'] }}.
                    <flux:link :href="route('records.employee-pay-settings')" wire:navigate>See all of them</flux:link>
                </p>
            @endif
        </section>
    @endif
</div>
