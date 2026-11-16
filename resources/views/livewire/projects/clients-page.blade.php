<div @class(['contents' => $toolsOnly, 'ppm-surface space-y-5 p-5' => $membersOnly, 'ppm-workspace space-y-6' => ! $membersOnly && ! $toolsOnly]) @if ($membersOnly) aria-label="Client members" @endif>
@if ($membersOnly)
    @include('livewire.projects.partials.client-members')
@else
    @if (! $toolsOnly)
    <x-work.breadcrumbs :portfolio="$selectedPortfolio" page="Clients" />
    <header class="ppm-page-header ppm-creation-header"><div class="min-w-0 flex-1"><p class="ppm-eyebrow">{{ $selectedPortfolio !== null ? 'Portfolio' : 'Client directory' }}</p><h1 class="ppm-heading break-words">{{ $selectedPortfolio->name ?? 'Clients' }}</h1></div>
        <div class="ppm-actions">
        @if ($selectedPortfolio?->can_manage)<flux:dropdown position="bottom" align="end"><flux:button variant="ghost" square icon="ellipsis-horizontal" aria-label="Portfolio actions" /><flux:menu><flux:menu.item icon="pencil-square" wire:click="$dispatch('edit-current-portfolio')">Edit portfolio</flux:menu.item></flux:menu></flux:dropdown>
        @endif
        <flux:button variant="primary" icon="plus" icon:class="hidden sm:block" wire:click="openCreate" :disabled="$portfolioUnavailable || $selectedPortfolio?->archived_at !== null">New client</flux:button></div>
        @if ($selectedPortfolio !== null)
        <div class="ppm-header-context space-y-3">
            @if ($selectedPortfolio->description)<div><x-work.description-preview :text="$selectedPortfolio->description" /></div>
            @endif
            <div class="flex flex-wrap items-center gap-5 text-xs text-muted-foreground"><span class="num">{{ $portfolioCounts['clients'] }} clients</span><a href="{{ route('projects.index', ['portfolio' => $selectedPortfolio->id, 'section' => 'projects']) }}" class="num hover:text-primary" wire:navigate>{{ $portfolioCounts['projects'] }} projects</a><button type="button" class="num inline-flex items-center gap-1.5 hover:text-primary" wire:click="$dispatch('open-current-portfolio-members')" aria-label="View portfolio members"><flux:icon.users class="size-4" />{{ $selectedPortfolio->people_count }} members</button></div>
        </div>
        @endif
    </header>
    <x-work.context-tabs :portfolio="$selectedPortfolio" active="clients" />
    @if ($selectedPortfolio?->archived_at !== null)
        <div class="flex flex-wrap items-center gap-2 text-sm"><span class="pill pill-muted">Archived</span><span class="text-muted-foreground">Restore this portfolio to add clients.</span><a href="{{ route('projects.portfolios', ['archived' => 1]) }}" class="font-medium text-primary hover:underline">Portfolios</a></div>
    @endif
    @if ($portfolioUnavailable)
        <div class="ppm-surface py-12 text-center"><h2 class="font-semibold">This portfolio is unavailable.</h2><flux:button class="mt-4" size="sm" variant="outline" wire:click="$set('portfolio', 'ALL')">Show my clients</flux:button></div>
    @else
    <div class="ppm-collection-toolbar" aria-label="Client controls"><flux:input size="sm" wire:model.live.debounce.250ms="search" placeholder="Find a client…" aria-label="Search clients" icon="magnifying-glass" class="ppm-search" />@include('livewire.projects.partials.collection-controls', ['kind' => 'clients'])</div>
    @if ($collectionView === 'list' && $rows->isNotEmpty())
        @include('livewire.projects.partials.container-list', ['kind' => 'clients'])
    @else
    <section class="ppm-library" aria-label="Your clients">
        @forelse ($rows as $row)
            <article class="ppm-surface ppm-project-card" wire:key="client-{{ $row->id }}">
                <a href="{{ route('projects.index', ['client' => $row->id, 'portfolio' => $portfolio]) }}" aria-label="Open {{ $row->name }} projects" class="focus:outline-none">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-primary-soft text-primary"><flux:icon.building-office-2 class="size-5" /></span>
                    <div class="space-y-1 pr-6"><h2 class="line-clamp-2 break-words" title="{{ $row->name }}">{{ $row->name }}</h2>
                        @if ($row->portfolio_name !== null)<p class="truncate text-xs text-muted-foreground" title="{{ $row->portfolio_name }}">{{ $row->portfolio_name }}</p>
                        @endif
                        @if ($row->description)<p class="line-clamp-2 text-sm text-muted-foreground">{{ $row->description }}</p>
                        @endif
                        <p class="num text-xs text-muted-foreground">{{ $projectCounts[$row->id] ?? 0 }} {{ ($projectCounts[$row->id] ?? 0) === 1 ? 'project' : 'projects' }}</p></div>
                    <x-work.progress :progress="$totalsByClient[$row->id]->progress()" />
                </a>
                @if ($row->can_manage)
                    <div class="ppm-client-tools"><flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Actions for {{ $row->name }}" />
                        <flux:menu>
                            <flux:menu.item icon="rectangle-stack" wire:click="openOrganize('{{ $row->id }}')">Move client</flux:menu.item>
                            <flux:menu.item icon="pencil-square" wire:click="openRename('{{ $row->id }}')">Edit client</flux:menu.item>
                            <flux:menu.item icon="users" wire:click="openClient('{{ $row->id }}')">Manage access</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown></div>
                @endif
                <footer class="flex items-center justify-between gap-3 px-5 pb-4 text-xs text-muted-foreground">
                    <span class="min-w-0 truncate" title="Client owner">{{ $owners->get($row->owner_id)?->name ?? 'Account #'.$row->owner_id }}</span>
                    <button type="button" wire:click="openClient('{{ $row->id }}')" class="flex shrink-0 items-center gap-1.5 rounded px-1 py-1 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" aria-label="View people for {{ $row->name }}"><flux:icon.users class="size-4" /><span class="num">{{ $row->people_count }}</span></button>
                </footer>
            </article>
        @empty
            <div class="ppm-surface col-span-full py-12 text-center">
                <flux:icon.building-office-2 class="mx-auto size-9 text-muted-foreground/50" />
                <h2 class="mt-4 font-semibold">{{ $search !== '' || $ownership !== 'ALL' || $contents !== 'ALL' ? 'No clients match these filters.' : 'No clients yet.' }}</h2>
                @if ($search !== '' || $ownership !== 'ALL' || $contents !== 'ALL')<flux:button class="mt-4" size="sm" variant="outline" wire:click="clearFilters">Clear filters</flux:button>
                @else<flux:button class="mt-4" size="sm" variant="primary" icon="plus" wire:click="openCreate" :disabled="$portfolioUnavailable || $selectedPortfolio?->archived_at !== null">New client</flux:button>
