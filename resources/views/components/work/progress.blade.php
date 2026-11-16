@props(['progress'])
<div {{ $attributes->class('flex min-w-0 items-center gap-3') }}>
    <div class="h-1.5 min-w-12 flex-1 overflow-hidden rounded-full bg-muted" role="progressbar" aria-label="Task-count progress" title="Subtasks count once. Parent tasks still need acceptance." aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}">
        <div class="h-full rounded-full bg-primary" style="width: {{ $progress['percent'] }}%"></div>
    </div>
    <span class="num shrink-0 text-xs text-muted-foreground">{{ $progress['completed'] }}/{{ $progress['total'] }} tasks</span>
</div>
