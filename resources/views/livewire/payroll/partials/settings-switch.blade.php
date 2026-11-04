{{-- The Payroll Settings pages share one switch, as Employee Records' views do; ordinary links keep each page's own address. --}}
@php
    $views = [
        'overview' => ['Overview', route('payroll.settings')],
        'employees' => ['Employees', route('records.employee-pay-settings')],
        'schedules' => ['Pay Schedules', route('payroll.settings.schedules')],
        'tables' => ['Contribution & Tax Tables', route('records.contribution-tables')],
        'rates' => ['Pay Basis & Rates', route('records.pay-rates')],
        'deductions' => ['Payroll Deductions', route('payroll.settings.deductions')],
        'incentives' => ['Incentives & Bonuses', route('records.incentive-types')],
        'adjustment-categories' => ['Adjustment Categories', route('payroll.settings.adjustment-categories')],
        'signatories' => ['Signatories', route('payroll.settings.signatories')],
    ];
@endphp
<nav aria-label="Payroll Settings pages" class="inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg bg-muted p-1 text-sm text-muted-foreground print:hidden">
    @foreach ($views as $key => [$label, $href])
        <a
            href="{{ $href }}"
            wire:navigate
            @if ($activeView === $key) aria-current="page" @endif
            class="inline-flex items-center justify-center rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $activeView === $key ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
        >
            {{ $label }}
        </a>
    @endforeach
</nav>
