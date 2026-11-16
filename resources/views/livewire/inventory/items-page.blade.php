{{-- The register keeps custody context beside each item and reveals bulk tools after selection. --}}
@php use App\Support\HrisFormat; @endphp
<div class="space-y-6" wire:poll.60s="pollForChanges">
    <div><h1 class="text-2xl font-bold tracking-tight">Inventory</h1><p class="text-sm text-muted-foreground">Items the office owns, and the employee each one is issued to.</p></div>
    @include('livewire.inventory.partials.tabs', ['activeInventoryTab' => 'items'])
    @include('livewire.inventory.partials.errors')
    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input aria-label="Search items" placeholder="Search code, item or serial number" class="w-full min-w-[12rem] max-w-xs flex-1" wire:model.live.debounce.250ms="search" />
            <x-kit.filter-select label="Filter by Status" class="w-[10rem]" wire:model.live="status"><flux:select.option value="ALL">All statuses</flux:select.option><flux:select.option value="IN_STOCK">In stock</flux:select.option><flux:select.option value="ISSUED">Issued</flux:select.option><flux:select.option value="DISPOSED">Disposed</flux:select.option></x-kit.filter-select>
            <x-kit.filter-select label="Filter by Category" class="w-[12rem]" wire:model.live="categoryId"><flux:select.option value="ALL">All categories</flux:select.option>@foreach ($categories as $category)<flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>@endforeach</x-kit.filter-select>
            <x-kit.filter-select label="Filter by Location" class="w-[12rem]" wire:model.live="locationId"><flux:select.option value="ALL">All locations</flux:select.option>@foreach ($locations as $location)<flux:select.option value="{{ $location->id }}">{{ $location->name }}</flux:select.option>@endforeach</x-kit.filter-select>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <x-kit.export-button :href="route('inventory.items.export', array_filter(['q' => $search, 'status' => $status, 'category_id' => $categoryId, 'location_id' => $locationId, 'sort' => $sortKey, 'dir' => $sortDir]))" />
                <livewire:kit.csv-import table="inventory.items" :template="route('inventory.items.export', ['template' => 1])" key="import-inventory-items" />
                @if ($canManage)<flux:button variant="primary" icon="plus" wire:click="openAdd">Add Item</flux:button>@endif
            </div>
        </header>
        @if ($canManage && $selectedIds !== [])
            <div class="flex flex-wrap items-center gap-2 border-b border-border bg-muted/30 px-5 py-3" role="group" aria-label="Selected item actions">
                <span class="mr-2 text-sm font-medium" aria-live="polite">{{ count($selectedIds) }} selected</span>
                <flux:button size="sm" icon="user-plus" wire:click="startMovement('issue')">Issue Items</flux:button>
                <flux:button size="sm" icon="arrows-right-left" wire:click="startMovement('transfer')">Transfer Items</flux:button>
                <flux:button size="sm" icon="arrow-uturn-left" wire:click="startMovement('return')">Return Items</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="$set('selectedIds', [])">Clear Selection</flux:button>
            </div>
        @endif
        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead><tr>@if ($canManage)<th><span class="sr-only">Select Item</span></th>@endif<x-kit.sort-header field="code" label="Item Code" /><x-kit.sort-header field="name" label="Item" /><x-kit.sort-header field="status" label="Status" /><th>Issued To</th><th>Date Issued</th><th class="text-right">Action</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="item-{{ $row->id }}">
                            @if ($canManage)<td><flux:checkbox wire:model.live="selectedIds" value="{{ $row->id }}" aria-label="Select {{ $row->code }}" /></td>@endif
                            <td class="num whitespace-nowrap"><a class="text-primary hover:underline" href="{{ route('inventory.items.show', ['id' => $row->id]) }}" wire:navigate>{{ $row->code }}</a></td>
                            <td>
                                <a class="font-medium hover:text-primary hover:underline" href="{{ route('inventory.items.show', ['id' => $row->id]) }}" wire:navigate>{{ $row->name }}</a>
                                <div class="num text-xs text-muted-foreground">{{ $row->serial_no ?: 'No serial number' }}</div>
                                <div class="text-xs text-muted-foreground">{{ $row->category_name ?: 'Uncategorized' }} · {{ $row->location_name ?: 'No location' }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="pill {{ $row->status === 'ISSUED' ? 'pill-primary' : 'pill-muted' }}">{{ ucwords(strtolower(str_replace('_', ' ', $row->status))) }}</span>
                                <div class="mt-1"><span class="{{ $row->condition === 'SERVICEABLE' ? 'text-xs text-muted-foreground' : 'pill pill-caution' }}">{{ ucfirst(strtolower($row->condition)) }}</span></div>
                            </td>
                            <td>
                                @if ($row->employee_name)
                                    <div class="font-medium">{{ $row->employee_name }}</div>
                                    @if ($row->employee_position)<div class="text-xs text-muted-foreground">{{ $row->employee_position }}</div>@endif
                                    @if ($row->current_receipt_id)<a class="num inline-block max-w-64 break-all text-xs text-foreground hover:underline" href="{{ route('inventory.receipts.show', ['id' => $row->current_receipt_id]) }}" wire:navigate>MR No. {{ $row->current_receipt_mr_number }}</a>@endif
                                @else <span class="text-muted-foreground">—</span> @endif
                            </td>
                            <td class="num whitespace-nowrap">{{ HrisFormat::date($row->current_receipt_issued_on) }}</td>
                            <td class="text-right">
                                <x-kit.row-actions :label="$row->name">
                                    <flux:menu.item icon="eye" :href="route('inventory.items.show', ['id' => $row->id])" wire:navigate>View Item</flux:menu.item>
                                    @if ($canManage)
                                        @if ($row->status === 'IN_STOCK' && $row->condition === 'SERVICEABLE')
                                            <flux:menu.item icon="user-plus" wire:click="startMovement('issue', '{{ $row->id }}')">Issue…</flux:menu.item>
                                        @elseif ($row->status === 'ISSUED')
                                            <flux:menu.item icon="arrows-right-left" :disabled="$row->condition !== 'SERVICEABLE'" wire:click="startMovement('transfer', '{{ $row->id }}')">Transfer…</flux:menu.item>
                                            <flux:menu.item icon="arrow-uturn-left" :disabled="$row->condition === 'MISSING'" wire:click="startMovement('return', '{{ $row->id }}')">Return…</flux:menu.item>
                                        @endif
                                        <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->id }}')">Edit Item</flux:menu.item>
                                        @if ($row->status === 'IN_STOCK')<flux:menu.item variant="danger" icon="trash" wire:click="confirmDelete('{{ $row->id }}')">Delete Unused Item</flux:menu.item>@endif
                                    @endif
                                </x-kit.row-actions>
                            </td>
                        </tr>
                    @empty
                        <tr wire:key="empty"><x-kit.empty-state :colspan="$canManage ? 7 : 6" title="No items match those filters." :hint="$canManage ? 'Add an item to start the property register.' : 'Items issued to your employee record appear here.'" /></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-kit.pagination :paginator="$rows" noun="items" />
    </section>
    @if ($canManage)@include('livewire.inventory.partials.item-dialogs')@endif
</div>
