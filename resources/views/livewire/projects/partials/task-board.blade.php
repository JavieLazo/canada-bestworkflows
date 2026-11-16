@php
    use App\Domain\Projects\TaskPolicy;
@endphp
<div
    wire:key="task-board-{{ $project->id }}"
    class="ppm-board max-w-full pb-3"
    data-work-pan
    role="region"
    aria-label="Task board"
    tabindex="0"
    x-data="taskBoard($wire)"
    x-on:dragend="dragged = null; over = null"
    x-bind:aria-busy="moving"
>
    <div class="flex items-start gap-3 pb-2">
        @foreach ($board as $laneStatus => $lane)
            <section
                wire:key="board-{{ $laneStatus }}"
                aria-label="{{ TaskPolicy::statusLabel($laneStatus) }} tasks"
                data-board-status="{{ $laneStatus }}"
                class="ppm-lane flex w-72 shrink-0 flex-col p-3"
                x-bind:class="over === '{{ $laneStatus }}' && 'ring-2 ring-primary'"
                x-on:dragover.prevent="if (dragged && dragged.from !== '{{ $laneStatus }}') { over = '{{ $laneStatus }}'; $event.dataTransfer.dropEffect = 'move'; }"
                x-on:dragleave.self="over = null"
                x-on:drop.prevent="drop('{{ $laneStatus }}')"
            >
                <header class="flex items-center gap-2 px-2 pb-3 pt-1">
                    <h3><span class="{{ TaskPolicy::statusPill($laneStatus) }}">{{ TaskPolicy::statusLabel($laneStatus) }}</span></h3>
                    <span class="ppm-count" data-board-count data-total="{{ $lane->total() }}">{{ $lane->total() }}</span>
                    @if ($canCreateTasks)
                        <flux:button size="sm" variant="ghost" square icon="plus" class="ml-auto" wire:click="openTaskCreation(null, '{{ $laneStatus }}')" aria-label="Add a task to {{ TaskPolicy::statusLabel($laneStatus) }}" title="Add task" />
                    @endif
                </header>
                <div class="space-y-2" data-board-items>
                    @foreach ($lane as $task)
                        @php
                            $movable = $taskAuthority[$task->id] ?? false;
                            $attached = $fileCounts[$task->id] ?? 0;
                            $discussed = $commentCounts[$task->id] ?? 0;
                        @endphp
                        <article
                            wire:key="board-task-{{ $task->id }}"
                            data-board-task="{{ $task->id }}"
                            data-task-status="{{ $task->status }}"
                            class="ppm-task-card"
                            draggable="{{ $movable ? 'true' : 'false' }}"
                            x-bind:draggable="!moving && @js($movable)"
                            x-bind:class="dragged?.id === @js($task->id) && 'opacity-50'"
                            x-on:dragstart="start($event, @js($boardMoves[$task->id]), @js($movable))"
                        >
                            <button
                                type="button"
                                class="block w-full space-y-3 rounded-lg p-3 text-left focus-visible:outline-2 focus-visible:outline-primary"
                                wire:click="openTask('{{ $task->id }}')"
                                aria-label="Open {{ $task->code }}: {{ $task->title }}{{ $task->group_name !== null ? ' · '.$task->group_name : '' }}"
                            >
                                <span class="flex items-center justify-between gap-2"><span class="num truncate text-[11px] text-muted-foreground">{{ $task->code }}</span><x-work.task-deadline :task="$task" :today="$today" /></span>
                                <x-work.task-parent :task="$task" compact />
                                <span class="line-clamp-3 block break-words text-sm font-medium" title="{{ $task->title }}">{{ $task->title }}</span>
                                <x-work.task-group :task="$task" />
                                @if ($task->priority !== 'NORMAL')<span class="{{ TaskPolicy::priorityPill($task->priority) }}">{{ TaskPolicy::priorityLabel($task->priority) }}</span>
                                @endif
                                <span class="flex items-center justify-between gap-2">
                                    <x-work.assignees :task="$task" summary class="flex-1" />
                                    <span class="flex shrink-0 flex-col items-end gap-2 text-xs text-muted-foreground"><span class="flex items-center gap-2">
                                        @if ($attached > 0)
                                            <span class="flex items-center gap-1" aria-label="{{ $attached }} attached files">
                                                @include('livewire.projects.partials.icon', ['icon' => 'paperclip', 'class' => 'size-3'])
                                                <span class="num">{{ $attached }}</span>
                                            </span>
                                        @endif
                                        @if ($discussed > 0)
                                            <span class="flex items-center gap-1" aria-label="{{ $discussed }} comments">
                                                @include('livewire.projects.partials.icon', ['icon' => 'message-square', 'class' => 'size-3'])
                                                <span class="num">{{ $discussed }}</span>
                                            </span>
                                        @endif
                                    </span><x-work.subtask-progress :task="$task" compact /><x-work.response-progress :task="$task" compact /></span>
                                </span>
                            </button>
                        </article>
                    @endforeach
                    <p data-board-empty @if ($lane->isNotEmpty()) hidden @endif class="px-2 py-8 text-center text-xs text-muted-foreground">No tasks</p>
                </div>
                @if ($lane->lastPage() > 1)
                    <nav class="mt-auto flex items-center justify-between gap-2 px-1 pt-3" aria-label="{{ TaskPolicy::statusLabel($laneStatus) }} pages">
                        <flux:button size="sm" variant="ghost" square icon="chevron-left" wire:click="previousPage('board_{{ $laneStatus }}')" :disabled="$lane->onFirstPage()" aria-label="Previous {{ TaskPolicy::statusLabel($laneStatus) }} tasks" />
                        <span class="num text-xs text-muted-foreground">{{ $lane->firstItem() }}–{{ $lane->lastItem() }} of {{ $lane->total() }}</span>
                        <flux:button size="sm" variant="ghost" square icon="chevron-right" wire:click="nextPage('board_{{ $laneStatus }}')" :disabled="! $lane->hasMorePages()" aria-label="Next {{ TaskPolicy::statusLabel($laneStatus) }} tasks" />
                    </nav>
                @endif
            </section>
        @endforeach
    </div>
    <span role="status" class="sr-only" x-text="announcement"></span>
</div>
