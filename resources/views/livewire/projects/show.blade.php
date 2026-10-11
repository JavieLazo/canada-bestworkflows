{{--
    /projects/{id} — the port of project-detail.tsx and its three dialogs:
    edit-project-dialog.tsx, add-task-dialog.tsx and task-review-dialog.tsx,
    plus one deliberate addition the reference never had: a "Delete project"
    confirm (the DB cascades take the tasks, files and comments with the row).

    The summary card is narrower than the reference's: Site / Task Leader /
    Due Date / Progress. There are no Timeline or Health cells (the reference
    has both): the due date is what people actually look for, and the four
    queue cards below already say what "health" summarises. "Task Leader" is
    the user-facing name of `projects.manager_id` (an account); the
    identifiers keep "manager".

    Icons are the reference's own Lucide set, inlined through
    livewire/projects/partials/icon.blade.php (Flux ships Heroicons; the
    reference look is Lucide). On a flux:button the include goes in the named
    `icon` slot, never the default slot: Flux wraps the default slot in a bare
    <span> and preflight makes <svg> display:block, so an inline icon there
    stacks above the label. The named slot also gives every button Flux's
    leading-icon padding. Employee photos render as initials until a
    photo-serving route exists (same stand-in as the kit employee picker).

    A task is handed to 0..N employees (project_task_assignees), so the
    "Assignees" column and the review header print every name, and both
    dialogs bind a list through the kit employee CHECKLIST, not the picker.
    A crew of two or more hands its work in as one team response (GROUP) or
    each member their own (INDIVIDUAL): an INDIVIDUAL task keeps its task
    status in the Status column and adds its responses' progress under it
    ("2 of 3 approved · 1 awaiting review") — counts only, never a note.

    The "Delete project" button and its confirm render for managers only
    ($canManage from Show::render()); the other header controls keep the
    module's deny-at-action convention, and deleteProject() re-checks anyway.

    The project's Task Leader (`projects.manager_id`) RUNS it alongside the
    Projects managers ($runsProject): the header status select, the "Set
    status" control and the start dates are theirs too, and the edit dialog
    shows a leader who is not a manager the start date alone. Every command
    re-checks that against the locked rows.
--}}
@php
    use App\Domain\Projects\ProjectPolicy;
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
@endphp
<div class="space-y-6">
    <a
        href="{{ route('projects.index') }}"
        class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
    >
        @include('livewire.projects.partials.icon', ['icon' => 'arrow-left', 'class' => 'size-4'])
        Project Management
    </a>

    @if ($project === null)
        <div class="hris-card p-10 text-center">
            <h1 class="text-lg font-semibold">Project not found.</h1>
            <p class="mt-2 text-sm text-muted-foreground">This project may have been removed.</p>
        </div>
    @else
        @php
            $siteName = $sites->firstWhere('id', $project->site_id)?->name ?? '—';
            $accountName = fn ($accountId) => $accountId === null ? '—' : ($accounts->firstWhere('id', $accountId)?->name ?? '—');
        @endphp

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight">
                    <span class="num text-muted-foreground">{{ $project->code }}</span> {{ $project->name }}
                </h1>
                @if ($project->description)
                    <p class="mt-1 max-w-3xl text-sm text-muted-foreground">{{ $project->description }}</p>
                @endif
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <flux:button variant="outline" wire:click="openEdit">
                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'edit-3', 'class' => 'size-4'])</x-slot:icon>
                    Edit project
                </flux:button>
                @if ($canManage)
                    <flux:button variant="danger" wire:click="$set('deleteConfirmOpen', true)">
                        <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'trash-2', 'class' => 'size-4'])</x-slot:icon>
                        Delete project
                    </flux:button>
                @endif
                {{-- A status set by hand (ManualProjectStatus): any status,
                     a closed project's reopen included, except what its facts
                     forbid — Active without a Task Leader, Completed with a
                     task still open. The rendered status rides along, so a
                     project someone moved meanwhile is refused, not moved on. --}}
                <x-kit.select
                    aria-label="Project status"
                    class="w-[11rem]"
                    wire:change="changeStatus($event.target.value, '{{ $project->status }}')"
                >
                    @foreach (ProjectPolicy::STATUSES as $value => $label)
                        <flux:select.option
                            value="{{ $value }}"
                            :selected="$project->status === $value"
                            :disabled="$taskTotals->manualStatusError($project, $value) !== null"
                        >{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            </div>
        </div>

        <section class="hris-card grid gap-5 p-5 sm:grid-cols-2 xl:grid-cols-4">
            <div class="min-w-0 space-y-1">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Site</p>
                <div class="break-words text-sm font-medium">{{ $siteName }}</div>
            </div>
            <div class="min-w-0 space-y-1">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Task Leader</p>
                <div class="break-words text-sm font-medium">{{ $accountName($project->manager_id) }}</div>
            </div>
            <div class="min-w-0 space-y-1">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Due Date</p>
                <div class="break-words text-sm font-medium">
                    <span class="num">{{ $project->due_date ? ProjectsData::fmtDate($project->due_date) : '—' }}</span>
                </div>
            </div>
            <div class="min-w-0 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Progress</p>
                <div class="h-2 w-full overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}">
                    <div class="h-full rounded-full bg-primary transition-all" style="width: {{ $progress['percent'] }}%"></div>
                </div>
                <p class="text-xs text-muted-foreground">
                    <span class="num">{{ $progress['completed'] }}</span> of
                    <span class="num">{{ $progress['total'] }}</span> tasks complete
                </p>
            </div>
        </section>

        <section aria-label="Project work queue" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button
                type="button"
                aria-pressed="{{ $queue === 'REVIEW' ? 'true' : 'false' }}"
                wire:click="setQueue('REVIEW')"
                class="hris-card flex items-center gap-3 p-4 text-left transition-colors hover:bg-accent"
            >
                <span class="rounded-md bg-success/10 p-2 text-success">
                    @include('livewire.projects.partials.icon', ['icon' => 'clipboard-check', 'class' => 'size-4'])
                </span>
                <span>
                    <span class="num block text-xl font-bold">{{ $reviewCount }}</span>
                    <span class="text-xs text-muted-foreground">Needs review</span>
                </span>
            </button>
            <button
                type="button"
                aria-pressed="{{ $queue === 'OVERDUE' ? 'true' : 'false' }}"
                wire:click="setQueue('OVERDUE')"
                class="hris-card flex items-center gap-3 p-4 text-left transition-colors hover:bg-accent"
            >
                <span class="rounded-md bg-danger/10 p-2 text-danger">
                    @include('livewire.projects.partials.icon', ['icon' => 'alert-triangle', 'class' => 'size-4'])
                </span>
                <span>
                    <span class="num block text-xl font-bold">{{ $overdueCount }}</span>
                    <span class="text-xs text-muted-foreground">Overdue tasks</span>
                </span>
            </button>
            <button
                type="button"
                aria-pressed="{{ $queue === 'DUE_SOON' ? 'true' : 'false' }}"
                wire:click="setQueue('DUE_SOON')"
                class="hris-card flex items-center gap-3 p-4 text-left transition-colors hover:bg-accent"
            >
                <span class="rounded-md bg-warning/10 p-2 text-warning">
                    @include('livewire.projects.partials.icon', ['icon' => 'alert-triangle', 'class' => 'size-4'])
                </span>
                <span>
                    <span class="num block text-xl font-bold">{{ $dueSoonCount }}</span>
                    <span class="text-xs text-muted-foreground">Due soon tasks</span>
                </span>
            </button>
            <button
                type="button"
                aria-pressed="{{ $queue === 'UNASSIGNED' ? 'true' : 'false' }}"
                wire:click="setQueue('UNASSIGNED')"
                class="hris-card flex items-center gap-3 p-4 text-left transition-colors hover:bg-accent"
            >
                <span class="rounded-md bg-muted p-2 text-muted-foreground">
                    @include('livewire.projects.partials.icon', ['icon' => 'user-round-x', 'class' => 'size-4'])
                </span>
                <span>
                    <span class="num block text-xl font-bold">{{ $unassignedCount }}</span>
                    <span class="text-xs text-muted-foreground">Unassigned tasks</span>
                </span>
            </button>
        </section>

        <section class="hris-card overflow-hidden">
            <header class="border-b border-border px-5 py-4">
                <h2 class="text-base font-bold">Workload</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Open work by assignee on this project.
                </p>
            </header>
            @if ($workload->isEmpty())
                <p class="px-5 py-6 text-sm text-muted-foreground">No assigned work yet.</p>
            @else
                <div class="divide-y divide-border">
                    @foreach ($workload as $entry)
                        <button
                            wire:key="workload-{{ $entry['employee']->id }}"
                            type="button"
                            class="flex w-full items-center justify-between gap-4 px-5 py-3 text-left hover:bg-accent"
                            wire:click="filterByAssignee('{{ $entry['employee']->id }}')"
                        >
                            <span class="flex min-w-0 items-center gap-3">
                                <span class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                    <span class="num text-xs font-semibold">{{ ProjectsData::initials($entry['employee']) }}</span>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ ProjectsData::employeeName($entry['employee']) }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">
                                        {{ $entry['employee']->position ?? 'No position' }}
                                    </span>
                                </span>
                            </span>
                            <span class="flex shrink-0 items-center gap-4 text-xs text-muted-foreground">
                                <span>
                                    <strong class="num text-foreground">{{ $entry['active'] }}</strong> open
                                </span>
                                @if ($entry['review'] > 0)
                                    <span class="text-success">
                                        <strong class="num">{{ $entry['review'] }}</strong> submitted
                                    </span>
                                @endif
                                @if ($entry['overdue'] > 0)
                                    <span class="text-danger">
                                        <strong class="num">{{ $entry['overdue'] }}</strong> overdue
                                    </span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="hris-card overflow-hidden">
            <header class="border-b border-border px-5 py-4">
                <h2 class="text-base font-bold">Recent activity</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    The latest discussion on this project&rsquo;s tasks.
                </p>
            </header>
            @if ($recentActivity->isEmpty())
                <p class="px-5 py-6 text-sm text-muted-foreground">No task activity yet.</p>
            @else
                <div class="divide-y divide-border">
                    @foreach ($recentActivity as $comment)
                        @php
                            $activityTask = $activityTasks->get($comment->task_id);
                        @endphp
                        <div wire:key="activity-{{ $comment->id }}" class="flex gap-3 px-5 py-3">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm">
                                    <span class="font-medium">{{ $comment->author_name }}</span> commented on
                                    <span class="num font-medium">{{ $activityTask?->code ?? 'task' }}</span>
                                </p>
                                <p class="mt-1 break-words text-sm text-muted-foreground">{{ $comment->body }}</p>
                                <p class="num mt-1 text-xs text-muted-foreground">
                                    {{ ProjectsData::fmtDateTime($comment->created_at) }}
                                </p>
                            </div>
                            @if ($comment->event)
                                <span class="pill pill-muted self-start">{{ $comment->event }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-bold">Tasks</h2>
            <div class="flex flex-wrap items-center gap-2">
                {{-- Green like every Export CSV (x-kit.export-button); its own markup only for the projects icon set. --}}
                <flux:button variant="primary" href="{{ $tasksExportUrl }}" class="hris-button-success">
                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'download', 'class' => 'size-4'])</x-slot:icon>
                    Export CSV
                </flux:button>
                <livewire:kit.csv-import
                    table="projects.tasks"
                    :context="$project->id"
                    :template="route('projects.tasks.export', ['project' => $project->id, 'template' => 1])"
                    :key="'import-tasks-'.$project->id"
                />
                <flux:button variant="primary" wire:click="$set('adding', true)">
                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'plus', 'class' => 'size-4'])</x-slot:icon>
                    Add task
                </flux:button>
            </div>
        </div>

        <section class="hris-card overflow-hidden">
            <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
                <flux:input
                    aria-label="Search tasks"
                    placeholder="Search code or task"
                    class="w-full min-w-[12rem] max-w-xs flex-1"
                    wire:model.live.debounce.250ms="search"
                />

                <x-kit.filter-select label="Filter by status" class="w-[13rem]" wire:model.live="status">
                    <flux:select.option value="ALL">All statuses</flux:select.option>
                    @foreach (TaskPolicy::STATUSES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.filter-select>

                <x-kit.filter-select label="Filter by assignee" class="w-[13rem]" wire:model.live="assignee">
                    <flux:select.option value="ALL">All assignees</flux:select.option>
                    <flux:select.option value="UNASSIGNED">Unassigned</flux:select.option>
                    @foreach ($holders as $holder)
                        <flux:select.option value="{{ $holder->id }}">{{ ProjectsData::employeeName($holder) }}</flux:select.option>
                    @endforeach
                </x-kit.filter-select>

                <x-kit.filter-select label="Filter by queue" class="w-[13rem]" wire:model.live="queue">
                    <flux:select.option value="ALL">All work</flux:select.option>
                    <flux:select.option value="REVIEW">Needs review</flux:select.option>
                    <flux:select.option value="OVERDUE">Overdue</flux:select.option>
                    <flux:select.option value="DUE_SOON">Due soon</flux:select.option>
                    <flux:select.option value="UNASSIGNED">Unassigned</flux:select.option>
                </x-kit.filter-select>

                <x-kit.filter-select label="Filter by priority" class="w-[13rem]" wire:model.live="priority">
                    <flux:select.option value="ALL">All priorities</flux:select.option>
                    @foreach (TaskPolicy::PRIORITIES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </x-kit.filter-select>
            </header>

            <div class="hris-table-wrap hris-table-scroll">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <x-kit.sort-header field="code" label="Code" />
                            <x-kit.sort-header field="task" label="Task" />
                            <x-kit.sort-header field="assignee" label="Assignees" />
                            <x-kit.sort-header field="priority" label="Priority" />
                            <x-kit.sort-header field="start" label="Start" />
                            <x-kit.sort-header field="due" label="Due" />
                            <x-kit.sort-header field="status" label="Status" />
                        </tr>
                    </thead>
                    <tbody>
                        @if ($visibleCount === 0)
                            <tr wire:key="empty">
                                <x-kit.empty-state
                                    :colspan="7"
                                    :title="$taskCount === 0 ? 'No tasks on this project yet.' : 'No task matches those filters.'"
                                />
                            </tr>
                        @endif
                        @foreach ($rows as $t)
                            @php
                                // ONE surname-ordered list feeds both lines of the
                                // Assignees cell, so entry N of the Employee IDs
                                // below is entry N of the names.
                                $rowAssignees = ProjectsData::sortedAssignees($t);
                                $overdue = TaskPolicy::isOverdue(ProjectsData::taskShape($t), $today);
                                $attached = $fileCounts[$t->id] ?? 0;
                                $discussed = $commentCounts[$t->id] ?? 0;
                            @endphp
                            <tr
                                wire:key="task-{{ $t->id }}"
                                tabindex="0"
                                aria-label="Open {{ $t->code !== '' ? $t->code : 'task' }}"
                                class="cursor-pointer focus:outline-none focus-visible:bg-accent"
                                wire:click="openTask('{{ $t->id }}')"
                                x-on:keydown.enter.prevent="$wire.openTask('{{ $t->id }}')"
                                x-on:keydown.space.prevent="$wire.openTask('{{ $t->id }}')"
                            >
                                <td class="num whitespace-nowrap align-middle">{{ $t->code !== '' ? $t->code : '—' }}</td>
                                <td>
                                    <div class="min-w-0 max-w-[24rem]">
                                        <div class="flex min-w-0 items-center gap-1.5">
                                            <p class="truncate font-medium">{{ $t->title }}</p>
                                            @if ($attached > 0)
                                                <span
                                                    role="img"
                                                    class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                                                    aria-label="{{ $attached }} attached {{ $attached === 1 ? 'file' : 'files' }}"
                                                >
                                                    @include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3'])
                                                    <span class="num">{{ $attached }}</span>
                                                </span>
                                            @endif
                                            @if ($discussed > 0)
                                                <span
                                                    role="img"
                                                    class="flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                                                    aria-label="{{ $discussed }} {{ $discussed === 1 ? 'comment' : 'comments' }}"
                                                >
                                                    @include('livewire.projects.partials.icon', ['icon' => 'message-square', 'class' => 'size-3'])
                                                    <span class="num">{{ $discussed }}</span>
                                                </span>
                                            @endif
                                        </div>
                                        @if ($t->description)
                                            <p class="truncate text-xs text-muted-foreground">{{ $t->description }}</p>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    {{-- Every assignee, "Garcia, Reyes"-ordered; wraps rather
                                         than truncates so a shared task never hides a name. --}}
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                            <span class="num text-xs font-semibold">
                                                @if (count($rowAssignees) === 0)
                                                    —
                                                @elseif (count($rowAssignees) === 1)
                                                    {{ ProjectsData::initials($rowAssignees[0]) }}
                                                @else
                                                    {{ count($rowAssignees) }}
                                                @endif
                                            </span>
                                        </span>
                                        <div class="min-w-0 max-w-[16rem]">
                                            <p class="break-words font-medium">
                                                {{ count($rowAssignees) === 0 ? '—' : implode(', ', array_map(static fn ($e) => ProjectsData::employeeName($e), $rowAssignees)) }}
                                            </p>
                                            {{-- Employee IDs, not row ids. POSITIONAL: the names
                                                 above are joined over every assignee, so an
                                                 unnumbered one holds its place with an em dash
                                                 rather than being dropped — otherwise three
                                                 names sit over two ids and the reader pairs the
                                                 wrong number with the wrong person. When NOBODY
                                                 on the task has a number there is nothing to
                                                 align, and the line is a single em dash. --}}
                                            @php
                                                $assigneeIds = array_map(static fn ($e): string => \App\Support\EmployeeIdentity::besideName($e), $rowAssignees);
                                                $assigneeLine = \App\Support\EmployeeIdentity::meta(...$assigneeIds) === ''
                                                    ? '—'
                                                    : implode(' · ', array_map(static fn (string $id): string => $id === '' ? '—' : $id, $assigneeIds));
                                            @endphp
                                            <p class="num break-words text-xs text-muted-foreground">
                                                {{ count($rowAssignees) === 0 ? '—' : $assigneeLine }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="{{ TaskPolicy::priorityPill($t->priority) }}">
                                        {{ TaskPolicy::priorityLabel($t->priority) }}
                                    </span>
                                </td>
                                <td class="num whitespace-nowrap">
                                    {{ $t->start_date ? ProjectsData::fmtDate($t->start_date) : '—' }}
                                </td>
                                <td class="{{ $overdue ? 'num whitespace-nowrap font-medium text-danger' : 'num whitespace-nowrap' }}">
                                    {{ $t->due_date ? ProjectsData::fmtDate($t->due_date) : '—' }}
                                    @if ($overdue)
                                        <span class="text-xs"> · overdue</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="{{ TaskPolicy::statusPill($t->status) }}">{{ TaskPolicy::statusLabel($t->status) }}</span>
                                    {{-- The progress line WRAPS inside the nowrap cell (the
                                         pill keeps its own nowrap): at up to four clauses it
                                         would otherwise widen the Status column several times
                                         over and squeeze Task and Assignees. Capped like the
                                         Assignees column. --}}
                                    @if ($t->isIndividual())
                                        <span class="num mt-1 block max-w-[16rem] whitespace-normal text-xs text-muted-foreground">{{ $t->responseSummary() }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-kit.pagination :paginator="$rows" noun="tasks" />
        </section>

        @include('livewire.projects.partials.dialog-task-add')

        @include('livewire.projects.partials.dialog-project-edit')

        @include('livewire.projects.partials.dialog-project-delete')

        @include('livewire.projects.partials.dialog-task-review')
    @endif
</div>
