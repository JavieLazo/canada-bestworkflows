{{--
    Reports and CSV share the same selection; the cards on top are labelled
    as register-wide. A report with a Cost column says what its items add up
    to under the current filters — every page, not the one shown — and how
    many have no cost recorded; Stock on hand also splits it by category.
    Every figure comes from InventoryReports (`totals`, `value`).
--}}
@php
    use App\Livewire\Inventory\Support\InventoryReportRows;
    use App\Support\Currency;

    $costColumn = array_search('Cost', $columns, true);
    $noCostNote = $value === null ? '' : InventoryReportRows::withoutCostNote($value['without_cost']);
@endphp
<div class="space-y-6" wire:poll.60s="pollForChanges">
    <div><h1 class="text-2xl font-bold tracking-tight">Inventory Reports</h1></div>
    @include('livewire.inventory.partials.tabs', ['activeInventoryTab' => 'reports'])
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach (['stock' => 'In stock', 'issued' => 'Issued', 'exceptions' => 'Condition / count exceptions', 'open_incidents' => 'Open incident reports', 'open_counts' => 'Open counts'] as $key => $label)
            <div class="hris-card p-4" data-total="{{ $key }}">
                <p class="text-sm text-muted-foreground">{{ $label }}</p>
                <p class="num mt-1 text-2xl font-semibold">{{ number_format($totals[$key]) }}</p>
                @isset($totals['values'][$key])
                    <p class="mt-1 text-xs text-muted-foreground" data-total-value>Total value <span class="num font-medium text-foreground">{{ Currency::format($totals['values'][$key]['value']) }}</span></p>
                @endisset
            </div>
        @endforeach
    </div>
    <p class="text-xs text-muted-foreground">Totals cover the whole register. Total value adds up what each item cost when it was acquired. Exceptions include damaged, unserviceable or missing items, open incident reports and unresolved physical-count discrepancies. Disposed items are excluded.</p>
    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <x-kit.select class="w-full sm:w-48" aria-label="Inventory report" wire:model.live="report">
                <flux:select.option value="holdings">Employee holdings</flux:select.option><flux:select.option value="stock">Stock on hand</flux:select.option><flux:select.option value="exceptions">Condition & count exceptions</flux:select.option><flux:select.option value="movements">Movement history</flux:select.option>
            </x-kit.select>
            <flux:input class="w-full min-w-40 max-w-xs flex-1" aria-label="Search report items" placeholder="Search item code or name" wire:model.live.debounce.250ms="search" />
            <div class="w-full sm:w-60"><livewire:kit.employee-picker :value="$employeeId" name="inventory-report-employee" placeholder="All employees" :key="'inventory-report-employee-'.$employeeId" /></div>
            @if ($employeeId !== '')<flux:button size="sm" variant="ghost" wire:click="clearEmployee">All Employees</flux:button>@endif
            <div class="ml-auto"><x-kit.export-button :href="route('inventory.reports.export', ['report' => $report, 'q' => $search, 'employee' => $employeeId])" /></div>
        </header>
        @if ($value !== null)
            {{-- What the rows below add up to, across every page of them. --}}
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-b border-border bg-muted/40 px-5 py-3 text-sm" data-report-value>
                <p><span class="text-muted-foreground">Items</span> <strong class="num" data-report-items>{{ number_format($value['items']) }}</strong></p>
                <p><span class="text-muted-foreground">Total Value</span> <strong class="num" data-report-total>{{ Currency::format($value['value']) }}</strong></p>
                @if ($noCostNote !== '')
                    <p class="text-muted-foreground" data-report-no-cost>{{ $noCostNote }}, so the total leaves {{ $value['without_cost'] === 1 ? 'it' : 'them' }} out.</p>
                @endif
                <p class="text-xs text-muted-foreground sm:ml-auto">Covers every item this report lists, on all pages.</p>
            </div>
        @endif
        <div class="hris-table-wrap hris-table-scroll"><table class="hris-table"><thead><tr>@foreach ($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>
            @forelse ($rows as $row)
                <tr wire:key="report-{{ $report }}-{{ $row->id }}">
                    @foreach ($row->cells as $index => $cell)
                        @if ($index === $costColumn)
                            <td class="num whitespace-nowrap">{{ Currency::format($cell) }}</td>
                        @else
                            <td>{{ $cell === '' ? '—' : $cell }}</td>
                        @endif
                    @endforeach
                </tr>
            @empty
                <tr wire:key="empty"><x-kit.empty-state :colspan="count($columns)" title="No items match this report." /></tr>
            @endforelse
        </tbody></table></div><x-kit.pagination :paginator="$rows" noun="records" />
    </section>
    @if ($value !== null && $value['categories'] !== [])
        <section class="hris-card overflow-hidden" data-value-by-category>
            <header class="border-b border-border px-5 py-4">
                <h2 class="font-semibold">Stock Value by Category</h2>
                <p class="text-sm text-muted-foreground">The same items as the report above, grouped by their category.</p>
            </header>
            <div class="hris-table-wrap">
                <table class="hris-table">
                    <thead><tr><th>Category</th><th>Items</th><th>Total Value</th><th>No Cost Recorded</th></tr></thead>
                    <tbody>
                        @foreach ($value['categories'] as $category)
                            <tr wire:key="category-{{ $loop->index }}-{{ $category['name'] }}">
                                <td>{{ $category['name'] === '' ? 'No category' : $category['name'] }}</td>
                                <td class="num">{{ number_format($category['items']) }}</td>
                                <td class="num whitespace-nowrap">{{ Currency::format($category['value']) }}</td>
                                <td class="num">{{ $category['without_cost'] === 0 ? '—' : number_format($category['without_cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-semibold">Total</td>
                            <td class="num font-semibold">{{ number_format($value['items']) }}</td>
                            <td class="num whitespace-nowrap font-semibold">{{ Currency::format($value['value']) }}</td>
                            <td class="num font-semibold">{{ $value['without_cost'] === 0 ? '—' : number_format($value['without_cost']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    @endif
</div>
