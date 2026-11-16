@props(['task', 'compact' => false])
@if ($task->has_subtasks)
    @php
        $subtaskProgress = $task->subtaskProgress();
    @endphp
    @if ($task->subtasks_count > 0)
        <span {{ $attributes->class('flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground') }} title="{{ $subtaskProgress['completed'] }}/{{ $subtaskProgress['total'] }} subtasks">
            @if ($compact)<flux:icon.rectangle-stack class="size-3" aria-hidden="true" />@endif
            <span role="progressbar" aria-label="Visible subtask progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $subtaskProgress['percent'] }}" @class(['h-1 overflow-hidden rounded-full bg-muted', 'w-8' => $compact, 'w-16' => ! $compact])><span class="block h-full bg-primary" style="width: {{ $subtaskProgress['percent'] }}%"></span></span>
            <span class="num whitespace-nowrap">{{ $subtaskProgress['completed'] }}/{{ $subtaskProgress['total'] }}{{ $compact ? '' : ' subtasks' }}</span>
        </span>
    @else
        <span {{ $attributes->class('text-xs text-muted-foreground') }}>Subtasks</span>
    @endif
@endif
