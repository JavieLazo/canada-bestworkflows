@props(['task', 'compact' => false, 'summary' => false])
@php
    use App\Livewire\Projects\ProjectsData;
    use App\Support\EmployeeIdentity;

    $crew = ProjectsData::sortedAssignees($task);
    $names = $crew === [] ? 'Unassigned' : ProjectsData::assigneeNames($task);
    $identities = implode("\n", array_map(static fn ($member) => EmployeeIdentity::meta(ProjectsData::employeeName($member), EmployeeIdentity::besideName($member)), ($summary ? array_slice($crew, 0, 5) : $crew)));
    if ($summary && count($crew) > 5) {
        $identities .= "\n".(count($crew) - 5).' others';
    }
@endphp
<span {{ $attributes->class('flex min-w-0 items-center gap-2') }} role="group" aria-label="Assignees: {{ $summary && $crew !== [] ? ProjectsData::employeeName($crew[0]).(count($crew) > 1 ? ' and '.(count($crew) - 1).(count($crew) === 2 ? ' other' : ' others') : '') : ($identities ?: $names) }}" title="{{ $identities ?: $names }}">
    <span class="flex shrink-0 -space-x-1.5" aria-hidden="true">
        @foreach (array_slice($crew, 0, $summary ? 2 : 3) as $member)
            <span class="flex size-6 items-center justify-center rounded-full border-2 border-card bg-muted text-[10px] font-semibold">{{ ProjectsData::initials($member) }}</span>
        @endforeach
    </span>
    @if ($summary && count($crew) > 2)<span class="num flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-card bg-muted text-[10px]" aria-hidden="true">+{{ count($crew) - 2 }}</span>
    @elseif ($compact && count($crew) > 3)<span class="num text-xs text-muted-foreground" aria-hidden="true">+{{ count($crew) - 3 }}</span>
    @endif
    @if ($summary && $crew !== [])
        <span class="min-w-0 text-xs"><span class="block truncate">{{ ProjectsData::employeeName($crew[0]) }}</span>@if (count($crew) > 1)<span class="block text-[10px] text-muted-foreground">{{ count($crew) - 1 }} {{ count($crew) === 2 ? 'other' : 'others' }}</span>@endif</span>
    @else
        <span @class(['truncate text-xs text-muted-foreground', 'sr-only' => $compact && $crew !== []])>{{ $names }}</span>
    @endif
</span>
