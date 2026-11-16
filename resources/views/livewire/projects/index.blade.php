@php
    use App\Domain\Projects\ProjectPolicy;
@endphp
<div class="ppm-workspace space-y-6">
    <x-work.breadcrumbs :portfolio="$contextPortfolio" :client="$selectedClient" :page="$sectionTitle" />
    @php
        $projectCreation = $canCreateInternal || $creatableClients->isNotEmpty();
        $clientShortcut = $section === 'overview' && $selectedClient === null && $canAddClient;
        $portfolioShortcut = $clientShortcut && $selectedPortfolio === null;
        $showCreation = in_array($section, ['overview', 'projects'], true) && ! $portfolioUnavailable && ! $clientUnavailable && ($projectCreation || $clientShortcut);
    @endphp
    <header class="ppm-page-header ppm-creation-header">
        <div class="min-w-0 flex-1"><p class="ppm-eyebrow">{{ $selectedClient !== null ? 'Client' : ($selectedPortfolio !== null ? 'Portfolio' : ($portfolio === 'NONE' ? 'Unorganized work' : 'All visible work')) }}</p><h1 class="ppm-heading break-words">{{ $selectedClient->name ?? $selectedPortfolio->name ?? $sectionTitle }}</h1></div>
        @if ($section !== 'capacity')
        <div class="ppm-actions">
            <flux:dropdown position="bottom" align="end"><flux:button variant="ghost" square icon="ellipsis-horizontal" :aria-label="$selectedClient !== null ? 'Client actions' : ($selectedPortfolio !== null ? 'Portfolio actions' : 'Project tools')" /><flux:menu>
                @if ($selectedClient?->can_manage)
                    <flux:menu.item icon="pencil-square" wire:click="$dispatch('edit-current-client')">Edit client</flux:menu.item>
                    <flux:menu.item icon="rectangle-stack" wire:click="$dispatch('move-current-client')">Move client</flux:menu.item>
                @elseif ($selectedClient === null && $selectedPortfolio?->can_manage)
                    <flux:menu.item icon="pencil-square" wire:click="$dispatch('edit-current-portfolio')">Edit portfolio</flux:menu.item>
                @endif
                <flux:menu.item icon="arrow-down-tray" href="{{ $exportUrl }}">Export CSV</flux:menu.item>
                @if ($canCreateInternal)<flux:menu.item icon="arrow-up-tray" wire:click="$dispatch('open-csv-import', { table: 'projects' })">Import CSV</flux:menu.item>
                @endif
            </flux:menu></flux:dropdown>
            @if ($showCreation)
                <div class="flex items-center gap-1" role="group" aria-label="Create project work">
                    @if ($projectCreation)<flux:button variant="primary" icon="plus" icon:class="hidden sm:block" wire:click="openCreateProject">New project</flux:button>
                    @else<flux:button variant="primary" icon="plus" icon:class="hidden sm:block" wire:click="$dispatch('new-project-client')">New client</flux:button>
                    @endif
                    @if (($clientShortcut && $projectCreation) || $portfolioShortcut)
                        <flux:dropdown position="bottom" align="end"><flux:button variant="primary" square icon="chevron-down" aria-label="More creation actions" /><flux:menu>
                            @if ($clientShortcut && $projectCreation)<flux:menu.item icon="building-office-2" wire:click="$dispatch('new-project-client')">New client</flux:menu.item>
                            @endif
                            @if ($portfolioShortcut)<flux:menu.item icon="rectangle-stack" href="{{ route('projects.portfolios', ['new' => 1]) }}" wire:navigate>New portfolio</flux:menu.item>
                            @endif
                        </flux:menu></flux:dropdown>
                    @endif
                </div>
            @endif
        </div>
        @endif
        @if ($selectedClient !== null || $selectedPortfolio !== null)
        <div class="ppm-header-context space-y-3">
            @if (($selectedClient !== null ? $selectedClient->description : $selectedPortfolio?->description) !== null)<div><x-work.description-preview :text="$selectedClient !== null ? $selectedClient->description : $selectedPortfolio->description" /></div>
            @endif
            @if ($selectedClient !== null || $selectedPortfolio !== null)
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground">
                    @if ($selectedClient === null)<a href="{{ route('projects.clients', ['portfolio' => $selectedPortfolio->id]) }}" class="num hover:text-primary" wire:navigate>{{ $contextCounts['clients'] }} clients</a>
                    @endif
                    <button type="button" class="num hover:text-primary" wire:click="$set('section', 'projects')">{{ $contextCounts['projects'] }} projects</button>
                    <button type="button" class="num inline-flex items-center gap-1.5 hover:text-primary" wire:click="$dispatch('{{ $selectedClient !== null ? 'open-current-client-members' : 'open-current-portfolio-members' }}')" aria-label="View {{ $selectedClient !== null ? 'client' : 'portfolio' }} members"><flux:icon.users class="size-4" />{{ $selectedClient->people_count ?? $selectedPortfolio->people_count }} members</button>
                </div>
            @endif
            @if ($selectedPortfolio?->archived_at !== null)<span class="pill pill-muted mt-2">Archived</span>
            @endif
        </div>
        @endif
    </header>
    <x-work.context-tabs :portfolio="$contextPortfolio" :client="$selectedClient" :active="$section" />
    @if ($portfolioUnavailable)
        <div class="ppm-surface py-12 text-center"><h2 class="font-semibold">This portfolio is unavailable.</h2><flux:button class="mt-4" size="sm" variant="outline" wire:click="$set('portfolio', 'ALL')">Show my work</flux:button></div>
    @elseif ($clientUnavailable)
        <div class="ppm-surface p-8 text-center"><h2 class="font-semibold">This client is unavailable in this context.</h2><a href="{{ route('projects.clients') }}" class="mt-3 inline-block text-sm text-primary" wire:navigate>Browse clients</a></div>
    @elseif ($section === 'members')
        @if ($selectedClient !== null)
            <livewire:projects.clients-page :members-for="$selectedClient->id" :key="'client-members-'.$selectedClient->id" />
        @elseif ($selectedPortfolio !== null)
            <livewire:projects.portfolios-page :members-for="$selectedPortfolio->id" :key="'portfolio-members-'.$selectedPortfolio->id" />
        @endif
    @elseif ($section === 'capacity')
        <livewire:projects.capacity-workspace :portfolio="$portfolio" :client="$client" :key="'capacity-'.$portfolio.'-'.$client" />
    @elseif ($section === 'overview')
        {{-- All visible work gets the new overview; portfolios and clients keep theirs for now. --}}
        @if ($selectedClient === null && $selectedPortfolio === null && $visibleCount > 0)
            <livewire:projects.general-overview :portfolio="$portfolio" :key="'general-overview-'.$portfolio" />
        @else
            @include('livewire.projects.partials.module-overview')
        @endif
    @else
    <section class="space-y-4" aria-label="Project library">
        <div class="ppm-collection-toolbar" aria-label="Project controls">
            <flux:input size="sm" icon="magnifying-glass" placeholder="Find a project…" aria-label="Search projects" wire:model.live.debounce.250ms="search" class="ppm-search" />
            <div class="ppm-actions">
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" variant="ghost" icon="arrows-up-down" aria-label="Sort projects">Sort</flux:button>
                    <flux:menu>
                        @foreach (['code' => 'Code', 'name' => 'Name', 'manager' => 'Project lead', 'status' => 'Status'] as $field => $label)
                            <flux:menu.item wire:click="sortBy('{{ $field }}')" :icon="$sortKey === $field ? ($sortDir === 'asc' ? 'arrow-up' : 'arrow-down') : null">{{ $label }}</flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
                <x-work.filters id="project-library-filters" label="Project filters" :count="$activeFilterCount - (trim($search) === '' ? 0 : 1) - ($queue === 'ALL' ? 0 : 1)">
                    <x-kit.select label="Status" aria-label="Filter by status" wire:model.live="status">
                        <flux:select.option value="ALL">All statuses</flux:select.option>
                        @foreach (ProjectPolicy::STATUSES as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                    <x-kit.select label="Site" aria-label="Filter by site" wire:model.live="site">
                        <flux:select.option value="ALL">All sites</flux:select.option>
                        @foreach ($sites as $siteRow)
                            <flux:select.option value="{{ $siteRow->id }}">{{ $siteRow->name }}</flux:select.option>
                        @endforeach
                    </x-kit.select>
                    <x-kit.select label="Project lead" aria-label="Filter by project lead" wire:model.live="manager" searchable>
                        <flux:select.option value="ALL">All project leads</flux:select.option>
                        @foreach ($accounts as $account)
                            @if ($account->is_active)
                                <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                            @endif
                        @endforeach
                    </x-kit.select>
                </x-work.filters>
                <div class="ppm-view-switch" role="group" aria-label="Project view">
                    <flux:button size="sm" square icon="squares-2x2" :variant="$projectView === 'cards' ? 'filled' : 'ghost'" wire:click="$set('projectView', 'cards')" aria-label="Project cards" aria-pressed="{{ $projectView === 'cards' ? 'true' : 'false' }}" />
                    <flux:button size="sm" square icon="list-bullet" :variant="$projectView === 'list' ? 'filled' : 'ghost'" wire:click="$set('projectView', 'list')" aria-label="Project list" aria-pressed="{{ $projectView === 'list' ? 'true' : 'false' }}" />
                </div>
            </div>
        </div>

        @include('livewire.projects.partials.work-queues', ['allLabel' => 'All projects'])





        @if ($visibleCount === 0)
            <div class="ppm-surface py-12 text-center">
                <flux:icon.folder class="mx-auto size-9 text-muted-foreground/50" />
                <h2 class="mt-4 font-semibold">{{ $activeFilterCount > 0 ? 'No matching projects' : 'Your next project starts here' }}</h2>
                @if ($activeFilterCount > 0)
                    <flux:button class="mt-4" size="sm" variant="outline" wire:click="clearFilters">Clear filters</flux:button>
                @elseif ($canCreateInternal || $creatableClients->isNotEmpty())
                    <flux:button class="mt-4" size="sm" variant="primary" icon="plus" wire:click="openCreateProject">New project</flux:button>
                @elseif ($selectedPortfolio?->archived_at === null)
                    <flux:button class="mt-4" size="sm" variant="primary" wire:click="$dispatch('new-project-client')">Create a client</flux:button>
                @endif
            </div>
        @elseif ($projectView === 'cards')
            @include('livewire.projects.partials.project-cards')
        @else
            <div class="ppm-surface overflow-hidden">
@include('livewire.projects.partials.project-list')</div>
        @endif

        <x-kit.pagination :paginator="$rows" noun="projects" class="!border-0 !px-0" />
    </section>
    @endif

    @if ($canCreateInternal)
        <livewire:kit.csv-import table="projects" :template="route('projects.export', ['template' => 1])" :show-trigger="false" key="import-projects" />
    @endif

    <livewire:projects.new-client :portfolio="$portfolio" :key="'new-client-'.$portfolio" />
    @if ($selectedClient !== null)
        <livewire:projects.clients-page :tools-for="$selectedClient->id" :key="'client-tools-'.$selectedClient->id" />
    @elseif ($selectedClient === null && $selectedPortfolio !== null)
        <livewire:projects.portfolios-page :tools-for="$selectedPortfolio->id" :key="'portfolio-tools-'.$selectedPortfolio->id" />
    @endif
    @include('livewire.projects.partials.dialog-project-add')
</div>
