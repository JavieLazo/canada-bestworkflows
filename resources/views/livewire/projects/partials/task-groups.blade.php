<div class="space-y-2">
    <flux:label>Activities</flux:label>
    <x-work.popover id="task-groups-picker" label="Choose activities">
        <x-slot:trigger><flux:button x-ref="trigger" size="sm" variant="outline" icon="rectangle-group" icon:trailing="chevron-down" class="w-full justify-between" popovertarget="task-groups-picker" aria-haspopup="dialog" x-bind:aria-expanded="open.toString()">{{ $groupSelection === [] ? 'All activities' : count($groupSelection).' selected' }}</flux:button></x-slot:trigger>
        <div class="space-y-3" x-data="{ query: '' }">
            <flux:input size="sm" icon="magnifying-glass" x-model="query" placeholder="Find an activity…" aria-label="Search activities" />
            <flux:checkbox.group wire:model.live="groupSelection" label="Activities">
                <div class="ppm-scroll-quiet max-h-60 space-y-2 overflow-y-auto">
                    <flux:checkbox value="NONE" label="No activity" />
                    @foreach ($groups as $taskGroup)
                        <div x-show="!query || $el.textContent.toLocaleLowerCase().includes(query.toLocaleLowerCase())" wire:key="group-filter-{{ $taskGroup->id }}">
                            <flux:checkbox value="{{ $taskGroup->id }}" :label="$taskGroup->name" />
                        </div>
                    @endforeach
                </div>
            </flux:checkbox.group>
            <div class="flex justify-end"><flux:button size="sm" variant="ghost" popovertarget="task-groups-picker" popovertargetaction="hide">Done</flux:button></div>
        </div>
    </x-work.popover>
    @if ($canEditWork && $activeGroup !== null)
        <div class="flex items-center justify-between gap-2 border-t border-border pt-2">
            <span class="truncate text-xs text-muted-foreground">{{ $activeGroup->name }}</span>
            <flux:dropdown position="bottom" align="end">
                <flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Activity actions" />
                <flux:menu>
                    <flux:menu.item icon="pencil-square" wire:click="openGroupEditor('{{ $activeGroup->id }}')">Rename activity</flux:menu.item>
                    <flux:menu.item variant="danger" icon="trash" wire:click="deleteGroup('{{ $activeGroup->id }}')">Remove empty activity</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    @endif
</div>