@endif
            </div>
        @endforelse
    </section>
    @endif
    <x-kit.pagination :paginator="$rows" noun="clients" class="!border-0 !px-0" />
    @endif

    <livewire:projects.new-client :portfolio="$portfolio" :key="'client-create-'.$portfolio" />
    @if ($selectedPortfolio !== null)<livewire:projects.portfolios-page :tools-for="$selectedPortfolio->id" :key="'portfolio-tools-'.$selectedPortfolio->id" />
    @endif
    @endif
    <flux:modal wire:model.self="editorOpen" class="w-full max-w-md">
        <form wire:submit="saveClient" class="space-y-5">
            <flux:heading size="lg" level="2">Edit client</flux:heading>
            <flux:input wire:model="name" label="Client name" maxlength="200" required autofocus autocomplete="off" />
            <flux:textarea wire:model="description" label="Description" badge="Optional" rows="3" maxlength="4000" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('editorOpen', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="organizeOpen" class="w-full max-w-md">
        @if ($organizingClient !== null)
            <form wire:submit="organizeClient" class="space-y-5">
                <flux:heading size="lg" level="2" class="break-words pr-8">Move {{ $organizingClient->name }}</flux:heading>
                <x-kit.select wire:model="destination" label="Destination portfolio" searchable>
                    <flux:select.option value="NONE">Without portfolio</flux:select.option>
                    @if ($expectedPortfolioId !== null && ! $contributablePortfolios->contains('id', $expectedPortfolioId))
                        <flux:select.option value="{{ $expectedPortfolioId }}">{{ $organizingClient->portfolio_name ?? 'Current portfolio unavailable' }}</flux:select.option>
                    @endif
                    @foreach ($contributablePortfolios as $option)<flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                <flux:error name="portfolio" />
                <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('organizeOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Move client</flux:button></div>
            </form>
        @else
            <flux:heading size="lg" level="2">This client is no longer available.</flux:heading>
        @endif
    </flux:modal>

    <flux:modal wire:model.self="membersOpen" class="ppm-members-dialog w-full max-w-lg">
        @if ($membersOpen)@include('livewire.projects.partials.client-members')
        @endif
    </flux:modal>
@endif
</div>
