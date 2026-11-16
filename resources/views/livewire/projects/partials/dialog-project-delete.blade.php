{{-- The delete-project confirm — rendered for managers only ($canManage from Show::render()); deleteProject() re-checks the gate itself. Included by show.blade.php and shares that view's variables. --}}
{{-- Delete project — no reference dialog; modelled on the employees
     delete confirm, rendered for managers only (the action re-checks
     the gate itself).

     Accessible name: Flux forwards only class/style/autofocus from
     <flux:modal> to its <dialog>, so an aria-label on the tag lands
     on the <ui-modal> wrapper and names nothing. The heading carries
     an id and x-init points the dialog's aria-labelledby at it once
     Alpine boots; the dialog is wire:ignore.self, so the attribute
     survives every re-render. --}}
@if ($canManage)
    <flux:modal
        wire:model.self="deleteConfirmOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'delete-project-heading')"
    >
        <div class="space-y-4">
            <div>
                <flux:heading size="lg" id="delete-project-heading">Delete this project?</flux:heading>
                <flux:text class="mt-2">
                    This permanently removes {{ $project->code }} &mdash; {{ $project->name }}, its
                    <span class="num">{{ $taskCount }}</span> {{ $taskCount === 1 ? 'task' : 'tasks' }}, and
                    every file and comment attached to them. This cannot be undone.
                </flux:text>
            </div>
            <div class="flex items-center justify-end gap-2">
                <flux:button variant="outline" wire:click="$set('deleteConfirmOpen', false)">
                    Cancel
                </flux:button>
                <flux:button variant="danger" wire:click="deleteProject">
                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'trash-2', 'class' => 'size-4'])</x-slot:icon>
                    Delete Project
                </flux:button>
            </div>
        </div>
    </flux:modal>
@endif
