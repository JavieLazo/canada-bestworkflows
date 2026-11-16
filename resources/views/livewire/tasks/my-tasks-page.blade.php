@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
    use App\Modules\WorkManagement\Domain\Tasks\TaskInbox;
@endphp
<div class="ppm-workspace ppm-task-explorer space-y-4" x-data="{ collapsed: {} }">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3"><h1 class="ppm-heading">My Tasks</h1><span class="ppm-count">{{ $total }}</span></div>
        @if ($linked)
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" square variant="ghost" icon="ellipsis-horizontal" aria-label="My Tasks tools" />
                    <flux:menu><flux:menu.item icon="arrow-down-tray" href="{{ route('tasks.export', ['q' => $search, 'status' => $status, 'project' => $projectId, 'queue' => $queue, 'sort' => $sortKey, 'dir' => $sortDir]) }}">Export CSV</flux:menu.item></flux:menu>
                </flux:dropdown>
        @endif
    </header>

    @if (! $linked)
        <div class="ppm-surface p-6"><h2 class="font-medium">Connect your employee record</h2><p class="mt-2 text-sm text-muted-foreground">Your account isn't linked to an employee record yet. HR can link it so your assigned work appears here.</p></div>
    @else
        <div class="ppm-task-toolbar" aria-label="My Tasks controls">
            @if ($taskView !== 'capacity')<flux:input size="sm" aria-label="Search my tasks" icon="magnifying-glass" placeholder="Find a task, project or client…" class="ppm-search" wire:model.live.debounce.250ms="search" />
            @endif
            <div class="ppm-actions">
                @if ($taskView !== 'capacity')
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="ghost" icon="arrows-up-down" aria-label="Sort my tasks">Sort</flux:button>
                        <flux:menu>
                            <flux:menu.item icon="sparkles" wire:click="focusQueue">Needs attention</flux:menu.item>
                            @foreach (['priority' => 'Priority', 'due' => 'Due date', 'start' => 'Start date', 'task' => 'Task', 'status' => 'Status', 'code' => 'Code'] as $value => $label)
                                <flux:menu.item wire:click="sortBy('{{ $value }}')" :icon="$sortKey === $value ? ($sortDir === 'asc' ? 'arrow-up' : 'arrow-down') : null">{{ $label }}</flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                    <x-work.filters id="my-task-filters" label="My task filters" :count="$activeFilterCount - (trim($search) === '' ? 0 : 1)">
                        <x-kit.select label="Project" aria-label="Filter by project" wire:model.live="projectId" searchable>
                            <flux:select.option value="ALL">All projects</flux:select.option>
                            @foreach ($myProjects as $myProject)
                                <flux:select.option value="{{ $myProject->id }}">{{ $myProject->name }} · {{ $myProject->client_name ?? 'Internal' }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                        <x-kit.select label="Status" aria-label="Filter by status" wire:model.live="status">
                            <flux:select.option value="ALL">All statuses</flux:select.option>
                            @foreach (ProjectsData::myStatuses() as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                        <x-kit.select label="Attention" aria-label="Filter by attention" wire:model.live="queue">
                            @foreach (TaskInbox::QUEUES as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                    </x-work.filters>
                    @if ($activeFilterCount > 0)<flux:button size="sm" variant="ghost" wire:click="clearFilters">Clear filters</flux:button>
                    @endif
                @endif
                <div class="ppm-view-switch" role="group" aria-label="My Tasks view">
                    <flux:button size="sm" icon="list-bullet" :variant="$taskView === 'list' ? 'filled' : 'ghost'" wire:click="$set('taskView', 'list')" aria-pressed="{{ $taskView === 'list' ? 'true' : 'false' }}">List</flux:button>
                    <flux:button size="sm" icon="calendar-days" :variant="$taskView === 'gantt' ? 'filled' : 'ghost'" wire:click="$set('taskView', 'gantt')" aria-pressed="{{ $taskView === 'gantt' ? 'true' : 'false' }}">Gantt</flux:button>
                    <flux:button size="sm" icon="users" :variant="$taskView === 'capacity' ? 'filled' : 'ghost'" wire:click="$set('taskView', 'capacity')" aria-pressed="{{ $taskView === 'capacity' ? 'true' : 'false' }}">Availability</flux:button>
                </div>
            </div>
        </div>
        @if ($taskView === 'capacity')
            <livewire:projects.capacity-workspace :own="true" key="own-capacity" />
        @elseif ($taskView === 'gantt')
            <x-work.task-timeline :rows="$rows" :timeline="$timeline" :today="$today" own :sort-key="$sortKey" />
        @else
            <div class="hris-card overflow-hidden" wire:key="own-task-list">
                <div class="hris-table-wrap hris-table-scroll">
                    <table class="hris-table ppm-inbox">
                        <thead><tr><x-kit.sort-header field="task" label="Task" /><th class="hidden sm:table-cell">Project</th><x-kit.sort-header field="due" label="Due" class="hidden sm:table-cell" /><x-kit.sort-header field="status" label="Status" class="hidden sm:table-cell" /><th><span class="sr-only">Action</span></th></tr></thead>
                        <tbody>
                            @if ($rows->isEmpty())
                                <tr><x-kit.empty-state :colspan="5" class="hidden sm:table-cell" :title="$mineEmpty ? 'No tasks are assigned to you yet.' : 'No task matches those filters.'" :hint="$mineEmpty ? 'Your assigned work will appear here.' : 'Try another search or clear the filters.'" /><x-kit.empty-state :colspan="2" class="sm:hidden" :title="$mineEmpty ? 'No tasks are assigned to you yet.' : 'No task matches those filters.'" :hint="$mineEmpty ? 'Your assigned work will appear here.' : 'Try another search or clear the filters.'" /></tr>
                            @endif
                            @php
                                $previousBucket = null;
                                $bucketCounts = $rows->getCollection()->countBy(fn ($task) => $task->inboxBucket($today));
                            @endphp
                            @foreach ($rows as $t)
                                @php
                                    $bucket = $t->inboxBucket($today);
                                    $action = $t->ownAction();
                                @endphp
                                @if ($sortKey === 'focus' && $previousBucket !== $bucket)
                                    <tr wire:key="inbox-section-{{ $bucket }}" class="bg-muted/30">
                                        @foreach (['hidden sm:table-cell' => 5, 'sm:hidden' => 2] as $visibility => $columns)
                                            <td colspan="{{ $columns }}" class="!py-3 {{ $visibility }}"><button type="button" class="ppm-inbox-group {{ in_array($bucket, ['RETURNED', 'OVERDUE'], true) ? 'text-danger' : '' }}" x-on:click="collapsed['{{ $bucket }}'] = !collapsed['{{ $bucket }}']" x-bind:aria-expanded="!collapsed['{{ $bucket }}']"><flux:icon.chevron-down class="size-4 shrink-0" x-bind:class="collapsed['{{ $bucket }}'] && '-rotate-90'" /><span>{{ TaskInbox::BUCKETS[$bucket] }}</span><span class="ppm-count" title="Tasks on this page">{{ $bucketCounts[$bucket] }}</span></button></td>
                                        @endforeach
                                    </tr>
                                @endif
                                @php
                                    $previousBucket = $bucket;
                                @endphp
                                <tr wire:key="task-{{ $t->id }}" x-show="{{ $sortKey === 'focus' ? '!collapsed[\''.$bucket.'\']' : 'true' }}" tabindex="0" aria-label="Open {{ $t->code }} · {{ $t->myStatusLabel() }}" class="cursor-pointer focus:outline-none focus-visible:bg-accent" wire:click="openTask('{{ $t->id }}')" x-on:keydown.enter.self.prevent="$wire.openTask('{{ $t->id }}')" x-on:keydown.space.self.prevent="$wire.openTask('{{ $t->id }}')">
                                    <td class="min-w-0 sm:min-w-56">
                                        <div class="max-w-96 space-y-1 {{ $t->isSubtask() ? 'pl-5' : '' }}">
                                            <x-work.task-parent :task="$t" class="max-w-72" compact />
                                            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 sm:flex-nowrap"><span class="num w-full shrink-0 text-[10px] text-muted-foreground sm:w-auto">{{ $t->code }}</span>@if ($t->isSubtask())<flux:icon.arrow-turn-down-right class="size-3.5 shrink-0 text-muted-foreground" />@endif<p class="min-w-0 flex-1 truncate font-medium" title="{{ $t->title }}">{{ $t->title }}</p></div>
                                            <div class="flex min-w-0 items-center gap-3 text-xs text-muted-foreground">@if ($t->priority !== 'NORMAL')<span class="{{ TaskPolicy::priorityPill($t->priority) }}">{{ TaskPolicy::priorityLabel($t->priority) }}</span>@endif<x-work.task-group :task="$t" class="max-w-44" />
                                                @if (($fileCounts[$t->id] ?? 0) > 0)<span class="inline-flex items-center gap-1" aria-label="{{ $fileCounts[$t->id] }} attached files"><flux:icon.paper-clip class="size-3" /><span class="num">{{ $fileCounts[$t->id] }}</span></span>
                                                @endif
                                                @if (($commentCounts[$t->id] ?? 0) > 0)<span class="inline-flex items-center gap-1" aria-label="{{ $commentCounts[$t->id] }} comments"><flux:icon.chat-bubble-left class="size-3" /><span class="num">{{ $commentCounts[$t->id] }}</span></span>
                                                @endif
                                            </div>
                                            <div class="space-y-1 sm:hidden"><p class="truncate text-xs text-muted-foreground">{{ $t->project?->name }}</p><span class="flex flex-wrap items-center gap-2"><x-work.task-state :status="$t->my_status ?? $t->status" :label="$t->myStatusLabel()" /><x-work.task-deadline :task="$t" :today="$today" own /></span></div>
                                            <x-work.subtask-progress :task="$t" />
                                        </div>
                                    </td>
                                    <td class="hidden max-w-60 sm:table-cell"><p class="truncate text-sm" title="{{ $t->project?->name }}">{{ $t->project?->name ?? '—' }}</p><x-work.project-context :project="$t->project" class="mt-1 max-w-56" /></td>
                                    <td class="hidden whitespace-nowrap sm:table-cell"><x-work.task-deadline :task="$t" :today="$today" own />@unless ($t->due_date)<span class="text-xs text-muted-foreground">—</span>@endunless
                                        @if ($t->start_date)<span class="num mt-1 flex items-center gap-1 text-xs text-muted-foreground" title="Start date"><flux:icon.play class="size-3" aria-hidden="true" /><span class="sr-only">Starts </span>{{ ProjectsData::fmtDate($t->start_date) }}</span>
                                        @endif
                                    </td>
                                    <td class="hidden sm:table-cell"><x-work.task-state :status="$t->my_status ?? $t->status" :label="$t->myStatusLabel()" /></td>
                                    <td class="text-right">
                                        @if ($action === 'start')
                                            <flux:button size="sm" variant="outline" icon="play" wire:click.stop="startTask('{{ $t->id }}')" aria-label="{{ $t->status === 'RETURNED' ? 'Resume' : 'Start' }} {{ $t->title }}">{{ $t->status === 'RETURNED' ? 'Resume' : 'Start' }}</flux:button>
                                        @elseif ($action === 'respond')
                                            <flux:button size="sm" variant="ghost" icon="paper-airplane" wire:click.stop="openTask('{{ $t->id }}')" aria-label="Respond to {{ $t->title }}">Respond</flux:button>
                                        @else
                                            <flux:button size="sm" square variant="ghost" icon="chevron-right" wire:click.stop="openTask('{{ $t->id }}')" aria-label="Open {{ $t->title }}" />
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-kit.pagination :paginator="$rows" noun="tasks" />
            </div>
        @endif
        @include('livewire.tasks.partials.task-detail-dialog')
    @endif
</div>
