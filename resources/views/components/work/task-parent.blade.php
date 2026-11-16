@props(['task', 'compact' => false])
@if ($task->isSubtask())
    <span {{ $attributes->class('flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground') }} title="Subtask of {{ $task->parent?->title ?? 'unavailable task' }}" aria-label="Subtask of {{ $task->parent?->title ?? 'unavailable task' }}">
        <flux:icon.arrow-turn-down-right class="size-3 shrink-0" />
        <span class="truncate">{{ $task->parent !== null ? ($compact ? $task->parent->title : 'Subtask of '.$task->parent->title) : 'Subtask' }}</span>
    </span>
@endif
