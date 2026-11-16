@props(['task', 'today', 'own' => false])
@php
    use App\Domain\Projects\ProjectPolicy;
    use App\Domain\Projects\TaskPolicy;
    use App\Modules\WorkManagement\Domain\Tasks\TaskInbox;
    $due = $task->due_date?->format('Y-m-d');
    $status = $own ? ($task->my_status ?? $task->status) : $task->status;
    $actionable = $own ? in_array($status, TaskInbox::ACTIONABLE, true) : ! TaskPolicy::isClosed($status);
    $late = $due !== null && $actionable && $due < $today;
    $soon = $due !== null && $actionable && $due >= $today && ProjectPolicy::daysUntilDate($due, $today) <= ProjectPolicy::HEALTH_TASK_DUE_SOON_DAYS;
@endphp
@if ($due !== null)
    <span {{ $attributes->class(['num inline-flex shrink-0 items-center gap-1 text-xs', 'font-medium text-danger' => $late, 'font-medium text-warning' => $soon, 'text-muted-foreground' => ! $late && ! $soon]) }} title="{{ $task->due_date->format('F j, Y') }}{{ $late ? ' · Overdue' : ($soon ? ' · Due soon' : '') }}">
        <flux:icon.calendar-days class="size-3.5" aria-hidden="true" />
        <x-work.date :date="$task->due_date" :today="$today" />
        @if ($late || $soon)<span class="sr-only">{{ $late ? 'Overdue' : 'Due soon' }}</span>
        @endif
    </span>
@endif
