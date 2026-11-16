@php
    use App\Domain\Projects\ProjectPolicy;
    use App\Livewire\Projects\ProjectsData;
    $work = $overview['totals'];
    $projectStates = $overview['projects'];
    $projectUrl = fn (array $filters = []) => route('projects.index', [...$scopeParameters, 'section' => 'projects', ...$filters]);
    $stateColors = ['PLANNING' => 'var(--muted-foreground)', 'ACTIVE' => 'var(--primary)', 'ON_HOLD' => 'var(--warning)', 'COMPLETED' => 'var(--success)', 'CANCELLED' => 'var(--danger)'];
    $chartItems = [];
    foreach (ProjectPolicy::STATUSES as $state => $label) {
        $chartItems[] = ['label' => $label, 'value' => $projectStates[$state] ?? 0, 'color' => $stateColors[$state] ?? 'var(--muted-foreground)', 'href' => $projectUrl(['status' => $state])];
    }
@endphp
<section class="space-y-5" aria-label="Visible work overview">
    @if ($visibleCount === 0)
        <div class="ppm-surface flex flex-col items-center gap-4 p-10 text-center"><flux:icon.folder class="size-9 text-muted-foreground/50" /><h2 class="font-semibold">Your next project starts here</h2>
            @if ($canCreateInternal || $creatableClients->isNotEmpty())<flux:button size="sm" variant="primary" icon="plus" wire:click="openCreateProject">New project</flux:button>
            @elseif ($canAddClient)<flux:button size="sm" variant="primary" icon="plus" wire:click="$dispatch('new-project-client')">Create a client</flux:button>
            @endif
        </div>
    @else
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Work attention summary">
            @foreach ([['Active projects', $activeProjects, $visibleCount, $projectUrl(['status' => 'ACTIVE']), 'var(--primary)'], ['Awaiting review', $work->review, $work->total, $projectUrl(['queue' => 'REVIEW']), 'var(--warning)'], ['Overdue tasks', $work->overdue, $work->total, $projectUrl(['queue' => 'OVERDUE']), 'var(--danger)'], ['Unassigned tasks', $work->unassigned, $work->total, $projectUrl(['queue' => 'UNASSIGNED']), 'var(--muted-foreground)']] as [$label, $number, $denominator, $href, $color])
                <a href="{{ $href }}" class="ppm-surface min-w-0 space-y-3 p-4 hover:border-primary/40 focus-visible:outline-2 focus-visible:outline-primary" wire:navigate><span class="flex items-center justify-between gap-2 text-xs text-muted-foreground">{{ $label }}<flux:icon.arrow-up-right class="size-3 shrink-0" /></span><strong class="num block text-3xl font-semibold tracking-tight" style="color: {{ $color }}">{{ $number }}</strong><span class="block h-1 overflow-hidden rounded-full bg-muted" aria-hidden="true"><span class="block h-full rounded-full" style="background: {{ $color }}; width: {{ min(100, 100 * $number / max(1, $denominator)) }}%"></span></span></a>
            @endforeach
        </div>
        <div class="ppm-dashboard">
            @if ($overview['attention']->isNotEmpty())
                <section class="ppm-surface ppm-chart-card" aria-label="Projects needing attention"><header><h2 class="ppm-section-title">Needs attention</h2><a href="{{ $projectUrl() }}" class="ppm-card-link" wire:navigate>All projects <span aria-hidden="true">↗</span></a></header>
                    <div class="ppm-dashboard-list">@foreach ($overview['attention']->take(4) as $row)@php($totals = $totalsByProject[$row->id])<a href="{{ route('projects.show', $row->id) }}" wire:navigate><span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $totals->overdue > 0 ? 'bg-danger-soft text-danger' : 'bg-warning/10 text-warning' }}"><flux:icon :name="$totals->overdue > 0 ? 'clock' : 'inbox'" class="size-4" /></span><span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium" title="{{ $row->name }}">{{ $row->name }}</span><span class="mt-1 block truncate text-xs text-muted-foreground">{{ $row->client_name ?? 'Internal' }}</span></span><span class="num shrink-0 text-xs {{ $totals->overdue > 0 ? 'text-danger' : 'text-muted-foreground' }}">{{ $totals->overdue > 0 ? $totals->overdue.' overdue' : ($totals->review > 0 ? $totals->review.' review' : $totals->unassigned.' unassigned') }}</span><flux:icon.chevron-right class="size-3 text-muted-foreground" /></a>
                    @endforeach
                    </div>
                </section>
            @endif
            @if ($overview['deadlines']->isNotEmpty())
                <section class="ppm-surface ppm-chart-card" aria-label="Project deadlines"><header><h2 class="ppm-section-title">Deadlines</h2><a href="{{ $projectUrl() }}" class="ppm-card-link" wire:navigate>All projects <span aria-hidden="true">↗</span></a></header>
                    <div class="ppm-dashboard-list">@foreach ($overview['deadlines']->take(4) as $row)@php($late = $row->due_date->format('Y-m-d') < $today)<a href="{{ route('projects.show', $row->id) }}" wire:navigate><span class="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg {{ $late ? 'bg-danger-soft text-danger' : 'bg-muted text-foreground' }}"><span class="text-[9px] font-medium uppercase">{{ $row->due_date->format('M') }}</span><span class="num text-base font-semibold leading-none">{{ $row->due_date->format('j') }}</span></span><span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium" title="{{ $row->name }}">{{ $row->name }}</span><span class="mt-1 block truncate text-xs text-muted-foreground">{{ $row->client_name ?? 'Internal' }}</span></span><span class="num text-xs {{ $late ? 'text-danger' : 'text-muted-foreground' }}">{{ $late ? 'Overdue' : ProjectsData::fmtDate($row->due_date) }}</span></a>
                    @endforeach
                    </div>
                </section>
            @endif
            <section class="ppm-surface ppm-chart-card" aria-label="Project status breakdown"><header><h2 class="ppm-section-title">Delivery overview</h2></header><x-work.ring-chart :items="$chartItems" :value="$visibleCount" label="projects" />
                <div class="space-y-2 border-t border-border pt-4"><h3 class="text-xs text-muted-foreground">Work completed</h3><a href="{{ $projectUrl() }}" class="block rounded focus-visible:outline-2 focus-visible:outline-primary" aria-label="View project work progress" wire:navigate><x-work.progress :progress="$work->progress()" /></a></div>
            </section>
            @if ($selectedClient === null)
                @include('livewire.projects.partials.client-delivery')
            @endif
            <livewire:projects.capacity-overview :portfolio="$portfolio" :client="$client" :key="'capacity-overview-'.$portfolio.'-'.$client" />
            <livewire:projects.reporting-overview :portfolio="$portfolio" :client="$client" :key="'reporting-overview-'.$portfolio.'-'.$client" />
            <livewire:projects.finance-overview :portfolio="$portfolio" :client="$client" :key="'finance-overview-'.$portfolio.'-'.$client" />
        </div>
    @endif
</section>
