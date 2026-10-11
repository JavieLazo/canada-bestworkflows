{{-- My Profile row removals remain pending until an independent reviewer approves. --}}
<flux:modal wire:model.self="confirmOpen" class="w-full max-w-md">
    <div class="space-y-4">
        <div class="space-y-2">
            <flux:heading size="lg">Delete {{ $deletingLabel }}?</flux:heading>
            <flux:text>
                @if ($requiresApproval)
                    The row stays on your official record until management approves its removal.
                @else
                    It is removed from {{ $name }}'s record for good. This cannot be undone.
                @endif
            </flux:text>
        </div>
        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="outline">Cancel</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="deleteConfirmed">{{ $requiresApproval ? 'Request removal' : 'Delete' }}</flux:button>
        </div>
    </div>
</flux:modal>
