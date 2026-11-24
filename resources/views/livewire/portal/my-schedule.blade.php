{{--
    My Portal › My schedule (Portal\MySchedule): the employee's own month,
    read only, drawn like the Shift Roster's employee month — a box per date
    with its number (today green), a holiday's name, the shift and hours,
    and where it is worked; "No shift" where nothing is rostered (no Time In
    that day), "Off" on a rest day, the leave in one word. The usual week
    sits under the heading. HR changes it on the Shift Roster.
--}}
@php
    use App\Domain\Attendance\CalendarDays;
    use App\Livewire\Roster\Support\RosterDayText;
    use App\Modules\Timekeeping\Domain\Roster\CustomHours;
    use App\Modules\Timekeeping\Domain\Roster\LocationKind;

    $dates = CalendarDays::each($first, $last);
    $lead = RosterDayText::monthLead($first);
    $placeOf = static fn ($day): string => match (true) {
        $day->place_kind === LocationKind::WFH => 'Work from home',
        $day->place_kind === LocationKind::ONSITE && $day->place_site_id !== null => $sites->get($day->place_site_id)->name ?? 'Assigned worksite',
        default => 'Assigned worksite',
    };
@endphp
<section class="hris-card overflow-hidden" data-my-schedule>
    <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
        <div class="min-w-0">
            <h2 class="text-base font-bold">My Schedule</h2>
            <p class="text-sm text-muted-foreground" data-my-usual-week>
                @if ($linked && $usualWeek !== '')
                    Usual week: {{ $usualWeek }}. HR sets it in Shift Roster, and you are told when it changes.
                @else
                    HR sets your schedule in Shift Roster, and you are told when it changes.
                @endif
            </p>
        </div>
        <div class="ml-auto flex items-center gap-1">
            <flux:button size="sm" variant="outline" icon="chevron-left" wire:click="previousMonth" aria-label="Previous month" />
            <strong class="num min-w-[9.5rem] text-center text-sm" data-month-label>{{ RosterDayText::monthLabel($first) }}</strong>
            <flux:button size="sm" variant="outline" icon="chevron-right" wire:click="nextMonth" aria-label="Next month" />
            @if (substr($today, 0, 7) !== substr($first, 0, 7))
                <flux:button size="sm" variant="ghost" wire:click="thisMonth">This Month</flux:button>
            @endif
        </div>
    </header>

    @if (! $linked)
        <p class="p-6 text-sm text-muted-foreground">Your login isn't linked to an employee record yet, so there is no schedule to show.</p>
    @else
        <div class="p-4 sm:p-5">
            <div class="grid grid-cols-7 gap-1 sm:gap-1.5" role="group" aria-label="{{ RosterDayText::monthLabel($first) }}">
                @foreach (RosterDayText::weekdayHeadings() as $weekday)
                    <span class="text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ $weekday }}</span>
                @endforeach
                @for ($i = 0; $i < $lead; $i++)
                    <span aria-hidden="true"></span>
                @endfor
                @foreach ($dates as $date)
                    @php
                        $day = $days[$date] ?? null;
                        $isToday = $date === $today;
                        $onLeave = $day?->leave !== null && ! $day->half_day;
                        $title = RosterDayText::longDay($date).($day?->holiday !== null ? ' · '.$day->holiday : '').' · '.($day === null ? '—' : ($day->leave ?? ($day->works ? $day->label.' '.$day->hours : $day->label)));
                    @endphp
                    <div
                        wire:key="my-day-{{ $date }}"
                        title="{{ $title }}"
                        data-date="{{ $date }}"
                        @class([
                            'grid min-h-[4.5rem] content-start gap-0.5 rounded-lg border p-1.5 text-xs sm:min-h-[5.5rem] sm:p-2',
                            'border-dashed' => $onLeave,
                            'border-success bg-success-soft' => $isToday,
                            'border-border bg-card' => ! $isToday,
                        ])
                    >
                        <span @class(['num text-[11px]', 'font-bold text-success' => $isToday, 'text-muted-foreground' => ! $isToday])>
                            {{ (int) substr($date, 8, 2) }}{{ $isToday ? ' · Today' : '' }}
                        </span>
                        @if ($day?->holiday !== null)
                            <span class="hidden truncate text-[11px] font-medium text-muted-foreground sm:block" data-holiday>{{ $day->holiday }}</span>
                        @endif
                        @if ($day === null)
                            <span class="text-muted-foreground">—</span>
                        @elseif ($onLeave)
                            <b class="text-[13px]">{{ RosterDayText::leaveWord($day) }}</b>
                            <span class="hidden text-muted-foreground sm:block">{{ $day->leave }}</span>
                        @elseif (! $day->rostered)
                            <b class="text-[13px] font-medium text-muted-foreground">No shift</b>
                        @elseif (! $day->works)
                            <b class="text-[13px] font-medium text-muted-foreground">Off</b>
                        @else
                            <b class="break-words text-[13px]">{{ $day->custom ? $day->hours : $day->label }}</b>
                            @if (! $day->custom || $day->label === CustomHours::NAME)
                                <span class="hidden text-muted-foreground sm:block">{{ $day->custom ? 'Custom hours' : $day->hours }}</span>
                            @endif
                            <span class="hidden font-medium sm:block">{{ $placeOf($day) }}</span>
                            @if ($day->leave !== null)
                                <span class="hidden text-muted-foreground sm:block">{{ RosterDayText::leaveWord($day) }}</span>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-muted-foreground">On a day with no shift you cannot record a Time In. Ask HR to set it in Shift Roster. Overnight hours belong to the day the shift starts.</p>
        </div>
    @endif
</section>
