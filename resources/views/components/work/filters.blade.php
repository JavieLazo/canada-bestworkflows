@props(['id', 'count' => 0, 'label' => 'Filters'])
<x-work.popover :id="$id" :label="$label">
    <x-slot:trigger>
    <flux:button x-ref="trigger" size="sm" variant="outline" icon="adjustments-horizontal" :popovertarget="$id" aria-haspopup="dialog" x-bind:aria-expanded="open.toString()">
        Filters <span @class(['ppm-count', 'invisible' => $count === 0]) aria-hidden="{{ $count === 0 ? 'true' : 'false' }}">{{ $count }}</span>
    </flux:button>
    </x-slot:trigger>
        <h2 class="ppm-section-title">{{ $label }}</h2>
        {{ $slot }}
        <div class="flex items-center justify-between gap-3 pt-1">
            <flux:button size="sm" variant="ghost" wire:click="clearFilters" :disabled="$count === 0">Clear filters</flux:button>
            <flux:button size="sm" variant="outline" :popovertarget="$id" popovertargetaction="hide">Done</flux:button>
        </div>
</x-work.popover>
