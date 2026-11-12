{{--
    One date of one employee in the week table (RosterDayView): approved
    leave as a pill in one word — Leave, Half-day or Travel, the words the
    employee's month uses (RosterDayText::leaveWord), the type in its title —
    "No shift" or "Off" muted, else the shift's name over
    its hours — hours with no pattern behind them lead with the hours
    themselves, at the hours' size, captioned "Custom hours" when they were
    typed in as such. A half-day leave keeps the shift beneath its pill.

    The day is a fixed width (roster-page): a name wraps to two lines at
    most and ends in "…", the full name and hours in its title; hours never
    break.

    Expects $day: RosterDayView|null (null for an employee whose days the
    viewer may not read).
--}}
@php
    use App\Livewire\Roster\Support\RosterDayText;
@endphp
@if ($day === null)
    <span class="text-muted-foreground">—</span>
@else
    @if ($day->leave !== null)
        <span class="pill pill-muted" title="{{ $day->leave }}{{ $day->half_day ? ' · half day' : '' }}">{{ RosterDayText::leaveWord($day) }}</span>
    @endif
    @if ($day->leave === null || $day->half_day)
        @if (! $day->rostered)
            <span class="block text-muted-foreground" title="Nothing is rostered: no Time In">No shift</span>
        @elseif (! $day->works)
            <span class="block text-muted-foreground" title="Rest day">Off</span>
        @else
            @if ($day->custom)
                <span class="block whitespace-nowrap text-[13px] font-medium">{{ $day->hours }}</span>
            @else
                <span class="line-clamp-2 break-words font-medium" title="{{ $day->label }} · {{ $day->hours }}">{{ $day->label }}</span>
            @endif
            @if (! $day->custom || $day->label === \App\Modules\Timekeeping\Domain\Roster\CustomHours::NAME)
                <span class="block whitespace-nowrap text-[13px] text-muted-foreground">{{ $day->custom ? 'Custom hours' : $day->hours }}</span>
            @endif
        @endif
    @endif
@endif
