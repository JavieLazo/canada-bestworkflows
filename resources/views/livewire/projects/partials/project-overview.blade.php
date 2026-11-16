@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
    $stateColors = ['PENDING' => 'var(--muted-foreground)', 'IN_PROGRESS' => 'var(--primary)', 'SUBMITTED' => 'var(--warning)', 'RETURNED' => 'var(--danger)', 'COMPLETED' => 'var(--success)', 'CANCELLED' => 'var(--muted-foreground)'];
    $chartItems = [];
    foreach (TaskPolicy::STATUSES as $state => $label) {
        $chartItems[] = ['label' => $label, 'value' => $delivery['states'][$state] ?? 0, 'color' => $stateColors[$state], 'href' => route('projects.show', ['project' => $project->id, 'view' => 'list', 'status' => $state])];
    }
@endphp
<section class="space-y-5" aria-label="Project overview">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Project attention summary">
        @foreach (['REVIEW' => ['Awaiting review', $reviewCount, 'var(--warning)'], 'OVERDUE' => ['Overdue tasks', $overdueCount, 'var(--danger)'], 'DUE_SOON' => ['Due soon', $dueSoonCount, 'var(--primary)'], 'UNASSIGNED' => ['Unassigned tasks', $unassignedCount, 'var(--muted-foreground)']] as $queueValue => [$label, $number, $color])
            <button type="button" class="ppm-surface min-w-0 space-y-3 p-4 text-left hover:border-primary/40 focus-visible:outline-2 focus-visible:outline-primary" wire:click="setQueue('{{ $queueValue }}')"><span class="flex items-center justify-between gap-2 text-xs text-muted-foreground">{{ $label }}<flux:icon.arrow-up-right class="size-3 shrink-0" /></span><strong class="num block text-3xl font-semibold tracking-tight" style="color: {{ $color }}">{{ $number }}</strong><span class="block h-1 overflow-hidden rounded-full bg-muted" aria-hidden="true"><span class="block h-full rounded-full" style="background: {{ $color }}; width: {{ min(100, 100 * $number / max(1, $taskCount)) }}%"></span></span></button>
        @endforeach
    </div>
    <div class="ppm-dashboard" aria-label="Project summaries">
        <section class="ppm-surface ppm-chart-card" aria-label="Task delivery">
            <header><h2 class="ppm-section-title">Task delivery</h2><a href="{{ route('projects.show', ['project' => $project->id, 'view' => 'list']) }}" class="ppm-card-link" wire:navigate>All tasks <span aria-hidden="true">↗</span></a></header>
            @if ($taskCount > 0)<x-work.ring-chart :items="$chartItems" :value="$taskCount" label="tasks" />
            @else
                <div class="ppm-dashboard-empty"><flux:icon.clipboard-document-list class="size-8 shrink-0" /><span>No tasks yet.</span></div>
                @if ($canCreateTasks)<flux:button size="sm" variant="outline" icon="plus" wire:click="openTaskCreation">Add task</flux:button>
                @endif
            @endif
        </section>
        <section class="ppm-surface ppm-chart-card" aria-label="Task deadlines">
            <header><h2 class="ppm-section-title">Upcoming work</h2><button type="button" class="ppm-card-link" wire:click="setQueue('DUE_SOON')">Due soon <span aria-hidden="true">↗</span></button></header>
            <div class="ppm-dashboard-list">
                @forelse ($delivery['deadlines'] as $task)
                    @php($late = $task->due_date->format('Y-m-d') < $today)
                    <button type="button" class="flex min-w-0 items-center gap-3 rounded-lg py-3 text-left hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" wire:click="openTask('{{ $task->id }}')"><span class="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg {{ $late ? 'bg-danger-soft text-danger' : 'bg-muted text-foreground' }}"><span class="text-[9px] font-medium uppercase">{{ $task->due_date->format('M') }}</span><span class="num text-base font-semibold leading-none">{{ $task->due_date->format('j') }}</span></span><span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium" title="{{ $task->title }}">{{ $task->title }}</span><span class="num mt-1 block text-xs text-muted-foreground">{{ $task->code }}</span></span><span class="num shrink-0 text-xs {{ $late ? 'text-danger' : 'text-muted-foreground' }}">{{ $late ? 'Overdue' : ProjectsData::fmtDate($task->due_date) }}</span></button>
                @empty
                    <div class="ppm-dashboard-empty"><flux:icon.calendar-days class="size-8 shrink-0" /><span>No scheduled open tasks.</span></div>
                @endforelse
            </div>
        </section>
        <livewire:projects.capacity-overview :project-id="$project->id" :key="'project-capacity-overview-'.$project->id" />
        @if ($canReadReporting)
            <livewire:projects.reporting-overview :project-id="$project->id" :key="'project-reporting-overview-'.$project->id" />
        @endif
        @if ($canReadFinance)
            <livewire:projects.finance-overview :project-id="$project->id" :key="'project-finance-overview-'.$project->id" />
        @endif
    </div>
</section>
