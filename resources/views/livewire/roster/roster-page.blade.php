{{--
    Shift Roster — one week of everyone's schedule (RosterPage): a row per
    active employee, a column per day of the week, Sunday to Saturday (the
    order is `WorkWeek`'s, through RosterDayText). Each day names
    the shift that applies on it and its hours, "Off" on a rest day, "No
    shift" where nothing is rostered (no Time In), and approved leave as a
    pill where the viewer may see it. A red alert at a day's top right
    marks a shift that starts less than eight hours after the one before
    ended; clicking it says why, and nothing stops the schedule being
    saved as it is.

    The whole row opens the employee's month (roster.employee), the only
    place a schedule is changed; the name is also a real link, and Enter or
    Space opens the focused row. Today is not marked here: the employee's
    month marks it.

    Search, the department filter and pagination are the directory's; the
    week paging sits on the right of the card's header. No status, position
    or pattern filter, no sort, no row actions and no CSV.
--}}
@php
    use App\Livewire\Roster\Hris;
    use App\Livewire\Roster\Support\RosterDayText;
    use App\Support\HrisFormat;
    use App\Domain\Attendance\CalendarDays;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Shift Roster</h1>
        <p class="text-sm text-muted-foreground">
            Each day shows the shift the employee works that day. A change is in force as soon as it is saved;
            past dates and posted payslips never change. Click a row to see that employee’s whole month and change it.
        </p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search employees"
                placeholder="Search name or position"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />

            <x-kit.filter-select label="Filter by Department" class="w-[13rem]" wire:model.live="department">
                @foreach ($departments as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <div class="ml-auto flex items-center gap-1">
                <flux:button size="sm" variant="outline" icon="chevron-left" wire:click="previousWeek" aria-label="Previous week" />
                <strong class="num min-w-[11.5rem] text-center text-sm" data-week-label>{{ RosterDayText::weekLabel($weekStart) }}</strong>
                <flux:button size="sm" variant="outline" icon="chevron-right" wire:click="nextWeek" aria-label="Next week" />
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table hris-table-pin-first">
                <thead>
                    <tr>
                        <th>Employee</th>
                        @foreach ($days as $date)
                            <th class="num">
                                {{ HrisFormat::weekday(CalendarDays::dayOfWeek($date)) }} {{ (int) substr($date, 8, 2) }}
                                @isset($holidays[$date])
                                    <span class="block max-w-[8.5rem] truncate font-sans text-[11px] font-medium normal-case text-muted-foreground" title="Holiday · {{ $holidays[$date] }}" data-holiday>{{ $holidays[$date] }}</span>
                                @endisset
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $employee)
                        @php
                            $name = Hris::employeeName($employee);
                            $url = route('roster.employee', ['employee' => $employee->id, 'month' => substr($weekStart, 0, 7)]);
                            $week = $calendar[$employee->id] ?? [];
                            $place = $places[$employee->id] ?? '';
                        @endphp
                        <tr
                            wire:key="row-{{ $employee->id }}"
                            tabindex="0"
                            class="cursor-pointer focus-visible:bg-accent focus-visible:outline-none"
                            aria-label="Open {{ $name }}’s month"
                            x-data
                            x-on:click="if (! $event.target.closest('a, button, [popover]')) Livewire.navigate(@js($url))"
                            x-on:keydown.enter.self="Livewire.navigate(@js($url))"
                            x-on:keydown.space.self.prevent="Livewire.navigate(@js($url))"
                        >
                            {{-- Pinned while the days scroll sideways, so its content is
                                 capped (hris-table-pin-first). --}}
                            <td>
                                <div class="w-[11rem] sm:w-[14rem]">
                                    <div class="flex min-w-0 items-center gap-1.5">
                                        <a href="{{ $url }}" wire:navigate class="block truncate font-medium hover:underline" tabindex="-1">{{ $name }}</a>
                                        @unless ($employee->is_active)
                                            <span class="pill pill-muted shrink-0" title="Left on {{ HrisFormat::date($employee->date_resigned) }}">Left</span>
                                        @endunless
                                    </div>
                                    <p class="truncate text-xs text-muted-foreground" title="{{ $employee->position ?? '' }}{{ $place !== '' ? ' · '.$place : '' }}">{{ $employee->position ?? '—' }}{{ $place !== '' ? ' · '.$place : '' }}</p>
                                </div>
                            </td>
                            @foreach ($days as $date)
                                @php
                                    $day = $week[$date] ?? null;
                                    $note = $day === null ? null : RosterDayText::restNote($week[CalendarDays::previous($date)] ?? null, $day);
                                @endphp
                                {{-- A fixed width, so a long shift name wraps inside its day
                                     instead of widening the column and pushing the week
                                     off-screen. The alert's corner is kept clear only on a
                                     day that has one. --}}
                                <td @class(['relative w-[9.5rem] min-w-[9.5rem] max-w-[9.5rem] pl-3', 'pr-7' => $note !== null, 'pr-3' => $note === null]) data-day="{{ $date }}">
                                    @include('livewire.roster.partials.day-cell', ['day' => $day])
                                    @if ($note !== null)
                                        @include('livewire.roster.partials.rest-alert', ['note' => $note, 'name' => $name])
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="8" title="No employee matches those filters." />
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" noun="employees" />
    </section>
</div>
