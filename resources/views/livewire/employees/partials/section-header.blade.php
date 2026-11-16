{{-- Shared section actions submit My Profile changes for approval and save management edits directly. --}}
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        @if ($titleHidden ?? false)
            <h2 class="sr-only">{{ $section['title'] }}</h2>
            @if (! empty($section['description']))
                <p class="text-sm text-muted-foreground">{{ $section['description'] }}</p>
            @endif
        @else
            <h2 class="text-base font-bold">{{ $section['title'] }}</h2>
            @if (! empty($section['description']))
                <p class="mt-1 text-sm text-muted-foreground">{{ $section['description'] }}</p>
            @endif
        @endif
    </div>
    <div>
        @if ($editing)
            <div class="flex items-center gap-2">
                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="cancelEditing">
                    Cancel
                </flux:button>
                <flux:button variant="primary" size="sm" wire:click="saveSection('{{ $section['id'] }}')">
                    {{ ($requiresApproval ?? false) ? 'Submit for Approval' : 'Save' }}
                </flux:button>
            </div>
        @elseif ($canEdit ?? false)
            <button
                type="button"
                wire:click="startEdit('{{ $section['id'] }}')"
                class="text-sm font-medium text-primary hover:underline"
            >
                Edit
            </button>
        @endif
    </div>
</div>

@if ($editing && ($requiresApproval ?? false))
    <p class="mt-3 text-xs text-muted-foreground">Your official record stays unchanged until this request is approved.</p>
@endif
