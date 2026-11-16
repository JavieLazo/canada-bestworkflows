<div>
    <flux:modal wire:model.self="editorOpen" class="w-full max-w-md"><form wire:submit="save" class="space-y-5">
        <flux:heading size="lg" level="2">New client</flux:heading>
        <flux:input wire:model="name" label="Client name" maxlength="200" required autofocus autocomplete="off" />
        <flux:textarea wire:model="description" label="Description" badge="Optional" rows="3" maxlength="4000" />
        <x-kit.select wire:model="draftPortfolioId" label="Portfolio" aria-label="New client portfolio" searchable><flux:select.option value="NONE">Without portfolio</flux:select.option>
            @foreach ($destinations as $destination)<flux:select.option value="{{ $destination->id }}">{{ $destination->name }}</flux:select.option>
            @endforeach
        </x-kit.select><flux:error name="portfolio" />
        <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('editorOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Create client</flux:button></div>
    </form></flux:modal>
</div>
