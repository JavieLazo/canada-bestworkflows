{{-- Catalog labels are live reference data; issued receipts keep their captured labels. --}}
<div class="space-y-6" wire:poll.60s="pollForChanges">
    <div><h1 class="text-2xl font-bold tracking-tight">Categories & locations</h1></div>
    @include('livewire.inventory.partials.tabs', ['activeInventoryTab' => 'catalogs'])
    @include('livewire.inventory.partials.errors')
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (['category' => ['Categories', $categories], 'location' => ['Locations', $locations]] as $catalogKind => [$heading, $catalogRows])
            <section class="hris-card overflow-hidden"><header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4"><h2 class="font-semibold">{{ $heading }}</h2>@if ($canManage)<flux:button size="sm" icon="plus" variant="primary" wire:click="openCatalog('{{ $catalogKind }}')">Add {{ $catalogKind }}</flux:button>@endif</header><div class="hris-table-wrap hris-table-scroll"><table class="hris-table"><thead><tr><th>Name</th><th class="text-right">Items</th><th class="text-right">Action</th></tr></thead><tbody>
                @forelse ($catalogRows as $row)<tr wire:key="catalog-{{ $catalogKind }}-{{ $row->id }}"><td class="font-medium">{{ $row->name }}</td><td class="num text-right">{{ $row->item_count }}</td><td class="text-right">@if ($canManage)<x-kit.row-actions :label="$row->name"><flux:menu.item icon="pencil" wire:click="openCatalog('{{ $catalogKind }}', '{{ $row->id }}')">Rename</flux:menu.item><flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete('{{ $catalogKind }}', '{{ $row->id }}')">Delete unused {{ $catalogKind }}</flux:menu.item></x-kit.row-actions>@else — @endif</td></tr>@empty<tr wire:key="empty-{{ $catalogKind }}"><x-kit.empty-state :colspan="3" :title="'No '.strtolower($heading).' yet.'" /></tr>@endforelse
            </tbody></table></div></section>
        @endforeach
    </div>
    <p class="text-sm text-muted-foreground">Renaming updates the current register. Historical MRs retain the original labels. A category or location in use cannot be deleted.</p>
    <flux:modal wire:model.self="dialogOpen" class="w-full max-w-md"><form wire:submit="saveCatalog" class="space-y-5"><flux:heading size="lg">{{ $editingId ? 'Rename' : 'Add' }} {{ $kind }}</flux:heading>@include('livewire.inventory.partials.errors')<flux:input label="Name" wire:model="name" maxlength="160" required /><div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="outline">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Save {{ $kind }}</flux:button></div></form></flux:modal>
    <flux:modal wire:model.self="deleteDialog" class="w-full max-w-md"><div class="space-y-4"><flux:heading size="lg">Delete {{ $deletingKind }}?</flux:heading><flux:text>Only unused reference names can be deleted.</flux:text>@include('livewire.inventory.partials.errors')<div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="outline">Cancel</flux:button></flux:modal.close><flux:button variant="danger" wire:click="deleteCatalog">Delete</flux:button></div></div></flux:modal>
</div>
