<div class="flex shrink-0 flex-wrap items-center gap-2" aria-label="People controls">
    <flux:input size="sm" wire:model.live.debounce.250ms="peopleSearch" icon="magnifying-glass" placeholder="Find a person…" aria-label="Search people" class="min-w-36 flex-1" />
    <flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="ghost" square icon="arrows-up-down" aria-label="Sort people" /><flux:menu>
        <flux:menu.item wire:click="sortPeople('name')" :icon="$peopleSort === 'name' ? ($peopleDirection === 'asc' ? 'arrow-up' : 'arrow-down') : null">Name</flux:menu.item>
        @if ($kind === 'project')<flux:menu.item wire:click="sortPeople('work')" :icon="$peopleSort === 'work' ? ($peopleDirection === 'asc' ? 'arrow-up' : 'arrow-down') : null">Open tasks</flux:menu.item>
        @endif
    </flux:menu></flux:dropdown>
    <flux:dropdown position="bottom" align="end"><flux:button size="sm" :variant="$peopleAccess === 'ALL' ? 'outline' : 'filled'" square icon="funnel" aria-label="Filter people by access" /><flux:menu>
        @foreach (['ALL' => 'All access', ...($kind === 'project' ? ['OWNER' => 'Client owner', 'LEAD' => 'Project lead', 'EDITOR' => 'All tasks · edit', 'VIEWER' => 'All tasks · view', 'ASSIGNED' => 'Assigned tasks', 'INACTIVE' => 'Access inactive'] : ['OWNER' => 'Owner', ($kind === 'portfolio' ? 'CONTRIBUTOR' : 'CLIENT') => ($kind === 'portfolio' ? 'Contributor' : 'Client access')])] as $value => $label)
            <flux:menu.item wire:click="$set('peopleAccess', '{{ $value }}')" :icon="$peopleAccess === $value ? 'check' : null">{{ $label }}</flux:menu.item>
        @endforeach
    </flux:menu></flux:dropdown>
</div>
