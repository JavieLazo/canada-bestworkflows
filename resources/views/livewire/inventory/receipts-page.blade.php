{{-- Receipt contents and frozen employee context make each issue recognizable in the register. --}}
@php use App\Support\HrisFormat; @endphp
<div class="space-y-6" wire:poll.60s="pollForChanges">
    <div><h1 class="text-2xl font-bold tracking-tight">Memorandum Receipts</h1><p class="text-sm text-muted-foreground">Items issued together to an employee, with their original receipt and history.</p></div>
    @include('livewire.inventory.partials.tabs', ['activeInventoryTab' => 'receipts'])
    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input aria-label="Search memorandum receipts" placeholder="Search MR No. or employee" class="w-full min-w-[12rem] max-w-xs flex-1" wire:model.live.debounce.250ms="search" />
            <x-kit.filter-select label="Filter by Status" class="w-[12rem]" wire:model.live="status"><flux:select.option value="ALL">All statuses</flux:select.option><flux:select.option value="OPEN">Open</flux:select.option><flux:select.option value="CLOSED">Closed</flux:select.option><flux:select.option value="SUPERSEDED">Superseded</flux:select.option></x-kit.filter-select>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <x-kit.export-button :href="route('inventory.receipts.export', array_filter(['q' => $search, 'status' => $status, 'sort' => $sortKey, 'dir' => $sortDir]))" />
                @if ($canManage)<livewire:inventory.issue-receipt />@endif
            </div>
        </header>
        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead><tr><x-kit.sort-header field="mr_number" label="MR No." /><x-kit.sort-header field="issued_on" label="Date Issued" /><th>Received By</th><th>Original Items</th><x-kit.sort-header field="status" label="Status" /><th class="text-right">Action</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="receipt-{{ $row->id }}">
                            <td class="num max-w-64 break-all"><a class="font-medium text-primary hover:underline" href="{{ route('inventory.receipts.show', ['id' => $row->id]) }}" wire:navigate>{{ $row->mr_number }}</a></td>
                            <td class="num whitespace-nowrap">{{ HrisFormat::date($row->issued_on) }}</td>
                            <td><div class="font-medium">{{ $row->employee_name }}</div><div class="text-xs text-muted-foreground">{{ $row->employee_snapshot['position'] ?? '' }}</div></td>
                            <td class="max-w-sm"><a class="font-medium hover:text-primary hover:underline" href="{{ route('inventory.receipts.show', ['id' => $row->id]) }}" wire:navigate>{{ $row->item_count }} {{ $row->item_count === 1 ? 'item' : 'items' }}</a><div class="text-xs text-muted-foreground">{{ $row->item_summary }}</div></td>
                            <td><span class="pill {{ $row->status === 'OPEN' ? 'pill-primary' : 'pill-muted' }}">{{ ucfirst(strtolower($row->status)) }}</span></td>
                            <td class="text-right"><x-kit.row-actions :label="'MR No. '.$row->mr_number"><flux:menu.item icon="eye" :href="route('inventory.receipts.show', ['id' => $row->id])" wire:navigate>View MR</flux:menu.item><flux:menu.item icon="printer" :href="route('inventory.receipts.print', ['id' => $row->id])" target="_blank">Print MR</flux:menu.item></x-kit.row-actions></td>
                        </tr>
                    @empty
                        <tr wire:key="empty"><x-kit.empty-state :colspan="6" title="No memorandum receipts match those filters." :hint="$canManage ? 'Use Issue items to create a receipt for an employee.' : 'Your issued receipts appear here.'" /></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-kit.pagination :paginator="$rows" noun="receipts" />
    </section>
</div>
