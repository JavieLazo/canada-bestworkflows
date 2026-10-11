{{-- Item and custody drafts share the same fields on the register and item detail. --}}
<flux:modal wire:model.self="itemDialog" class="w-full max-w-2xl">
    <form wire:submit="saveItem" class="space-y-5">
        <div>
            <flux:heading size="lg">{{ $editingId ? 'Edit item' : 'Add item' }}</flux:heading>
            <flux:text>{{ $editingId ? 'Issued receipts keep their original details.' : 'Each physical item starts in stock.' }}</flux:text>
        </div>
        @include('livewire.inventory.partials.errors')
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2"><flux:input label="Item code" wire:model="form.code" maxlength="40" required /></div>
            <div class="sm:col-span-2"><flux:input label="Item description" wire:model="form.name" maxlength="240" required /></div>
            <flux:input label="Serial number" wire:model="form.serial_no" maxlength="160" />
            <div class="space-y-2"><flux:label>Acquisition date</flux:label><x-kit.date-field wire:model="form.acquired_on" aria-label="Acquisition date" /></div>
            <div class="space-y-2"><flux:label>Acquisition cost</flux:label><x-kit.money-input wire:model="form.cost" aria-label="Acquisition cost" /></div>
            <x-kit.select label="Category" wire:model="form.category_id"><flux:select.option value="">No category</flux:select.option>@foreach ($categories as $category)<flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>@endforeach</x-kit.select>
            <x-kit.select label="Location" wire:model="form.location_id"><flux:select.option value="">No location</flux:select.option>@foreach ($locations as $location)<flux:select.option value="{{ $location->id }}">{{ $location->name }}</flux:select.option>@endforeach</x-kit.select>
            <div class="sm:col-span-2"><flux:textarea label="Remarks" wire:model="form.remarks" rows="3" /></div>
        </div>
        <div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="outline">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Save item</flux:button></div>
    </form>
</flux:modal>
<flux:modal wire:model.self="movementDialog" class="w-full max-w-xl">
    <form wire:submit="saveMovement" class="space-y-5">
        <div>
            <flux:heading size="lg">{{ ['issue' => 'Issue selected items', 'transfer' => 'Transfer selected items', 'return' => 'Return selected items'][$movement] ?? 'Move items' }}</flux:heading>
            <flux:text>{{ count($selectedIds) }} selected {{ count($selectedIds) === 1 ? 'item' : 'items' }}. {{ $movement === 'return' ? 'Record an actual return to stock. The original MR keeps its item history.' : 'The selected items will appear together on one new memorandum receipt.' }}</flux:text>
        </div>
        @include('livewire.inventory.partials.errors')
        @if ($movement !== 'return')
            <flux:input label="MR No." wire:model="mrNumber" maxlength="80" required />
            <div class="space-y-2"><flux:label id="inventory-recipient-label">Received by</flux:label><livewire:kit.employee-picker :value="$employeeId" name="inventory-recipient" labelled-by="inventory-recipient-label" :active-only="true" :key="'inventory-recipient-'.$employeeId" /></div>
            <div class="space-y-2"><flux:label id="inventory-issuer-label">Issued by</flux:label><livewire:kit.employee-picker :value="$issuerId" name="inventory-issuer" labelled-by="inventory-issuer-label" :active-only="true" :key="'inventory-issuer-'.$issuerId" /></div>
            <p class="text-xs text-muted-foreground">Both names come from Employee Records. The signed-in account is recorded separately in the audit trail.</p>
        @endif
        <div class="space-y-2"><flux:label>{{ $movement === 'return' ? 'Return date' : 'Issue date' }}</flux:label><x-kit.date-field wire:model="movementDate" aria-label="Movement date" /></div>
        <flux:textarea label="Remarks" wire:model="movementRemarks" rows="3" />
        @if ($movement === 'transfer')<p class="text-sm text-muted-foreground">Select items held by the same employee. The original MR retains its transferred lines.</p>@endif
        <div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="outline">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ $movement === 'return' ? 'Record return' : 'Issue memorandum receipt' }}</flux:button></div>
    </form>
</flux:modal>
<flux:modal wire:model.self="deleteDialog" class="w-full max-w-md">
    <div class="space-y-4">
        <flux:heading size="lg">Delete this item?</flux:heading>
        <flux:text>Only an item that has never been issued can be deleted. Receipt and movement history must be retained.</flux:text>
        @include('livewire.inventory.partials.errors')
        <div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="outline">Cancel</flux:button></flux:modal.close><flux:button variant="danger" wire:click="deleteItem">Delete item</flux:button></div>
    </div>
</flux:modal>
