{{--
    My Tasks — port of copems/src/app/(protected)/my-tasks/my-tasks-page.tsx.

    Same tiles, same filters, same five columns in the same order, same
    priority-first default sort, same empty-state copy. Rows open the detail
    dialog (partials/task-detail-dialog.blade.php).

    DELIBERATE DIVERGENCE: the Status column, its sort, the status filter
    and the tiles all read the member's OWN status (`my_status`,
    WorkRecords::ownPage()/ownSummary()), not the task's. They differ only on
    an INDIVIDUAL task in progress, where it is the member's response state —
    so the filter offers Approved (ProjectsData::myStatuses()), which the
    reference's task statuses do not have. On a GROUP task the two are the
    same, and the page reads as the reference's does.
--}}
@php
    use App\Domain\Projects\TaskPolicy;
    use App\Livewire\Projects\ProjectsData;
    use App\Support\ManilaClock;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">My Tasks</h1>
        <p class="text-sm text-muted-foreground">
            {{ $employee
                ? \App\Support\EmployeeName::display($employee).' · '.($employee->position ?? 'Employee')
                : 'Work assigned to you' }} — open a task to start it, or to submit what you finished for review.
        </p>
    </div>

    @if (! $linked)
        {{-- A resolved account with no employee link (my-tasks-page.tsx:172-182). --}}
        <p class="hris-card p-6 text-sm text-muted-foreground">
            Your account isn't linked to an employee record yet. Tasks are assigned per employee, so
            there is nothing to show here until HR links it.
        </p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="hris-card p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">To do</p>
                <p class="num mt-1 text-2xl font-bold">{{ $counts['todo'] }}</p>
            </div>
            <div class="hris-card p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Awaiting review</p>
                <p class="num mt-1 text-2xl font-bold">{{ $counts['awaiting'] }}</p>
            </div>
            <div class="hris-card p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Returned</p>
                <p class="num mt-1 text-2xl font-bold {{ $counts['returned'] > 0 ? 'text-danger' : '' }}">{{ $counts['returned'] }}</p>
            </div>
            <div class="hris-card p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Completed</p>
                <p class="num mt-1 text-2xl font-bold">{{ $counts['completed'] }}</p>
            </div>
        </div>

        <section class="hris-card overflow-hidden">
            <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
                <flux:input
                    aria-label="Search my tasks"
                    placeholder="Search code, task or project"
                    class="w-full min-w-[12rem] max-w-xs flex-1"
                    wire:model.live.debounce.250ms="search"
                />

                <x-kit.filter-select
                    label="Filter by status"
                    class="w-[13rem]"
                    wire:model.live="status"
                    :options="['ALL' => 'All statuses'] + ProjectsData::myStatuses()"
                />

                <x-kit.filter-select
                    label="Filter by project"
                    class="w-[15rem]"
                    wire:model.live="projectId"
                    :options="['ALL' => 'All projects'] + $myProjects->pluck('name', 'id')->all()"
                />

                <div class="ml-auto">
                    <x-kit.export-button :href="route('tasks.export', array_filter(['q' => $search, 'status' => $status, 'project' => $projectId, 'sort' => $sortKey, 'dir' => $sortDir]))" />
                </div>
            </header>

            <div class="hris-table-wrap hris-table-scroll">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <x-kit.sort-header field="code" label="Code" />
                            <x-kit.sort-header field="task" label="Task" />
                            <x-kit.sort-header field="priority" label="Priority" />
                            <x-kit.sort-header field="start" label="Start" />
                            <x-kit.sort-header field="due" label="Due" />
                            <x-kit.sort-header field="status" label="Status" />
                        </tr>
                    </thead>
                    <tbody>
                        @if ($rows->total() === 0)
                            <tr wire:key="empty">
                                <x-kit.empty-state
                                    :colspan="6"
                                    :title="$mineEmpty
                                        ? 'No tasks are assigned to you yet.'
                                        : 'No task matches those filters.'"
                                />
                            </tr>
                        @endif
                        @foreach ($rows as $t)
                            @php
                                $project = $t->project;
                                $overdue = TaskPolicy::isOverdue(
                                    ['status' => $t->status, 'due_date' => $t->due_date?->format('Y-m-d')],
                                    $today,
                                );
                                $attached = (int) ($fileCounts[$t->id] ?? 0);
                                $discussed = (int) ($commentCounts[$t->id] ?? 0);
                            @endphp
                            <tr
                                wire:key="task-{{ $t->id }}"
                                tabindex="0"
                                aria-label="Open {{ trim($t->code) !== '' ? $t->code : 'task' }}"
                                class="cursor-pointer focus:outline-none focus-visible:bg-accent"
                                wire:click="openTask('{{ $t->id }}')"
                                x-on:keydown.enter.prevent="$wire.openTask('{{ $t->id }}')"
                                x-on:keydown.space.prevent="$wire.openTask('{{ $t->id }}')"
                            >
                                <td class="num whitespace-nowrap align-middle">{{ trim($t->code) !== '' ? $t->code : '—' }}</td>
                                <td>
                                    <div class="min-w-0">
                                        <div class="flex min-w-0 items-center gap-1.5">
                                            <p class="truncate font-medium">{{ $t->title }}</p>
                                            @if ($attached > 0)
                                                <span
                                                    role="img"
                                                    class="flex shrink-0 items-center gap-0.5 text-xs text-muted-foreground"
                                                    aria-label="{{ $attached }} {{ $attached === 1 ? 'file' : 'files' }} attached"
                                                >
                                                    <flux:icon.paper-clip class="h-3 w-3" />
                                                    {{ $attached }}
                                                </span>
                                            @endif
                                            @if ($discussed > 0)
                                                <span
                                                    role="img"
                                                    class="flex shrink-0 items-center gap-0.5 text-xs text-muted-foreground"
                                                    aria-label="{{ $discussed }} {{ $discussed === 1 ? 'comment' : 'comments' }}"
                                                >
                                                    <flux:icon.chat-bubble-left class="h-3 w-3" />
                                                    {{ $discussed }}
                                                </span>
                                            @endif
                                        </div>
                                        <p class="truncate text-xs text-muted-foreground">{{ $project->name ?? '—' }}</p>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="{{ TaskPolicy::priorityPill($t->priority) }}">{{ TaskPolicy::priorityLabel($t->priority) }}</span>
                                </td>
                                <td class="num whitespace-nowrap">
                                    {{ $t->start_date !== null ? $t->start_date->format('M d, Y') : '—' }}
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($t->due_date !== null)
                                        <span class="{{ $overdue ? 'num text-danger font-medium' : 'num' }}">
                                            {{ $t->due_date->format('M d, Y') }}@if ($overdue)<span class="text-xs"> · overdue</span>@endif
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="{{ $t->myStatusPill() }}">{{ $t->myStatusLabel() }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-kit.pagination :paginator="$rows" noun="tasks" />
        </section>
    @endif

    @include('livewire.tasks.partials.task-detail-dialog')
</div>
