@php($embedded = $embedded ?? false)
<div @class(['space-y-4' => $embedded, 'flex max-h-[calc(100dvh-7rem)] min-h-0 flex-col gap-4' => ! $embedded]) aria-label="Project members">
    <div class="flex shrink-0 items-center justify-between gap-3 {{ $embedded ? '' : 'pr-8' }}">
        <div class="flex min-w-0 items-center gap-1"><h3 class="ppm-section-title">Project members</h3><flux:tooltip content="All tasks access comes from a project role. Assigned tasks access covers that person's work only."><flux:button size="sm" variant="ghost" square icon="information-circle" aria-label="About project access" /></flux:tooltip></div>
        @if ($canManage)<flux:button size="sm" variant="ghost" wire:click="openMember" aria-label="Add member"><flux:icon.user-plus class="size-4" /><span class="hidden sm:inline">Add member</span></flux:button>
        @endif
    </div>
    @include('livewire.projects.partials.people-controls', ['kind' => 'project'])
    <ul @class(['divide-y divide-border', 'min-h-0 ppm-scroll-quiet overflow-y-auto overscroll-contain' => ! $embedded])>
        @forelse ($team ?? [] as $person)
            <li class="flex min-w-0 items-center gap-3 py-3 first:pt-0 last:pb-0" wire:key="project-person-{{ $person->id }}">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold" aria-hidden="true">{{ mb_substr($person->name, 0, 1) }}</span>
                <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium" title="{{ $person->name }}">{{ $person->name }}</p><p class="truncate text-xs text-muted-foreground" title="{{ $person->position }}">{{ $person->access_label }}{{ $person->user_id === null || $person->active ? '' : ' · Inactive account' }}{{ $person->position ? ' · '.$person->position : '' }}</p></div>
                @if ($person->employee_id !== null)<button type="button" class="num shrink-0 text-xs text-muted-foreground hover:text-primary" wire:click="filterByAssignee('{{ $person->employee_id }}')" aria-label="View {{ $person->name }} tasks">{{ $person->open_tasks }} open</button>
                @endif
                @if ($canManage && $person->user_id !== null && ($person->explicit || $person->access === 'ASSIGNED'))
                    <flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Access actions for {{ $person->name }}" /><flux:menu><flux:menu.item icon="pencil-square" wire:click="openMember({{ $person->user_id }})">{{ $person->explicit ? 'Edit project role' : 'Add project role' }}</flux:menu.item></flux:menu></flux:dropdown>
                @endif
            </li>
        @empty
            <li class="py-6 text-center text-sm text-muted-foreground">No people match these filters.</li>
        @endforelse
    </ul>
    @if ($team !== null)<x-kit.pagination :paginator="$team" noun="people" page-size-model="peoplePerPage" page-size-label="Members per page" class="shrink-0 !border-0 !px-0" />
    @endif
</div>
