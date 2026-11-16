@use('App\Modules\WorkManagement\Application\TaskGroups')
<flux:modal wire:model.self="groupEditorOpen" class="w-full max-w-md">
    <form wire:submit="saveGroup" class="space-y-5">
        <flux:heading level="2" size="lg">{{ $editingGroupId === null ? 'New activity' : 'Rename activity' }}</flux:heading>
        <flux:input label="Activity name" wire:model="groupName" placeholder="e.g. Design" maxlength="{{ TaskGroups::NAME_LIMIT }}" required autofocus />
        <div class="flex justify-end gap-2">
            <flux:button type="button" variant="ghost" wire:click="$set('groupEditorOpen', false)">Cancel</flux:button>
            <flux:button type="submit" variant="primary">{{ $editingGroupId === null ? 'Create activity' : 'Save name' }}</flux:button>
        </div>
    </form>
</flux:modal>
