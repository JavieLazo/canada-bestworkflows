{{-- The receipt register opens a complete issue form without navigating away. --}}
<div>
    <flux:button variant="primary" icon="plus" wire:click="open">Issue items</flux:button>
    <flux:modal wire:model.self="dialogOpen" class="w-full max-w-2xl">
        @if ($dialogOpen)
            <form wire:submit="save" class="space-y-5">
                <div><flux:heading size="lg">Issue items</flux:heading><flux:text>Choose an employee and the items to place on one memorandum receipt.</flux:text></div>
                @include('livewire.inventory.partials.errors')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2"><flux:label id="issue-receipt-recipient-label">Received by</flux:label><livewire:kit.employee-picker :value="$employeeId" name="issue-receipt-recipient" labelled-by="issue-receipt-recipient-label" :active-only="true" :key="'issue-receipt-recipient-'.$employeeId" /></div>
                    <div class="space-y-2"><flux:label id="issue-receipt-issuer-label">Issued by</flux:label><livewire:kit.employee-picker :value="$issuerId" name="issue-receipt-issuer" labelled-by="issue-receipt-issuer-label" :active-only="true" :key="'issue-receipt-issuer-'.$issuerId" /></div>
                </div>
                <flux:input label="MR No." wire:model="mrNumber" maxlength="80" required />
                <div class="space-y-3">
                    <flux:input label="Items in stock" aria-label="Search items in stock" placeholder="Search code, item or serial number" wire:model.live.debounce.250ms="search" />
                    <p class="text-xs text-muted-foreground" aria-live="polite">{{ count($selectedIds) }} selected · {{ $stockItems->total() }} serviceable items match your search</p>
                    <div class="overflow-hidden rounded-lg border border-border">
                        <div class="max-h-64 overflow-y-auto divide-y divide-border" role="group" aria-label="Available items">
                            @forelse ($stockItems as $stockItem)
                                <label wire:key="issue-stock-{{ $stockItem->id }}" class="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-muted/50">
                                    <flux:checkbox wire:model.live="selectedIds" value="{{ $stockItem->id }}" aria-label="Select {{ $stockItem->name }}" />
                                    <span class="min-w-0 flex-1 text-sm"><span class="block break-words font-medium">{{ $stockItem->name }}</span><span class="block break-words text-xs text-muted-foreground">Item {{ $stockItem->code }} · {{ $stockItem->serial_no ?: 'No serial number' }}</span></span>
                                    <span class="num whitespace-nowrap text-xs text-muted-foreground">₱{{ number_format($stockItem->cost, 2) }}</span>
                                </label>
                            @empty
                                <p class="p-4 text-sm text-muted-foreground">No serviceable items in stock match your search.</p>
                            @endforelse
                        </div>
                        <x-kit.pagination :paginator="$stockItems" noun="items" />
                    </div>
                </div>
                <div class="space-y-2"><flux:label>Issue date</flux:label><x-kit.date-field wire:model="issuedOn" aria-label="Issue date" /></div>
                <flux:textarea label="Remarks" wire:model="remarks" rows="2" />
                <div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="outline">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary" :disabled="$selectedIds === []">Issue memorandum receipt</flux:button></div>
            </form>
        @endif
    </flux:modal>
</div>
