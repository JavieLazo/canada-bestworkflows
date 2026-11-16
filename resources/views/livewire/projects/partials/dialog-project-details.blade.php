@use('App\Domain\Projects\ProjectPolicy')
<flux:modal x-data="workProjectDetails" wire:model.self="detailsOpen" :closable="false" class="ppm-details-dialog min-w-0 w-full max-w-3xl">
    @if ($detailsOpen)
        <header class="flex shrink-0 items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div class="min-w-0"><flux:heading size="lg" level="2" id="project-details-heading">{{ $editing ? ($canManage ? 'Edit project' : 'Plan project start') : ($projectStatusEditing ? 'Edit project status' : 'Project details') }}</flux:heading><p class="mt-1 truncate text-xs text-muted-foreground" title="{{ $project->name }}">{{ $project->name }}</p></div>
            <div class="flex shrink-0 items-center gap-1">
                @if (! $editing && ! $projectStatusEditing && $runsProject)<flux:button size="sm" variant="ghost" wire:click="openEdit" aria-label="Edit project details"><flux:icon.pencil-square class="size-4" /><span class="hidden sm:inline">Edit details</span></flux:button>
                @endif
                <flux:button size="sm" variant="ghost" square icon="x-mark" aria-label="Close project details" wire:click="closeProjectDetails" />
            </div>
        </header>
        <div x-ref="body" class="ppm-details-body ppm-scroll-quiet min-h-0 overflow-y-auto p-5">
        @if ($editing)
            @include('livewire.projects.partials.dialog-project-edit')
        @else
            <div class="space-y-5" role="region" aria-label="Project details">
                <div class="flex min-w-0 items-center justify-between gap-3">
                    <span class="num min-w-0 truncate text-xs text-muted-foreground" title="{{ $project->code }}"><span class="sr-only">Project code </span>{{ $project->code }}</span>
                    @if (! $projectStatusEditing)<div class="flex shrink-0 items-center gap-1.5"><span class="{{ ProjectPolicy::statusPill($project->status) }}"><span class="sr-only">Project status: </span>{{ ProjectPolicy::statusLabel($project->status) }}</span>@if ($runsProject)<flux:button size="sm" variant="ghost" square icon="pencil-square" aria-label="Edit project status" wire:click="editProjectStatus" />@endif</div>
                    @endif
                </div>
                @if ($projectStatusEditing)
                    <form wire:submit="saveProjectStatus" class="space-y-2">
                        <div class="flex flex-wrap items-center gap-2"><x-kit.select size="sm" aria-label="Project status" wire:model="editStatus" class="min-w-0 flex-1">
                            @foreach (ProjectPolicy::STATUSES as $value => $label)<flux:select.option value="{{ $value }}" :disabled="$taskTotals->manualStatusError($project, $value) !== null">{{ $label }}</flux:select.option>
                            @endforeach
                        </x-kit.select><flux:button size="sm" variant="primary" type="submit">Save status</flux:button><flux:button size="sm" variant="ghost" wire:click="cancelProjectEdit">Cancel</flux:button></div>
                        <flux:error name="projectStatus" />
                    </form>
                @endif
                @if (! $projectStatusEditing)
                @if ($project->description)<x-work.description-preview :text="$project->description" :id="'project-details-description-'.$project->id" />
                @endif
                @include('livewire.projects.partials.project-details')
                <div class="border-t border-border pt-5">@include('livewire.projects.partials.project-members', ['embedded' => true])</div>
                @endif
            </div>
        @endif
        </div>
    @endif
</flux:modal>
