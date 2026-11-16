<div @class(['contents' => $toolsOnly, 'ppm-surface space-y-5 p-5' => $membersOnly, 'ppm-workspace space-y-6' => ! $membersOnly && ! $toolsOnly]) @if ($membersOnly) aria-label="Portfolio members" @endif>
@if ($membersOnly)
    @include('livewire.projects.partials.portfolio-members')
@else
    @if (! $toolsOnly)
    <x-work.breadcrumbs page="Portfolios" />
    <header class="ppm-page-header ppm-creation-header">
        <div class="flex min-w-0 items-center gap-3"><h1 class="ppm-heading">Portfolios</h1><span class="ppm-count">{{ $rows->total() }}</span></div>
        <div class="ppm-actions"><flux:button variant="primary" icon="plus" icon:class="hidden sm:block" wire:click="openCreate">New portfolio</flux:button></div>
    </header>
    <div class="ppm-collection-toolbar" aria-label="Portfolio controls">
        <flux:input size="sm" wire:model.live.debounce.250ms="search" placeholder="Find a portfolio…" aria-label="Search portfolios" icon="magnifying-glass" class="ppm-search" />
        @include('livewire.projects.partials.collection-controls', ['kind' => 'portfolios'])
    </div>
    @if ($collectionView === 'list' && $rows->isNotEmpty())
        @include('livewire.projects.partials.container-list', ['kind' => 'portfolios'])
    @else
    <section class="ppm-library" aria-label="Your portfolios">
        @forelse ($rows as $row)
            <article class="ppm-surface ppm-project-card" wire:key="portfolio-{{ $row->id }}">
                <a href="{{ route('projects.index', ['portfolio' => $row->id]) }}" aria-label="Open {{ $row->name }} portfolio" class="focus:outline-none">
                    <div class="flex items-center justify-between gap-3 pr-6"><span class="flex size-11 items-center justify-center rounded-xl bg-primary-soft text-primary"><flux:icon.rectangle-stack class="size-5" /></span>
                        @if ($row->archived_at !== null)<span class="pill pill-muted">Archived</span>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1 space-y-2"><h2 class="line-clamp-2 break-words" title="{{ $row->name }}">{{ $row->name }}</h2>
                        @if ($row->description !== null)<p class="line-clamp-2 text-sm text-muted-foreground">{{ $row->description }}</p>
                        @endif
                    </div>
                    <div class="num flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground"><span>{{ $counts[$row->id]['clients'] }} {{ $counts[$row->id]['clients'] === 1 ? 'client' : 'clients' }}</span><span>{{ $counts[$row->id]['projects'] }} {{ $counts[$row->id]['projects'] === 1 ? 'project' : 'projects' }}</span></div>
                </a>
                @if ($row->can_manage)
                    <div class="ppm-client-tools"><flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Actions for {{ $row->name }}" />
                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="openEdit('{{ $row->id }}')">Edit portfolio</flux:menu.item>
                            <flux:menu.item icon="users" wire:click="openPeople('{{ $row->id }}')">Manage access</flux:menu.item>
                            <flux:menu.item :icon="$row->archived_at === null ? 'archive-box' : 'arrow-uturn-left'" wire:click="archivePortfolio('{{ $row->id }}', {{ $row->archived_at === null ? 'true' : 'false' }})">{{ $row->archived_at === null ? 'Archive' : 'Restore' }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown></div>
                @endif
                <footer class="flex items-center justify-between gap-3 px-5 pb-4 text-xs text-muted-foreground">
                    <span class="min-w-0 truncate" title="Portfolio owner">{{ $owners->get($row->owner_id)?->name ?? 'Account #'.$row->owner_id }}</span>
                    <button type="button" wire:click="openPeople('{{ $row->id }}')" class="flex shrink-0 items-center gap-1.5 rounded px-1 py-1 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" aria-label="View people for {{ $row->name }}"><flux:icon.users class="size-4" /><span class="num">{{ $row->people_count }}</span></button>
                </footer>
            </article>
        @empty
            <div class="ppm-surface col-span-full py-14 text-center">
                <flux:icon.rectangle-stack class="mx-auto size-9 text-muted-foreground/50" />
                <h2 class="mt-4 font-semibold">{{ $search !== '' || $ownership !== 'ALL' || $contents !== 'ALL' ? 'No matching portfolios' : ($archived ? 'No archived portfolios' : 'Bring your clients and projects together') }}</h2>
                @if ($search !== '' || $ownership !== 'ALL' || $contents !== 'ALL')<flux:button class="mt-4" size="sm" variant="outline" wire:click="clearFilters">Clear filters</flux:button>
                @elseif (! $archived)<flux:button class="mt-4" size="sm" variant="primary" icon="plus" wire:click="openCreate">New portfolio</flux:button>
                @endif
            </div>
        @endforelse
    </section>
    @endif
    <x-kit.pagination :paginator="$rows" noun="portfolios" class="!border-0 !px-0" />

    @endif
    <flux:modal wire:model.self="editorOpen" class="w-full max-w-lg">
        <form wire:submit="savePortfolio" class="space-y-5">
            <flux:heading size="lg" level="2">{{ $portfolioId === null ? 'New portfolio' : 'Edit portfolio' }}</flux:heading>
            <flux:input wire:model="name" label="Portfolio name" maxlength="200" required autofocus autocomplete="off" />
            <flux:textarea wire:model="description" label="Description" badge="Optional" rows="3" maxlength="4000" />
            <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('editorOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">{{ $portfolioId === null ? 'Create portfolio' : 'Save' }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="membersOpen" class="ppm-members-dialog w-full max-w-lg">
        @if ($membersOpen)@include('livewire.projects.partials.portfolio-members')
        @endif
    </flux:modal>
@endif
</div>
