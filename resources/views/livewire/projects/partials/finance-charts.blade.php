@use('App\Modules\WorkManagement\Domain\Projects\FinanceAmount')
<section class="ppm-surface ppm-chart-card" aria-label="Monthly recorded cash">
    <header><h3 class="ppm-section-title">Cash flow</h3><span class="text-xs text-muted-foreground">{{ $settings->currency }} · last six months</span></header>
    <div class="flex gap-4 text-xs text-muted-foreground"><span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-success"></span>Received</span><span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-primary"></span>Paid</span></div>
    @php($cashMaximum = max(1, ...array_column($cashTrend, 'received'), ...array_column($cashTrend, 'paid')))
    @if (collect($cashTrend)->contains(fn ($month) => $month['received'] > 0 || $month['paid'] > 0))
        <div class="flex min-h-56 flex-1 gap-3"><div class="num flex w-16 shrink-0 flex-col justify-between pb-7 text-right text-[10px] text-muted-foreground"><span>{{ FinanceAmount::format($cashMaximum) }}</span><span>{{ FinanceAmount::format((int) round($cashMaximum / 2)) }}</span><span>0</span></div>
            <div class="grid flex-1 grid-cols-6 gap-2 border-b border-border">@foreach ($cashTrend as $month)<div class="flex min-w-0 flex-col"><div class="flex flex-1 items-end justify-center gap-1 border-t border-dashed border-border/50" aria-label="{{ $month['month'] }}">
                @foreach ([['RECEIPT', 'received', 'bg-success', 'receivedWidth'], ['EXPENSE', 'paid', 'bg-primary', 'paidWidth']] as [$kind, $key, $color, $height])
                    @php($cashLabel = 'View '.$month['month'].' '.($key === 'paid' ? 'paid expenses' : 'receipts').': '.FinanceAmount::format($month[$key]).' '.$settings->currency)
                    <flux:tooltip class="contents" :content="$cashLabel"><button type="button" class="block w-1/3 rounded-t {{ $color }} hover:opacity-75 focus-visible:outline-2 focus-visible:outline-primary" style="height: {{ $month[$height] }}%; min-height: {{ $month[$key] > 0 ? '2px' : '0' }}" wire:click="showCashMonth('{{ $month['key'] }}', '{{ $kind }}')" aria-label="{{ $cashLabel }}" @disabled($month[$key] === 0)></button></flux:tooltip>
                @endforeach
            </div><span class="py-2 text-center text-[10px] text-muted-foreground">{{ explode(' ', $month['month'])[0] }}</span></div>
            @endforeach
            </div>
        </div>
    @else<div class="ppm-dashboard-empty"><flux:icon.chart-bar class="size-8 text-muted-foreground/40" /><span>No cash entries recorded in these months.</span></div>
    @endif
</section>
<section class="ppm-surface ppm-chart-card md:col-span-2" aria-label="Budget and paid expenses by category"><header><h3 class="ppm-section-title">Spend by category</h3><div class="flex gap-3 text-xs text-muted-foreground"><span class="flex items-center gap-1.5"><span class="h-2 w-3 rounded bg-muted-foreground/40"></span>Budget</span><span class="flex items-center gap-1.5"><span class="h-2 w-3 rounded bg-primary"></span>Paid</span></div></header>
    <div class="max-h-96 space-y-5 overflow-y-auto pr-1">@foreach ($categoryTotals as $row)<div class="space-y-2" wire:key="finance-category-{{ $row['id'] }}"><h4 class="truncate text-xs font-medium" title="{{ $row['name'] }}">{{ $row['name'] }}</h4><div class="space-y-1.5">
        @if ($row['budget'] !== null)<button type="button" wire:click="showBudget('{{ $row['id'] }}')" class="flex w-full items-center gap-3 rounded focus-visible:outline-2 focus-visible:outline-primary" aria-label="View approved {{ $row['name'] }} costs: {{ FinanceAmount::format($row['budget']) }} {{ $settings->currency }}"><span class="h-2 flex-1 overflow-hidden rounded bg-muted"><span class="block h-full rounded bg-muted-foreground/40" style="width: {{ $row['budgetWidth'] }}%"></span></span><span class="num w-24 shrink-0 text-right text-[11px] text-muted-foreground">{{ FinanceAmount::format($row['budget']) }}</span></button>
        @endif
        <button type="button" wire:click="showCashCategory('{{ $row['id'] }}')" class="flex w-full items-center gap-3 rounded focus-visible:outline-2 focus-visible:outline-primary" aria-label="View {{ $row['name'] }} payments: {{ FinanceAmount::format($row['paid']) }} {{ $settings->currency }}"><span class="h-2 flex-1 overflow-hidden rounded bg-muted"><span class="block h-full rounded bg-primary" style="width: {{ $row['paidWidth'] }}%"></span></span><span class="num w-24 shrink-0 text-right text-[11px]">{{ FinanceAmount::format($row['paid']) }}</span></button>
    </div></div>
    @endforeach
    </div>
</section>
