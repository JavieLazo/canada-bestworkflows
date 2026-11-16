@php
    use App\Domain\Projects\ProjectPolicy;
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
@endphp
<div class="ppm-workspace space-y-5">
    @if ($project === null)
        <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"><flux:icon.arrow-left class="size-4" />Projects</a>
        <div class="ppm-surface p-12 text-center"><h1 class="font-semibold">Project not found.</h1><p class="mt-2 text-sm text-muted-foreground">This project may have been removed.</p></div>
    @else
        @php
            $siteName = $sites->firstWhere('id', $project->site_id)?->name ?? '—';
            $accountName = fn ($accountId) => $accountId === null ? '—' : ($accounts->firstWhere('id', $accountId)?->name ?? '—');
        @endphp
        <x-work.breadcrumbs :portfolio="$contextPortfolio" :client="$client" :project="$project" />

        <header class="flex flex-col items-start justify-between gap-4 sm:flex-row">
            <div class="min-w-0 w-full flex-1 space-y-3">
                <div class="flex flex-wrap items-center gap-3"><h1 class="ppm-heading break-words">{{ $project->name }}</h1><span class="{{ ProjectPolicy::statusPill($project->status) }}" aria-label="Project status: {{ ProjectPolicy::statusLabel($project->status) }}">{{ ProjectPolicy::statusLabel($project->status) }}</span></div>
                @if ($project->description)<x-work.description-preview :text="$project->description" />
                @elseif ($runsProject)<button type="button" class="text-sm text-muted-foreground hover:text-primary" wire:click="openEdit">Add a description…</button>
                @endif
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground">
                    @if ($client !== null)<a class="min-w-0 rounded hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" href="{{ route('projects.index', ['client' => $client->id, 'portfolio' => $contextPortfolio?->id ?? 'ALL']) }}" wire:navigate aria-label="Open client {{ $client->name }}"><x-work.project-context :client="$client" /></a>
                    @else<x-work.project-context />
                    @endif
                    <button type="button" class="rounded hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" wire:click="openProjectDetails" aria-label="View project dates"><x-work.project-deadline :project="$project" :today="$today" /></button>
                    <button type="button" class="num inline-flex items-center gap-1.5 rounded hover:text-primary" wire:click="openPeople" aria-label="View project members"><flux:icon.users class="size-4" />{{ $peopleCount }} {{ $peopleCount === 1 ? 'member' : 'members' }}</button>
                    <x-work.progress :progress="$progress" class="w-48" />
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Project actions" />
                    <flux:menu>
                        <flux:menu.item icon="arrow-down-tray" href="{{ $tasksExportUrl }}">Export CSV</flux:menu.item>
                        @if ($canEditWork)
                            <flux:menu.item icon="arrow-up-tray" wire:click="$dispatch('open-csv-import', { table: 'projects.tasks', context: '{{ $project->id }}' })">Import CSV</flux:menu.item>
                        @endif
                        <flux:menu.item icon="information-circle" wire:click="openProjectDetails">Project details</flux:menu.item>
                        @if ($canManage)<flux:menu.item variant="danger" icon="trash" wire:click="$set('deleteConfirmOpen', true)">Delete project</flux:menu.item>
