@php
    use App\Domain\Projects\TaskPolicy;
@endphp
@php
    $listGrouped = $groupList && $group === 'ALL';
    $listedParents = $groupList && $sortKey === 'stack'
        ? array_fill_keys($rows->whereNull('parent_id')->pluck('id')->all(), true)
        : [];
@endphp
<div class="hris-table-wrap hris-table-scroll">
    <table class="hris-table ppm-table ppm-task-list">
        <thead>
            <tr>
                <x-kit.sort-header field="code" label="Code" />
                <x-kit.sort-header field="task" label="Task" />
                @unless ($listGrouped)<x-kit.sort-header field="group" label="Activity" />
                @endunless
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
                        :colspan="$listGrouped ? 7 : 8"
                        :title="$taskCount === 0 ? 'No tasks visible to you yet.' : 'No task matches those filters.'"
                    />
                </tr>
            @endif
            @php
                $previousGroup = null;
            @endphp
            @foreach ($rows as $t)
                @php
                    $sectionKey = $t->effective_group_id ?? 'NONE';
                    $attached = $fileCounts[$t->id] ?? 0;
                    $discussed = $commentCounts[$t->id] ?? 0;
                @endphp
                @if ($listGrouped && $previousGroup !== $sectionKey)
                    <tr wire:key="list-group-{{ $sectionKey }}" class="bg-muted/30">
                        <td colspan="7">
                            <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
                                <span class="flex min-w-0 items-center gap-2 font-medium"><flux:icon.folder class="size-4 shrink-0 text-muted-foreground" /><span class="truncate">{{ $t->group_name ?? 'No activity' }}</span></span>
                                <span class="flex flex-wrap items-center gap-3">
                                    @if (isset($groupTotals[$sectionKey]))<x-work.progress :progress="$groupTotals[$sectionKey]->progress()" class="w-40" />
                                    @endif
                                    @if ($canCreateTasks)<flux:button type="button" size="sm" variant="ghost" icon="plus" wire:click="openTaskCreation('{{ $sectionKey }}')">Add task</flux:button>
                                    @endif
                                </span>
                            </div>
                        </td>
                    </tr>
                @endif
                @php
                    $previousGroup = $sectionKey;
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
                        <div class="min-w-0 max-w-[24rem] {{ $t->isSubtask() ? 'pl-5' : '' }}">
                            @unless (isset($listedParents[$t->parent_id ?? '']))
                                <x-work.task-parent :task="$t" class="mb-1" compact />
                            @endunless
                            <div class="flex min-w-0 items-center gap-1.5">
                                @if ($t->isSubtask())<flux:icon.arrow-turn-down-right class="size-3.5 shrink-0 text-muted-foreground" aria-label="Subtask" />
                                @endif
                                <p class="truncate font-medium" title="{{ $t->title }}">{{ $t->title }}</p>
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
                            <x-work.subtask-progress :task="$t" class="mt-2" />
                        </div>
                    </td>
                    @unless ($listGrouped)
                    <td class="max-w-40">
                        @if ($t->group_name !== null)
                            <x-work.task-group :task="$t" />
                        @else
                            <span class="text-xs text-muted-foreground">No activity</span>
                        @endif
                    </td>
                    @endunless
                    <td>
                        <x-work.assignees :task="$t" class="max-w-56" />
                    </td>
                    <td class="whitespace-nowrap">
                        <span class="{{ $t->priority === 'NORMAL' ? 'text-xs text-muted-foreground' : TaskPolicy::priorityPill($t->priority) }}">
                            {{ TaskPolicy::priorityLabel($t->priority) }}
                        </span>
                    </td>
                    <td class="num whitespace-nowrap">
                        @if ($t->start_date)
                            <x-work.date :date="$t->start_date" :today="$today" />
                        @else
                            —
                        @endif
                    </td>
                    <td class="whitespace-nowrap"><x-work.task-deadline :task="$t" :today="$today" />@unless ($t->due_date)<span class="text-muted-foreground">—</span>@endunless</td>
                    <td class="whitespace-nowrap">
                        <span class="{{ TaskPolicy::statusPill($t->status) }}">{{ TaskPolicy::statusLabel($t->status) }}</span>
                        <x-work.response-progress :task="$t" class="mt-1 flex" />
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if ($canCreateTasks && $rows->count() < $rows->perPage())
    <div class="flex justify-center px-4 py-3" data-list-add-task><flux:button size="sm" variant="ghost" icon="plus" wire:click="openTaskCreation">Add task</flux:button></div>
@endif
<x-kit.pagination :paginator="$rows" noun="tasks" />
