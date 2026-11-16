@props(['task', 'compact' => false])
@if ($task->isIndividual() && ! \App\Domain\Projects\TaskPolicy::isClosed($task->status))
    @php($progress = $task->responseProgress())
    @if ($progress['assignees'] > 0)
        <span {{ $attributes->class('inline-flex items-center gap-1.5 text-xs text-muted-foreground') }} role="img" aria-label="{{ $task->responseSummary() }}" title="{{ $task->responseSummary() }}">
            <flux:icon.check-circle class="size-3.5" aria-hidden="true" />
            <span @class(['flex h-1.5 overflow-hidden rounded-full bg-muted', 'w-8' => $compact, 'w-12' => ! $compact]) aria-hidden="true">
                @foreach (['approved' => 'bg-success', 'submitted' => 'bg-primary', 'returned' => 'bg-warning'] as $state => $color)
                    <span class="{{ $color }}" style="width: {{ 100 * $progress[$state] / $progress['assignees'] }}%"></span>
                @endforeach
            </span>
            <span class="num" aria-hidden="true">{{ $progress['approved'] }}/{{ $progress['assignees'] }}</span>
        </span>
    @endif
@endif