@endif
                    </flux:menu>
                </flux:dropdown>
                @if ($canCreateTasks || $canQuickIndicator || $canQuickExpense)
                    <div class="flex items-center gap-1">
                        @if ($canCreateTasks)<flux:button variant="primary" icon="plus" wire:click="openTaskCreation">{{ $parent === 'ALL' ? 'New task' : 'New subtask' }}</flux:button>
                        @endif
                        <flux:dropdown position="bottom" align="end"><flux:button variant="primary" square icon="chevron-down" aria-label="Quick create" /><flux:menu>
                            @if ($canCreateTasks)<flux:menu.item icon="rectangle-group" wire:click="openGroupEditor">New activity</flux:menu.item>
                            @endif
                            @if ($canQuickIndicator)<flux:menu.item icon="chart-bar" wire:click="quickCreate('indicator')">New indicator</flux:menu.item>
                            @endif
                            @if ($canQuickExpense)<flux:menu.item icon="banknotes" wire:click="quickCreate('expense')">Paid expense</flux:menu.item>
                            @endif
                        </flux:menu></flux:dropdown>
                    </div>
                @endif
            </div>
        </header>

        @php($workView = in_array($taskView, ['board', 'list', 'gantt'], true))
        <div class="ppm-section-toolbar">
            <x-work.tabs aria-label="Project sections">
                @foreach (['overview' => 'Overview', 'tasks' => 'Tasks', 'capacity' => 'Availability', 'reporting' => 'Reporting', 'finance' => 'Finance', 'activity' => 'Audit log'] as $value => $label)
                    @php($sectionAction = $value === 'tasks' ? 'openTasks' : "\$set('taskView', '$value')")
                    @if (($value !== 'finance' || $canReadFinance) && ($value !== 'reporting' || $canReadReporting))<x-work.tab :active="$value === 'tasks' ? $workView : $taskView === $value" wire:click="{{ $sectionAction }}">{{ $label }}</x-work.tab>
                    @endif
                @endforeach
            </x-work.tabs>
            @if ($workView)<span class="num text-xs text-muted-foreground">{{ $visibleCount }} {{ $visibleCount === 1 ? 'task' : 'tasks' }}</span>
            @endif
        </div>

        @if ($taskView === 'overview')
            @include('livewire.projects.partials.project-overview')
        @elseif ($taskView === 'capacity')
            <livewire:projects.capacity-workspace :project-id="$project->id" :key="'capacity-'.$project->id" />
        @elseif ($taskView === 'finance')
            <livewire:projects.project-finance-workspace :project-id="$project->id" :key="'finance-'.$project->id" />
        @elseif ($taskView === 'reporting')
            <livewire:projects.project-reporting-workspace :project-id="$project->id" :key="'reporting-'.$project->id" />
        @elseif ($taskView === 'activity')
            @include('livewire.projects.partials.project-activity')
        @else
            @if ($parent !== 'ALL')
                <div class="flex min-w-0 items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3">
                    <span class="min-w-0 truncate text-sm"><span class="text-muted-foreground">Subtasks{{ $focusedParent !== null ? ' of' : '' }}</span> {{ $focusedParent?->title }}</span>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearParent">All tasks</flux:button>
                </div>
            @endif
            <div class="ppm-collection-toolbar" aria-label="Task controls">
                <flux:input size="sm" icon="magnifying-glass" placeholder="Find a task…" aria-label="Search tasks" class="ppm-search" wire:model.live.debounce.250ms="search" />
                <div class="ppm-actions">
                    @if (in_array($taskView, ['list', 'gantt'], true) && $group === 'ALL')
                        <flux:button size="sm" :variant="$groupList ? 'filled' : 'ghost'" icon="rectangle-group" wire:click="$toggle('groupList')" aria-label="Group tasks by activity" aria-pressed="{{ $groupList ? 'true' : 'false' }}">By activity</flux:button>
                    @endif
                    <x-work.filters id="project-task-filters" label="Task filters" :count="$activeFilterCount - (trim($search) === '' ? 0 : 1)">
                        @include('livewire.projects.partials.task-groups')
                        <x-kit.select label="Status" aria-label="Filter by status" wire:model.live="status">
                                                <flux:select.option value="ALL">All statuses</flux:select.option>
                                                @foreach (TaskPolicy::STATUSES as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                                            </x-kit.select>
                                            <x-kit.select label="Assignee" aria-label="Filter by assignee" wire:model.live="assignee" searchable>
                                                <flux:select.option value="ALL">All assignees</flux:select.option>
                                                <flux:select.option value="UNASSIGNED">Unassigned</flux:select.option>
                                                @foreach ($holders as $holder)<flux:select.option value="{{ $holder->id }}">{{ ProjectsData::employeeName($holder) }}</flux:select.option>
                        @endforeach
                                            </x-kit.select>
                                            <x-kit.select label="Priority" aria-label="Filter by priority" wire:model.live="priority">
                                                <flux:select.option value="ALL">All priorities</flux:select.option>
                                                @foreach (TaskPolicy::PRIORITIES as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                                            </x-kit.select>
                    </x-work.filters>
                <div class="ppm-view-switch" role="group" aria-label="Task view">
                    @foreach (['board' => ['Board', 'view-columns'], 'list' => ['List', 'list-bullet'], 'gantt' => ['Gantt', 'calendar-days']] as $layout => [$caption, $icon])<flux:button size="sm" :icon="$icon" :variant="$taskView === $layout ? 'filled' : 'ghost'" wire:click="$set('taskView', '{{ $layout }}')" aria-pressed="{{ $taskView === $layout ? 'true' : 'false' }}">{{ $caption }}</flux:button>
                    @endforeach
                </div>
                </div>
            </div>
            @include('livewire.projects.partials.work-queues', ['allLabel' => 'All tasks'])



            @if ($taskView === 'board')
                @include('livewire.projects.partials.task-board')
            @elseif ($taskView === 'gantt')
                <x-work.task-timeline :rows="$rows" :timeline="$timeline" :today="$today" :editable="$dateAuthority" :can-change-due="$canEditWork" :grouped="$groupList && $group === 'ALL'" :sort-key="$sortKey" />
            @else
                <div class="ppm-surface overflow-hidden" wire:key="task-list-{{ $project->id }}">
                    @include('livewire.projects.partials.task-list')
                </div>
            @endif
        @endif

        @if ($canEditWork)
            @include('livewire.projects.partials.dialog-task-add')
        @endif
        @if ($canEditWork)
            @include('livewire.projects.partials.dialog-task-group')
            <livewire:kit.csv-import table="projects.tasks" :context="$project->id" :template="route('projects.tasks.export', ['project' => $project->id, 'template' => 1])" :show-trigger="false" :key="'import-tasks-'.$project->id" />
        @endif
        @include('livewire.projects.partials.dialog-task-schedule')
        @include('livewire.projects.partials.dialog-project-details')
        @include('livewire.projects.partials.dialog-project-member')
        @include('livewire.projects.partials.dialog-project-delete')
        @include('livewire.projects.partials.dialog-task-review')
        <x-social.lightbox />
        <x-social.confirm />
    @endif
</div>
