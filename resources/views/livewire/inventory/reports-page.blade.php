{{-- Reports and CSV share the same selection; totals are labelled as register-wide. --}}
<div class="space-y-6" wire:poll.60s="pollForChanges">
    <div><h1 class="text-2xl font-bold tracking-tight">Inventory Reports</h1></div>
    @include('livewire.inventory.partials.tabs', ['activeInventoryTab' => 'reports'])
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach (['stock' => 'In stock', 'issued' => 'Issued', 'exceptions' => 'Condition / count exceptions', 'open_incidents' => 'Open incident reports', 'open_counts' => 'Open counts'] as $key => $label)
            <div class="hris-card p-4"><p class="text-sm text-muted-foreground">{{ $label }}</p><p class="num mt-1 text-2xl font-semibold">{{ number_format($totals[$key]) }}</p></div>
        @endforeach
    </div>
    <p class="text-xs text-muted-foreground">Totals cover the whole register. Exceptions include damaged, unserviceable or missing items, open incident reports and unresolved physical-count discrepancies. Disposed items are excluded.</p>
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
        <div class="hris-table-wrap hris-table-scroll"><table class="hris-table"><thead><tr>@foreach ($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>
            @forelse ($rows as $row)<tr wire:key="report-{{ $report }}-{{ $row->id }}">@foreach ($row->cells as $cell)<td>{{ $cell === '' ? '—' : $cell }}</td>@endforeach</tr>@empty<tr wire:key="empty"><x-kit.empty-state :colspan="count($columns)" title="No items match this report." /></tr>@endforelse
        </tbody></table></div><x-kit.pagination :paginator="$rows" noun="records" />
    </section>
</div>
