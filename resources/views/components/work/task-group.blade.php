@props(['task'])
@if ($task->group_name !== null)
    <span {{ $attributes->class('flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground') }} title="{{ $task->group_name }}">
        <flux:icon.folder class="size-3 shrink-0" />
        <span class="truncate">{{ $task->group_name }}</span>
    </span>
@endif
