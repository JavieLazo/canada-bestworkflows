@props(['project', 'today', 'icon' => true])
@php
    $due = $project->due_date?->format('Y-m-d');
    $open = ! in_array($project->status, ['COMPLETED', 'CANCELLED'], true);
    $late = $open && $due !== null && $due < $today;
@endphp
<span {{ $attributes->class(['num inline-flex items-center gap-1.5', 'text-danger' => $late, 'text-muted-foreground' => ! $late]) }} title="{{ $late ? 'Overdue · ' : '' }}{{ $project->due_date?->format('F j, Y') ?? 'No deadline' }}">
    @if ($icon)<flux:icon.calendar-days class="size-4" aria-hidden="true" />@endif
    {{ $due === null ? 'No deadline' : \App\Livewire\Projects\ProjectsData::fmtDate($project->due_date) }}
    @if ($late)<span class="sr-only">Overdue</span>
    @endif
</span>
