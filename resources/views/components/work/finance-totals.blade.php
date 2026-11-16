@props(['totals', 'budgetAction', 'receiptAction', 'expenseAction'])
@php
    use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
    $utilized = $totals->utilized();
@endphp
<div {{ $attributes->class('space-y-5') }}>
    <div class="flex flex-wrap items-center justify-between gap-2"><span class="pill pill-muted">{{ $totals->currency }}</span><span class="num text-xs text-muted-foreground">{{ $totals->budgetedProjects }} / {{ $totals->projects }} approved budgets</span></div>
    <div class="flex flex-wrap items-center gap-5">
        <button type="button" wire:click="{{ $budgetAction }}" class="relative size-32 shrink-0 rounded-full focus-visible:outline-2 focus-visible:outline-primary" aria-label="View approved budget and paid expenses in {{ $totals->currency }}">
            <svg viewBox="0 0 100 100" class="size-full -rotate-90" aria-hidden="true"><circle cx="50" cy="50" r="40" fill="none" stroke-width="10" class="stroke-muted" /><circle cx="50" cy="50" r="40" fill="none" stroke-width="10" class="{{ ($totals->remaining() ?? 0) < 0 ? 'stroke-danger' : 'stroke-primary' }}" stroke-dasharray="{{ 251.327 * min(100, $utilized ?? 0) / 100 }} 251.327" /></svg>
            <span class="num absolute inset-0 flex flex-col items-center justify-center text-2xl font-semibold">{{ $utilized === null ? '—' : $utilized.'%' }}<span class="mt-1 text-xs font-normal text-muted-foreground">budget paid</span></span>
        </button>
        <dl class="min-w-40 flex-1 space-y-3 text-xs">@foreach (['Approved budget' => $totals->budget(), 'Paid on budgeted work' => $totals->budgetedProjects === 0 ? null : $totals->budgetedPaidMinor, 'Remaining budget' => $totals->remaining()] as $label => $value)<div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"><dt class="text-muted-foreground">{{ $label }}</dt><dd class="num font-semibold {{ $value !== null && $value < 0 ? 'text-danger' : '' }}">{{ $value === null ? 'Not set' : FinanceAmount::format($value) }}</dd></div>
        @endforeach
        </dl>
    </div>
    @php($maxCash = max(1, $totals->receivedMinor, $totals->paidMinor))
    <div class="grid grid-cols-2 gap-4 border-t border-border pt-4">@foreach ([['Recorded receipts', $totals->receivedMinor, $receiptAction, 'bg-success'], ['Paid expenses', $totals->paidMinor, $expenseAction, 'bg-primary']] as [$label, $amount, $action, $color])<button type="button" wire:click="{{ $action }}" class="min-w-0 space-y-2 rounded-lg text-left focus-visible:outline-2 focus-visible:outline-primary"><span class="flex items-center justify-between gap-2 text-xs text-muted-foreground">{{ $label }}<flux:icon.arrow-up-right class="size-3" /></span><span class="num block break-words text-lg font-semibold">{{ FinanceAmount::format($amount) }}</span><span class="block h-1.5 overflow-hidden rounded bg-muted" aria-hidden="true"><span class="block h-full rounded {{ $color }}" style="width: {{ $amount / $maxCash * 100 }}%"></span></span></button>
        @endforeach
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2 text-xs"><span class="text-muted-foreground" title="Recorded receipts minus paid expenses, excluding opening records.">Net recorded cash</span><span class="num font-medium {{ $totals->netCash() < 0 ? 'text-danger' : '' }}">{{ FinanceAmount::format($totals->netCash()) }}</span></div>
</div>
