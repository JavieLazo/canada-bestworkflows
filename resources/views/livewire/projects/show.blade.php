{{--
    /projects/{id} — the port of project-detail.tsx and its three dialogs:
    edit-project-dialog.tsx, add-task-dialog.tsx and task-review-dialog.tsx,
    plus one deliberate addition the reference never had: a "Delete project"
    confirm (the DB cascades take the tasks, files and comments with the row).

    The summary card is narrower than the reference's: Site / Task Leader /
    Due Date / Progress. The Timeline and Health cells were dropped on request —
    the due date is what people actually look for, and the four queue cards
    below already say what "health" summarised. "Task Leader" is the user-facing
    name of `projects.manager_id` (an account); the identifiers keep "manager".

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

    The "Delete project" button and its confirm render for managers only
    ($canManage from Show::render()); the other header controls keep the
    module's deny-at-action convention, and deleteProject() re-checks anyway.
--}}
@php
    use App\Domain\Projects\ProjectPolicy;
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
    use App\Livewire\Projects\Show;
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
            $taskShapes = ProjectsData::taskShapes($projectTasks);
            $projectShape = ProjectsData::projectShape($project);
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
                <flux:select
                    aria-label="Project status"
                    class="w-[11rem]"
                    wire:change="changeStatus($event.target.value)"
                >
                    @foreach (ProjectPolicy::STATUSES as $value => $label)
                        <flux:select.option
                            value="{{ $value }}"
                            :selected="$project->status === $value"
                            :disabled="ProjectPolicy::statusError($projectShape, $taskShapes, $value) !== null"
                        >{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
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
                            $activityTask = null;
                            foreach ($projectTasks as $candidate) {
                                if ($candidate->id === $comment->task_id) {
                                    $activityTask = $candidate;
                                }
                            }
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
                <flux:button variant="outline" href="{{ $tasksExportUrl }}">
                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'download', 'class' => 'size-4'])</x-slot:icon>
                    Export CSV
                </flux:button>
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
                            <x-kit.sort-header field="due" label="Due" />
                            <x-kit.sort-header field="status" label="Status" />
                        </tr>
                    </thead>
                    <tbody>
                        @if ($visibleCount === 0)
                            <tr wire:key="empty">
                                <x-kit.empty-state
                                    :colspan="6"
                                    :title="$taskCount === 0 ? 'No tasks on this project yet.' : 'No task matches those filters.'"
                                />
                            </tr>
                        @endif
                        @foreach ($rows as $t)
                            @php
                                // ONE surname-ordered list feeds both lines of the
                                // Assignees cell, so line N of codes is line N of names.
                                $rowAssignees = Show::sortedAssignees($t);
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
                                            <p class="num break-words text-xs text-muted-foreground">
                                                {{ count($rowAssignees) === 0 ? '—' : implode(' · ', array_map(static fn ($e) => $e->employee_code, $rowAssignees)) }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="{{ TaskPolicy::priorityPill($t->priority) }}">
                                        {{ TaskPolicy::priorityLabel($t->priority) }}
                                    </span>
                                </td>
                                <td class="{{ $overdue ? 'num whitespace-nowrap font-medium text-danger' : 'num whitespace-nowrap' }}">
                                    {{ $t->due_date ? ProjectsData::fmtDate($t->due_date) : '—' }}
                                    @if ($overdue)
                                        <span class="text-xs"> · overdue</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="{{ TaskPolicy::statusPill($t->status) }}">{{ TaskPolicy::statusLabel($t->status) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-kit.pagination :paginator="$rows" noun="tasks" />
        </section>

        {{-- add-task-dialog.tsx --}}
        <flux:modal wire:model.self="adding" class="max-h-[90vh] w-full max-w-lg overflow-y-auto">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Add task</flux:heading>
                    <flux:text class="mt-1">
                        Hand out a unit of work on {{ $project->name }}. It starts pending until an assignee picks it
                        up from My Tasks.
                    </flux:text>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <flux:label for="task-title">Title</flux:label>
                        <flux:input
                            id="task-title"
                            placeholder="What has to be done"
                            wire:model.live.debounce.250ms="taskTitle"
                        />
                    </div>

                    <div class="space-y-2 sm:col-span-2">
                        <flux:label for="task-description">Description</flux:label>
                        <flux:textarea
                            id="task-description"
                            rows="3"
                            placeholder="Scope, acceptance criteria, anything the assignee needs"
                            wire:model="taskDescription"
                        />
                    </div>

                    <div class="space-y-2 sm:col-span-2">
                        <flux:label>Assignees</flux:label>
                        {{-- Entangled list; the nonce remounts the checklist (clean
                             search, fresh list) every time the draft resets.

                             `.live` on purpose: a nested wire:model is DEFERRED — each
                             tick is a request on the child only, and the new array is
                             pushed into the parent's client-side state without a
                             parent render. The "Add task" button's disabled gate
                             below is evaluated server-side, so without `.live` it
                             would go stale until some other parent round-trip. Live,
                             the child root's `wire:model.live="$parent.taskAssigneeIds"`
                             commits the parent after every toggle and the gate
                             re-evaluates (the server rule itself is unchanged). --}}
                        <livewire:kit.employee-checklist
                            wire:model.live="taskAssigneeIds"
                            name="task-assignees"
                            placeholder="Search employees"
                            aria-label="Assignees"
                            :key="'task-assignees-'.$taskDraftNonce"
                        />
                    </div>

                    <div class="space-y-2">
                        <flux:label>Priority</flux:label>
                        <flux:select aria-label="Priority" wire:model="taskPriority">
                            @foreach (TaskPolicy::PRIORITIES as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="space-y-2">
                        <flux:label for="task-due">Due date</flux:label>
                        <x-kit.date-field id="task-due" aria-label="Due date" wire:model="taskDueDate" />
                    </div>

                    <div class="space-y-2 sm:col-span-2">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                Attach brief (optional)
                            </p>
                            <flux:button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-7"
                                x-on:click="$refs.briefPickInput.click()"
                            >
                                <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3'])</x-slot:icon>
                                Attach
                            </flux:button>
                            <input
                                type="file"
                                multiple
                                class="hidden"
                                x-ref="briefPickInput"
                                wire:model="briefPick"
                            />
                        </div>

                        @if (count($briefStage) === 0)
                            <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                                Nothing attached. Anything you add here is stored with the task once it is created.
                            </p>
                        @else
                            <ul class="space-y-1">
                                @foreach ($briefStage as $i => $staged)
                                    <li
                                        wire:key="staged-{{ $i }}-{{ $staged->getClientOriginalName() }}"
                                        class="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2"
                                    >
                                        @include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3.5 shrink-0 text-muted-foreground'])
                                        <span class="min-w-0 flex-1 truncate text-sm">{{ $staged->getClientOriginalName() }}</span>
                                        <span class="num shrink-0 text-xs text-muted-foreground">
                                            {{ ProjectsData::humanSize((int) $staged->getSize()) }}
                                        </span>
                                        <flux:button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            square
                                            class="h-7 w-7 shrink-0"
                                            aria-label="Remove {{ $staged->getClientOriginalName() }}"
                                            wire:click="removeBrief({{ $i }})"
                                        >
                                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'trash-2', 'class' => 'size-3.5 text-danger'])</x-slot:icon>
                                        </flux:button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="flex items-end sm:col-span-2">
                        <flux:button
                            variant="primary"
                            wire:click="saveTask"
                            :disabled="trim($taskTitle) === '' || count($taskAssigneeIds) === 0"
                        >
                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'plus', 'class' => 'size-4'])</x-slot:icon>
                            Add task
                        </flux:button>
                    </div>
                </div>
            </div>
        </flux:modal>

        {{-- edit-project-dialog.tsx --}}
        <flux:modal wire:model.self="editing" class="max-h-[90vh] w-full max-w-lg overflow-y-auto">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Edit project</flux:heading>
                    <flux:text class="mt-1">
                        Update the project brief, task leader, due date, or current status for {{ $project->code }}.
                    </flux:text>
                </div>

                <form class="grid gap-4 sm:grid-cols-2" wire:submit="saveProject">
                    <div class="space-y-2 sm:col-span-2">
                        <flux:label for="project-edit-name">Project name</flux:label>
                        <flux:input id="project-edit-name" wire:model="editName" />
                    </div>

                    <div class="space-y-2 sm:col-span-2">
                        <flux:label for="project-edit-description">Description</flux:label>
                        <flux:textarea id="project-edit-description" rows="3" wire:model="editDescription" />
                    </div>

                    <div class="space-y-2">
                        <flux:label>Site</flux:label>
                        <flux:select aria-label="Project site" wire:model="editSiteId">
                            <flux:select.option value="NONE">No site</flux:select.option>
                            @foreach ($sites as $siteRow)
                                <flux:select.option value="{{ $siteRow->id }}">{{ $siteRow->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="space-y-2">
                        <flux:label>Task Leader</flux:label>
                        <flux:select aria-label="Project task leader" wire:model="editManagerId">
                            <flux:select.option value="NONE">No task leader</flux:select.option>
                            @foreach ($accounts as $account)
                                @if ((bool) $account->getAttribute('is_active'))
                                    <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                                @endif
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="space-y-2">
                        <flux:label>Status</flux:label>
                        <flux:select aria-label="Project status" wire:model="editStatus">
                            @foreach (ProjectPolicy::STATUSES as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="space-y-2">
                        <flux:label for="project-edit-start">Start date</flux:label>
                        <x-kit.date-field id="project-edit-start" aria-label="Start date" wire:model="editStartDate" />
                    </div>

                    <div class="space-y-2">
                        <flux:label for="project-edit-due">Due date</flux:label>
                        <x-kit.date-field id="project-edit-due" aria-label="Due date" wire:model="editDueDate" />
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-border pt-4 sm:col-span-2">
                        <p class="num text-xs text-muted-foreground">
                            Created {{ ProjectsData::fmtDateManila($project->created_at) }}
                        </p>
                        <flux:button type="submit" variant="primary">Save changes</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>

        {{-- Delete project — no reference dialog; modelled on the employees
             delete confirm, rendered for managers only (the action re-checks
             the gate itself).

             Accessible name: Flux forwards only class/style/autofocus from
             <flux:modal> to its <dialog>, so an aria-label on the tag lands
             on the <ui-modal> wrapper and names nothing. The heading carries
             an id and x-init points the dialog's aria-labelledby at it once
             Alpine boots; the dialog is wire:ignore.self, so the attribute
             survives every re-render. --}}
        @if ($canManage)
            <flux:modal
                wire:model.self="deleteConfirmOpen"
                class="w-full max-w-md"
                x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'delete-project-heading')"
            >
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg" id="delete-project-heading">Delete this project?</flux:heading>
                        <flux:text class="mt-2">
                            This permanently removes {{ $project->code }} &mdash; {{ $project->name }}, its
                            <span class="num">{{ $taskCount }}</span> {{ $taskCount === 1 ? 'task' : 'tasks' }}, and
                            every file and comment attached to them. This cannot be undone.
                        </flux:text>
                    </div>
                    <div class="flex items-center justify-end gap-2">
                        <flux:button variant="outline" wire:click="$set('deleteConfirmOpen', false)">
                            Cancel
                        </flux:button>
                        <flux:button variant="danger" wire:click="deleteProject">
                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'trash-2', 'class' => 'size-4'])</x-slot:icon>
                            Delete project
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        {{-- task-review-dialog.tsx --}}
        <flux:modal wire:model.self="reviewOpen" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto">
            @if ($openTask !== null)
                @php
                    $actions = TaskPolicy::managerCanMoveTo($openTask->status);
                    $taskOverdue = TaskPolicy::isOverdue(ProjectsData::taskShape($openTask), $today);
                @endphp
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg">{{ $openTask->code !== '' ? $openTask->code : 'Task' }}</flux:heading>
                        <flux:text class="mt-1">{{ $openTask->title }}</flux:text>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 rounded-md border border-border p-4">
                        <span class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                            <span class="num text-sm font-semibold">
                                @if (count($openTaskAssignees) === 0)
                                    —
                                @elseif (count($openTaskAssignees) === 1)
                                    {{ ProjectsData::initials($openTaskAssignees[0]) }}
                                @else
                                    {{ count($openTaskAssignees) }}
                                @endif
                            </span>
                        </span>
                        <div class="min-w-0">
                            <p class="break-words font-semibold">{{ ProjectsData::assigneeNames($openTask) }}</p>
                            <p class="num break-words text-xs text-muted-foreground">
                                @if (count($openTaskAssignees) === 0)
                                    Unassigned
                                @else
                                    @foreach ($openTaskAssignees as $assignee)
                                        {{ $assignee->employee_code }} · {{ $assignee->position ?? '—' }}{{ $loop->last ? '' : ',' }}
                                    @endforeach
                                @endif
                            </p>
                        </div>
                        <div class="ml-auto flex items-center gap-2">
                            <span class="{{ TaskPolicy::priorityPill($openTask->priority) }}">
                                {{ TaskPolicy::priorityLabel($openTask->priority) }}
                            </span>
                            <span class="{{ TaskPolicy::statusPill($openTask->status) }}">{{ TaskPolicy::statusLabel($openTask->status) }}</span>
                            <flux:button
                                type="button"
                                variant="ghost"
                                size="sm"
                                square
                                aria-label="Edit task assignment and deadline"
                                wire:click="toggleEditingDetails"
                            >
                                <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'edit-3', 'class' => 'size-4'])</x-slot:icon>
                            </flux:button>
                        </div>
                    </div>

                    @if ($editingDetails)
                        <div class="grid gap-4 rounded-md border border-border bg-muted/20 p-4 sm:grid-cols-3">
                            <div class="space-y-2 sm:col-span-3">
                                <flux:label>Assignees</flux:label>
                                <livewire:kit.employee-checklist
                                    wire:model="draftAssigneeIds"
                                    name="review-assignees"
                                    placeholder="Search employees"
                                    aria-label="Assignees"
                                    :key="'review-assignees-'.$openTask->id"
                                />
                            </div>
                            <div class="space-y-2">
                                <flux:label>Priority</flux:label>
                                <flux:select aria-label="Task priority" wire:model="draftPriority">
                                    @foreach (TaskPolicy::PRIORITIES as $value => $label)
                                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="space-y-2">
                                <flux:label for="task-edit-due">Due date</flux:label>
                                <x-kit.date-field id="task-edit-due" aria-label="Due date" wire:model="draftDueDate" />
                            </div>
                            <div class="flex items-end justify-end gap-2 sm:col-span-3">
                                <flux:button type="button" variant="ghost" wire:click="$set('editingDetails', false)">
                                    Cancel
                                </flux:button>
                                <flux:button type="button" variant="primary" wire:click="saveDetails">
                                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'save', 'class' => 'size-4'])</x-slot:icon>
                                    Save task details
                                </flux:button>
                            </div>
                        </div>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="min-w-0 space-y-1">
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">Project</p>
                            <div class="break-words text-sm font-medium">{{ $project->name }}</div>
                        </div>
                        <div class="min-w-0 space-y-1">
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">Due</p>
                            <div class="break-words text-sm font-medium">
                                @if ($openTask->due_date)
                                    <span class="{{ $taskOverdue ? 'num text-danger' : 'num' }}">
                                        {{ ProjectsData::fmtDate($openTask->due_date) }}{{ $taskOverdue ? ' · overdue' : '' }}
                                    </span>
                                @else
                                    —
                                @endif
                            </div>
                        </div>
                        <div class="min-w-0 space-y-1">
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">Assigned by</p>
                            <div class="break-words text-sm font-medium">
                                {{ $accountName($openTask->getAttribute('assigned_by')) }}
                                <span class="num block text-xs font-normal text-muted-foreground">
                                    {{ ProjectsData::fmtDateTime($openTask->assigned_at) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">Description</p>
                        <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">
                            {{ trim($openTask->description ?? '') !== '' ? trim($openTask->description) : 'No description given.' }}
                        </p>
                    </div>

                    {{-- Management owns the brief, so it stays editable at any status
                         in the dialog; outside the editable statuses the server
                         answers "Task evidence is locked in this status." --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Brief &amp; references</p>
                            <flux:button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-7"
                                x-on:click="$refs.reviewBriefInput.click()"
                            >
                                <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3'])</x-slot:icon>
                                Attach
                            </flux:button>
                            <input
                                type="file"
                                class="hidden"
                                x-ref="reviewBriefInput"
                                wire:model="reviewBriefUpload"
                            />
                        </div>
                        @if ($openTaskBrief->isEmpty())
                            <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                                No brief attached. Attach the spec or form the assignee should work from.
                            </p>
                        @else
                            <ul class="space-y-1">
                                @foreach ($openTaskBrief as $f)
                                    <li wire:key="brief-{{ $f->id }}" class="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2">
                                        @include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3.5 shrink-0 text-muted-foreground'])
                                        <span class="min-w-0 flex-1 truncate text-sm">{{ $f->file_name }}</span>
                                        <span class="num shrink-0 text-xs text-muted-foreground">{{ ProjectsData::humanSize((int) $f->size) }}</span>
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            square
                                            class="h-7 w-7 shrink-0"
                                            href="{{ route('projects.task-files.download', $f->id) }}"
                                            aria-label="Download {{ $f->file_name }}"
                                        >
                                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'download', 'class' => 'size-3.5'])</x-slot:icon>
                                        </flux:button>
                                        <flux:button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            square
                                            class="h-7 w-7 shrink-0"
                                            aria-label="Remove {{ $f->file_name }}"
                                            wire:click="deleteFile('{{ $f->id }}')"
                                        >
                                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'trash-2', 'class' => 'size-3.5 text-danger'])</x-slot:icon>
                                        </flux:button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- Read-only on purpose: the reviewer must not be able to add to
                         or thin out what the assignee actually handed in. --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Submitted files</p>
                        </div>
                        @if ($openTaskSubmitted->isEmpty())
                            <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                                No files were submitted with this task.
                            </p>
                        @else
                            <ul class="space-y-1">
                                @foreach ($openTaskSubmitted as $f)
                                    <li wire:key="submission-{{ $f->id }}" class="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2">
                                        @include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3.5 shrink-0 text-muted-foreground'])
                                        <span class="min-w-0 flex-1 truncate text-sm">{{ $f->file_name }}</span>
                                        <span class="num shrink-0 text-xs text-muted-foreground">{{ ProjectsData::humanSize((int) $f->size) }}</span>
                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            square
                                            class="h-7 w-7 shrink-0"
                                            href="{{ route('projects.task-files.download', $f->id) }}"
                                            aria-label="Download {{ $f->file_name }}"
                                        >
                                            <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'download', 'class' => 'size-3.5'])</x-slot:icon>
                                        </flux:button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- Returning clears submitted_at, so gate on the remarks too —
                         the assignee's note is still the last thing they said. --}}
                    @if ($openTask->submitted_at !== null || trim($openTask->remarks ?? '') !== '')
                        <div class="space-y-1">
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">
                                @php
                                    // Who handed it in: the recorded submitter when the
                                    // pivot era stamped one, else the assignee set.
                                    $submittedBy = $openTask->submitter !== null
                                        ? ProjectsData::employeeName($openTask->submitter)
                                        : ProjectsData::assigneeNames($openTask);
                                @endphp
                                @if ($openTask->submitted_at !== null)
                                    Submitted <span class="num">{{ ProjectsData::fmtDateTime($openTask->submitted_at) }}</span>
                                @else
                                    Last submission
                                @endif
                                @if ($submittedBy !== '—')
                                    by {{ $submittedBy }}
                                @endif
                            </p>
                            <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">
                                {{ trim($openTask->remarks ?? '') !== '' ? trim($openTask->remarks) : 'No remarks from the assignees.' }}
                            </p>
                        </div>
                    @endif

                    @if ($openTask->review_remarks)
                        <div class="space-y-1">
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">
                                Review remarks{{ $openTask->getAttribute('reviewed_by') !== null ? ' · '.$accountName($openTask->getAttribute('reviewed_by')) : '' }}
                            </p>
                            <p class="rounded-md border border-border bg-muted/30 p-3 text-sm">
                                {{ $openTask->review_remarks }}
                            </p>
                        </div>
                    @endif

                    @if (count($actions) > 0)
                        <div class="space-y-2">
                            <flux:label for="task-review-remark">
                                Review remark{{ in_array('RETURNED', $actions, true) ? ' (required to return)' : '' }}
                            </flux:label>
                            <flux:textarea
                                id="task-review-remark"
                                rows="3"
                                placeholder="What the assignee should know about this decision"
                                wire:model="remarks"
                            />
                        </div>
                    @endif

                    {{-- Above the decision row, and deliberately not gated on
                         `actions` — a note is still legitimate once the task is
                         closed (task-review-dialog.tsx:840-856). --}}
                    <div class="space-y-2">
                        <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            @include('livewire.projects.partials.icon', ['icon' => 'message-square', 'class' => 'size-3'])
                            Discussion
                            @if ($openTaskComments->count() > 0)
                                <span class="num">({{ $openTaskComments->count() }})</span>
                            @endif
                        </p>

                        @if ($openTaskComments->isEmpty())
                            <p class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-muted-foreground">
                                No comments yet.
                            </p>
                        @else
                            <ul class="space-y-2">
                                @foreach ($openTaskComments as $c)
                                    <li
                                        wire:key="comment-{{ $c->id }}"
                                        class="rounded-md border px-3 py-2 {{ $c->side === 'MANAGEMENT' ? 'border-border bg-muted/30' : 'border-border bg-card' }}"
                                    >
                                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                            <span class="text-sm font-medium">{{ $c->author_name }}</span>
                                            <span class="num text-xs text-muted-foreground">
                                                {{ ProjectsData::fmtDateTime($c->created_at) }}
                                            </span>
                                        </div>
                                        @if ($c->event)
                                            <p class="mt-0.5 text-xs text-muted-foreground">
                                                Posted with {{ mb_strtolower(TaskPolicy::statusLabel($c->event)) }}
                                            </p>
                                        @endif
                                        <p class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $c->body }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="space-y-2">
                            <flux:textarea
                                aria-label="Add a comment"
                                placeholder="Explain what needs changing, or answer a question…"
                                rows="2"
                                wire:model="commentBody"
                            />
                            <div class="flex justify-end">
                                <flux:button type="button" size="sm" variant="primary" wire:click="postComment">
                                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'send', 'class' => 'size-3'])</x-slot:icon>
                                    Post comment
                                </flux:button>
                            </div>
                        </div>
                    </div>

                    @if (count($actions) > 0)
                        <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                            @if (in_array('CANCELLED', $actions, true))
                                <flux:button variant="danger" wire:click="review('CANCELLED')">
                                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'ban', 'class' => 'size-4'])</x-slot:icon>
                                    Cancel task
                                </flux:button>
                            @endif
                            @if (in_array('RETURNED', $actions, true))
                                <flux:button variant="outline" wire:click="review('RETURNED')">
                                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'undo-2', 'class' => 'size-4'])</x-slot:icon>
                                    Return for rework
                                </flux:button>
                            @endif
                            @if (in_array('COMPLETED', $actions, true))
                                <flux:button variant="primary" wire:click="review('COMPLETED')">
                                    <x-slot:icon>@include('livewire.projects.partials.icon', ['icon' => 'check', 'class' => 'size-4'])</x-slot:icon>
                                    Accept task
                                </flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </flux:modal>
    @endif
</div>
