{{-- Editing a personal-data row in My Profile creates a proposal. --}}
<flux:modal wire:model.self="dialogOpen" class="w-full max-w-lg">
    <div class="space-y-4" x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'pds-dialog-heading-{{ $list }}')">
        <div class="space-y-2">
            <flux:heading size="lg" id="pds-dialog-heading-{{ $list }}">{{ $editingId !== null ? $spec['dialog']['edit'] : $spec['dialog']['add'] }}</flux:heading>
            <flux:text>{{ $spec['dialog']['text'] }}</flux:text>
        </div>
        <form class="grid gap-4 sm:grid-cols-2" wire:submit="save">
            @include('livewire.employees.partials.pds.fields', ['fields' => $fields, 'model' => 'form', 'fieldErrors' => $formErrors])

            @if ($list === 'education' && $editingId === null)
                @foreach ($legacy as $level => $line)
                    <p
                        wire:key="pds-legacy-note-{{ $level }}"
                        class="text-xs text-muted-foreground sm:col-span-2"
                        x-show="$wire.form.level === '{{ $level }}'"
                        @style(['display: none' => ($form['level'] ?? '') !== $level])
                    >Entered before: {{ $line }} — shown until a school of this level is added.</p>
                @endforeach
            @endif

            @if ($requiresApproval)
                <p class="text-xs text-muted-foreground sm:col-span-2">This row changes only after management approves your request.</p>
            @endif
            <div class="flex flex-wrap justify-end gap-2 sm:col-span-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                @if (! $requiresApproval)
                    <flux:button variant="outline" wire:click="save(true)">Save &amp; add another</flux:button>
                @endif
                <flux:button type="submit" variant="primary">{{ $requiresApproval ? 'Submit for approval' : 'Save' }}</flux:button>
            </div>
        </form>
    </div>
</flux:modal>
