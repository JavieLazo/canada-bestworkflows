{{--
    One employee's month on the Shift Roster (EmployeeSchedulePage), its own
    screen: back to the week table, the employee's name, position, place and
    usual week, then one card — Default schedule on the left of its header,
    the month paging on the right, and the calendar.

    Each date is a box with its day number, a holiday's name, the shift and
    hours, and where it is worked; today is green, picked dates blue (today
    keeps its green "Today" when picked), a date on whole-day leave cannot
    be picked, nor a past date — except one with no shift while employed,
    drawn with a warning border, which is picked apart from later dates and
    filled (FillUnscheduledDates). The short-rest alert sits at the box's
    top right — a sibling of the day's button, never inside it. Picking
    dates — in the browser, with no request — opens the side card on the
    right (below the calendar on a narrow screen): the dates in short, then
    Shift and Location for all of them, Save / Cancel, and for one date
    inside a temporary schedule of several dates, removing that schedule.
    A Default schedule saved for a later date is listed under the header
    with Cancel. A viewer without all-data ROSTER reads the month and
    changes nothing.

    The Default schedule's body is drawn only while it holds a draft, which
    closing it drops; both dialogs are opened by an action, never a
    client-side trigger.
--}}
@php
    use App\Domain\Attendance\CalendarDays;
    use App\Livewire\Roster\Hris;
    use App\Livewire\Roster\Support\RosterDayText;
    use App\Modules\Timekeeping\Domain\Roster\CustomHours;
    use App\Support\HrisFormat;

    $dates = CalendarDays::each($first, $last);
    $lead = (CalendarDays::dayOfWeek($first) + 6) % 7;
@endphp
<div class="space-y-6">
    <div class="space-y-1">
        <a href="{{ route('roster.index') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
            <flux:icon.chevron-left variant="micro" />
            Shift Roster
        </a>
        <h1 class="text-2xl font-bold tracking-tight">{{ $name }}</h1>
        <p class="text-sm text-muted-foreground" data-usual-week>
            {{ $employee->position ?? 'No position' }}{{ $place !== '' ? ' · '.$place : '' }} · Usual week: {{ $usualWeek }}
        </p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            @if ($canEdit)
                <flux:button variant="outline" wire:click="openDefaultSchedule">Default Schedule</flux:button>
            @endif
            <div class="ml-auto flex items-center gap-1">
                <flux:button size="sm" variant="outline" icon="chevron-left" wire:click="previousMonth" aria-label="Previous month" />
                <strong class="num min-w-[9.5rem] text-center text-sm" data-month-label>{{ RosterDayText::monthLabel($first) }}</strong>
                <flux:button size="sm" variant="outline" icon="chevron-right" wire:click="nextMonth" aria-label="Next month" />
            </div>
        </header>

        {{-- A Default schedule saved for a later date, still to start: what it
             sets, and Cancel while it can be. --}}
        @if ($upcoming !== [])
            <ul class="divide-y divide-border border-b border-border text-sm" data-upcoming-changes>
                @foreach ($upcoming as $changeId => $change)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5" wire:key="upcoming-{{ $changeId }}">
                        <span class="pill pill-caution">From {{ HrisFormat::date($change['from']) }}</span>
                        <span class="min-w-0 flex-1 text-muted-foreground">Default schedule saved, not yet in effect{{ $change['sets'] !== [] ? ': '.implode(' · ', $change['sets']) : '' }}</span>
                        <flux:button size="sm" variant="outline" wire:click="cancelChange(@js($changeId))" wire:loading.attr="disabled" wire:target="cancelChange">Cancel This Change</flux:button>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- PICKING HAPPENS IN THE BROWSER: a click turns the date blue at
             once, with no request. `picked` is entangled (deferred) with the
             component's draft, so Save — the one request — carries the dates,
             and the server's own clearing (after a save, a month change)
             comes back. The card starts on what the first date has now, from
             `card` (the facts the server put on the page for every date that
             can be picked); its fields are wire:model drafts set here, sent
             with Save. The server checks every picked date again on Save. --}}
        <div
            @class(['grid items-start gap-4 p-4 sm:p-5', 'lg:grid-cols-[minmax(0,1fr)_20rem]' => $picked !== []])
            x-data="{
                picked: $wire.entangle('picked'),
                card: @js($card),
                blank: @js(CustomHours::BLANK),
                months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                {{-- A past date with no shift and a later date are never picked
                     together: picking the other kind starts a new selection. --}}
                toggle(date) {
                    const fresh = this.picked.length > 0 && !! this.card[this.picked[0]] && !! this.card[date] && this.card[this.picked[0]].past !== this.card[date].past;
                    const kept = fresh ? [] : this.picked;
                    const next = kept.includes(date) ? kept.filter((d) => d !== date) : [...kept, date].sort();
                    this.picked = next;
                    if (fresh || next.length <= 1) this.start(next.length ? this.card[next[0]] : null);
                },
                past() {
                    return this.picked.length > 0 && !! (this.card[this.picked[0]] && this.card[this.picked[0]].past);
                },
                start(day) {
                    this.$wire.dayShift = day ? day.shift : 'DEFAULT';
                    this.$wire.dayCustom = day ? { ...day.custom } : { ...this.blank };
                    this.$wire.dayLocation = day ? day.location : 'DEFAULT';
                },
                clear() {
                    this.picked = [];
                    this.start(null);
                },
                one() {
                    return this.picked.length === 1 ? (this.card[this.picked[0]] ?? null) : null;
                },
                heading() {
                    return this.one() ? this.one().heading : this.picked.length + ' days';
                },
                {{-- RosterDayText::compact's twin: the month once, a run as a range — Nov 18, 20, 28–29. --}}
                compact() {
                    const next = (d) => { const t = new Date(d + 'T00:00:00Z'); t.setUTCDate(t.getUTCDate() + 1); return t.toISOString().slice(0, 10); };
                    const name = (d) => this.months[Number(d.slice(5, 7)) - 1];
                    const out = [];
                    let month = '';
                    for (let i = 0; i < this.picked.length; i++) {
                        const from = this.picked[i];
                        let to = from;
                        while (i + 1 < this.picked.length && next(to) === this.picked[i + 1]) to = this.picked[++i];
                        let label = (from.slice(0, 7) !== month ? name(from) + ' ' : '') + Number(from.slice(8, 10));
                        if (from !== to) label += '–' + (to.slice(0, 7) !== from.slice(0, 7) ? name(to) + ' ' : '') + Number(to.slice(8, 10));
                        month = to.slice(0, 7);
                        out.push(label);
                    }
                    return out.join(', ');
                },
            }"
            x-bind:class="picked.length ? 'lg:grid-cols-[minmax(0,1fr)_20rem]' : ''"
        >
            <div class="grid grid-cols-7 gap-1 sm:gap-1.5" role="group" aria-label="{{ RosterDayText::monthLabel($first) }}">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                    <span class="text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ $weekday }}</span>
                @endforeach
                @for ($i = 0; $i < $lead; $i++)
                    <span aria-hidden="true"></span>
                @endfor
                @foreach ($dates as $date)
                    @php
                        $day = $days[$date] ?? null;
                        $isToday = $date === $today;
                        $isPicked = in_array($date, $picked, true);
                        $onLeave = $day?->leave !== null && ! $day->half_day;
                        $locked = ! isset($card[$date]);
                        // A past date with no shift while employed: it holds payroll until filled.
                        $fillable = ! $locked && $date < $today;
                        $note = $day === null ? null : RosterDayText::restNote($days[CalendarDays::previous($date)] ?? null, $day);
                        $title = RosterDayText::longDay($date).($day?->holiday !== null ? ' · '.$day->holiday : '').' · '.($day === null ? '—' : ($day->leave ?? ($day->works ? $day->label.' '.$day->hours : $day->label)))
                            .($note !== null ? ' · less than 8 hours after the shift before' : '')
                            .($fillable ? ' · had no shift: pick it to set what it was' : ($date < $today ? ' · past dates cannot change' : ''));
                    @endphp
                    <div class="relative" wire:key="day-{{ $date }}">
                        {{-- Picked is aria-pressed, which the browser flips at once;
                             the server draws it too from the synced draft, so a
                             morph never undoes it. The aria-pressed: classes win
                             over today's green and the plain box. --}}
                        <button
                            type="button"
                            @unless ($locked) x-on:click="toggle('{{ $date }}')" x-bind:aria-pressed="picked.includes('{{ $date }}') ? 'true' : 'false'" @endunless
                            @disabled($locked)
                            aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                            title="{{ $title }}"
                            data-date="{{ $date }}"
                            @class([
                                'grid h-full min-h-[4.5rem] w-full content-start gap-0.5 rounded-lg border p-1.5 text-left text-xs transition-colors sm:min-h-[5.5rem] sm:p-2',
                                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                'aria-pressed:border-primary aria-pressed:bg-primary-soft aria-pressed:ring-2 aria-pressed:ring-inset aria-pressed:ring-primary',
                                'cursor-pointer hover:bg-accent' => ! $locked,
                                'cursor-default' => $locked,
                                'opacity-55' => $locked && ! $isToday && $canEdit,
                                'border-dashed' => $onLeave || $fillable,
                                'border-success bg-success-soft' => $isToday,
                                'border-warning bg-card' => $fillable,
                                'border-border bg-card' => ! $isToday && ! $fillable,
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
                            @elseif ($onLeave && ! $fillable)
                                <b class="text-[13px]">{{ RosterDayText::leaveWord($day) }}</b>
                                <span class="hidden text-muted-foreground sm:block">{{ $day->leave }}</span>
                            @elseif ($fillable)
                                <span class="pill pill-caution w-fit">No shift</span>
                                <span class="hidden text-muted-foreground sm:block">Pick to set</span>
                            @elseif (! $day->rostered)
                                <b class="text-[13px] font-medium text-muted-foreground">No shift</b>
                            @elseif (! $day->works)
                                <b class="text-[13px] font-medium text-muted-foreground">Off</b>
                            @else
                                <b class="break-words text-[13px]">{{ $day->custom ? $day->hours : $day->label }}</b>
                                @if (! $day->custom || $day->label === CustomHours::NAME)
                                    <span class="hidden text-muted-foreground sm:block">{{ $day->custom ? 'Custom hours' : $day->hours }}</span>
                                @endif
                                <span class="hidden font-medium sm:block">{{ RosterDayText::place($day, $sites, $employee->work_site_id) }}</span>
                                @if ($day->leave !== null)
                                    <span class="hidden text-muted-foreground sm:block" title="{{ $day->leave }} · half day">{{ RosterDayText::leaveWord($day) }}</span>
                                @endif
                            @endif
                        </button>
                        @if ($note !== null)
                            @include('livewire.roster.partials.rest-alert', ['note' => $note, 'name' => $name])
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($canEdit)
                <aside
                    class="grid content-start gap-3 rounded-xl border border-border bg-card p-4 lg:sticky lg:top-4"
                    aria-labelledby="picked-heading"
                    data-picked-card
                    @style(['display: none' => $picked === []])
                    x-show="picked.length > 0"
                >
                    <div>
                        <h2 class="text-base font-semibold" id="picked-heading" x-text="heading()">
                            {{ count($picked) === 1 ? RosterDayText::longDay($picked[0]) : count($picked).' days' }}
                        </h2>
                        <p class="text-xs text-muted-foreground" data-picked-dates x-show="picked.length > 1" x-text="compact()" @style(['display: none' => count($picked) < 2])>{{ RosterDayText::compact($picked) }}</p>
                    </div>

                    {{-- Past dates that had no shift: what saving them does. --}}
                    @php
                        $pastPicked = $picked !== [] && $picked[0] < $today;
                        $oneTemporary = count($picked) === 1 ? ($card[$picked[0]]['temporary'] ?? null) : null;
                    @endphp
                    <p class="rounded-lg border border-dashed border-warning px-3 py-2 text-xs text-muted-foreground" data-picked-past x-show="past()" @style(['display: none' => ! $pastPicked])>
                        These dates had no shift, so the DTR measured them against 8:00 AM–5:00 PM on weekdays, and payroll cannot be finalized over them.
                        Saving sets what they were. If the employee worked, enter the punches in eDTR.
                    </p>

                    <x-kit.select label="Shift" wire:model="dayShift" aria-label="Shift for the picked dates">
                        {{-- Past dates start on nothing chosen: they have no usual schedule to go back to. --}}
                        <flux:select.option value="" :hidden="! $pastPicked" x-bind:hidden="! past()">Choose a shift…</flux:select.option>
                        @foreach ($patterns as $p)
                            <flux:select.option value="{{ $p->id }}">{{ $p->name }} · {{ Hris::patternHours($p) }}</flux:select.option>
                        @endforeach
                        <flux:select.option value="REST">Rest day (day off)</flux:select.option>
                        <flux:select.option value="CUSTOM">Custom hours…</flux:select.option>
                        {{-- The hours a deleted pattern left on a date, kept exactly
                             as they are (flexible or with a flat break included):
                             custom hours could not reproduce every such copy.
                             On the page while the month has such a date, and
                             offered (not hidden) only while every picked date
                             has one. --}}
                        @if ($cardKeeps)
                            <flux:select.option
                                value="KEEP"
                                :hidden="$picked === [] || array_any($picked, static fn (string $d): bool => ($card[$d]['shift'] ?? null) !== 'KEEP')"
                                x-bind:hidden="! (picked.length && picked.every((d) => card[d] && card[d].shift === 'KEEP'))"
                                x-text="one() && one().keep ? 'Saved hours · ' + one().keep + ' (pattern deleted)' : 'Keep the saved hours (pattern deleted)'"
                            >Keep the saved hours (pattern deleted)</flux:select.option>
                        @endif
                        <flux:select.option value="DEFAULT" :hidden="$pastPicked" x-bind:hidden="past()" x-text="one() ? 'Usual schedule · ' + one().usual : 'Back to the usual schedule'">Back to the usual schedule</flux:select.option>
                    </x-kit.select>

                    {{-- Drawn always and shown while the shift is Custom hours, so
                         choosing it asks the server nothing; the fields are
                         drafts the first picked date fills (start()). --}}
                    <div class="grid grid-cols-2 gap-3" data-picked-custom x-show="$wire.dayShift === 'CUSTOM'" @style(['display: none' => $dayShift !== 'CUSTOM'])>
                        <div class="space-y-1">
                            <label for="day-custom-start" class="text-sm font-medium">Start</label>
                            <x-kit.time-field id="day-custom-start" wire:model="dayCustom.start" aria-label="Custom hours start" />
                        </div>
                        <div class="space-y-1">
                            <label for="day-custom-end" class="text-sm font-medium">End</label>
                            <x-kit.time-field id="day-custom-end" wire:model="dayCustom.end" aria-label="Custom hours end" />
                        </div>
                        {{-- The same fields as the Default schedule's custom hours. --}}
                        <div class="space-y-1">
                            <label for="day-custom-break-start" class="text-sm font-medium">Break From</label>
                            <x-kit.time-field id="day-custom-break-start" wire:model="dayCustom.break_start" aria-label="Custom hours break from" />
                        </div>
                        <div class="space-y-1">
                            <label for="day-custom-break-end" class="text-sm font-medium">Break To</label>
                            <x-kit.time-field id="day-custom-break-end" wire:model="dayCustom.break_end" aria-label="Custom hours break to" />
                        </div>
                        <flux:input type="number" size="sm" min="0" max="600" label="Grace (min)" wire:model="dayCustom.grace" aria-label="Custom hours grace minutes" />
                        <p class="col-span-2 text-xs text-muted-foreground">Hours not in Shifting Settings. A break window is unpaid; leave it blank for none.</p>
                    </div>

                    <x-kit.select label="Location" wire:model="dayLocation" aria-label="Location for the picked dates">
                        <flux:select.option value="DEFAULT" x-text="one() && one().location === 'DEFAULT' ? 'Use weekly location (' + one().place + ')' : 'Use weekly location'">Use weekly location</flux:select.option>
                        <flux:select.option value="WFH">Work from home</flux:select.option>
                        {{-- An inactive site is offered only where the picked dates
                             already have it: on the page while a date of the month
                             names it, hidden unless every picked date does. --}}
                        @foreach ($sites as $s)
                            @continue(! $s->is_active && ! in_array($s->id, $cardSites, true))
                            @if ($s->is_active)
                                <flux:select.option value="{{ $s->id }}">{{ $s->name }}</flux:select.option>
                            @else
                                <flux:select.option
                                    value="{{ $s->id }}"
                                    :hidden="$picked === [] || array_any($picked, static fn (string $d): bool => ($card[$d]['location'] ?? null) !== $s->id)"
                                    x-bind:hidden="! (picked.length && picked.every((d) => card[d] && card[d].location === @js($s->id)))"
                                >{{ $s->name }} (inactive)</flux:select.option>
                            @endif
                        @endforeach
                    </x-kit.select>

                    <p class="text-xs text-muted-foreground" x-text="past() ? 'Click more past dates with no shift to set them together.' : 'Click more dates to change them together.'">{{ $pastPicked ? 'Click more past dates with no shift to set them together.' : 'Click more dates to change them together.' }}</p>

                    <div class="flex items-center gap-2">
                        <flux:button variant="primary" wire:click="saveDays" wire:loading.attr="disabled" wire:target="saveDays">Save</flux:button>
                        <flux:button variant="outline" x-on:click="clear()">Cancel</flux:button>
                    </div>

                    {{-- One date inside a temporary schedule of several dates:
                         take the whole schedule away (from the next date it
                         can still change, once it has begun). --}}
                    <div class="space-y-2 border-t border-border pt-3" data-picked-temporary x-show="one() && one().temporary" @style(['display: none' => $oneTemporary === null])>
                        <p class="text-xs text-muted-foreground">
                            Part of a temporary schedule, <span class="num" x-text="one() && one().temporary ? one().temporary.span : ''">{{ $oneTemporary['span'] ?? '' }}</span>.
                            Removing it puts its dates back on the usual schedule; dates already past keep theirs.
                        </p>
                        <flux:button size="sm" variant="outline" x-on:click="$wire.removeTemporary(one().temporary.id)" wire:loading.attr="disabled" wire:target="removeTemporary">Remove the Whole Temporary Schedule</flux:button>
                    </div>
                </aside>
            @endif
        </div>
    </section>

    {{-- Default schedule — the employee's usual week: a shift and a place per
         weekday, from an effective date. The selects are wire:model drafts
         the command re-validates (shiftDays, locationDays), and every set
         of custom hours below the table is offered on every day as
         'CUSTOM:n', replaced by its hours before the command sees it. The
         modal is a FIXED max-w-5xl: Flux's <dialog> is wire:ignore.self, so
         a class change after it opens never reaches it. Accessible name: the
         heading carries an id and x-init points aria-labelledby at it. --}}
    {{-- Closing it — Cancel, the ×, Escape — drops its draft, so a closed
         dialog is never drawn again on the next request (a review it
         raised keeps its own copy of what it showed: $reviewInput). --}}
    {{-- NOTHING IN IT ASKS THE SERVER UNTIL SAVE: the day selects and the
         custom hours are deferred drafts, all CUSTOM_SETS custom-hours slots
         are drawn (hidden while not in use), and Alpine adds and removes a
         set, labels it in the day selects and marks rest days as they are
         chosen — the server's addCustomHours / removeCustomHours /
         $restDays / Hris::customHoursLabel, mirrored. --}}
    <flux:modal
        name="default-schedule"
        class="w-full max-w-5xl scroll-pb-32"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'default-schedule-heading')"
        x-on:close="$wire.shiftDays = []; $wire.locationDays = []; $wire.customHours = []; $wire.customSlots = []"
    >
        @if ($shiftDays !== [])
        @php
            $patternIds = $patterns->pluck('id')->all();
            $sets = \App\Livewire\Roster\EmployeeSchedulePage::CUSTOM_SETS;
        @endphp
        {{-- bg-inherit down to the sticky footer, or it turns transparent
             (the eDTR correction dialog's footer). The heading alone: the
             employee's name is the page's own heading behind it. --}}
        <div
            class="space-y-5 bg-inherit"
            x-data="{
                inUse: $wire.entangle('customSlots'),
                days: $wire.entangle('shiftDays'),
                unset: @js($standing === null ? '' : 'DEFAULT'),
                standing: @js($standing !== null),
                standingWeek: @js($standingWeek),
                max: @js($sets),
                blank: @js(CustomHours::BLANK),
                slotInUse(slot) {
                    return this.inUse.map(Number).includes(slot);
                },
                addSet() {
                    for (let slot = 0; slot < this.max; slot++) {
                        if (! this.slotInUse(slot)) {
                            this.inUse = [...this.inUse.map(Number), slot].sort((a, b) => a - b);
                            return;
                        }
                    }
                },
                removeSet(slot) {
                    this.inUse = this.inUse.map(Number).filter((s) => s !== slot);
                    this.days = this.days.map((choice) => choice === 'CUSTOM:' + slot ? this.unset : choice);
                    if (this.$wire.customHours[slot]) Object.assign(this.$wire.customHours[slot], this.blank);
                },
                {{-- HrisFormat::hm's twin: 07:00 reads 7:00 AM. --}}
                clockText(value) {
                    const [h, m] = String(value).split(':').map(Number);
                    return (h % 12 || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h >= 12 ? 'PM' : 'AM');
                },
                {{-- Hris::customHoursLabel's twin. --}}
                customHoursLabel(slot) {
                    const hours = this.$wire.customHours[slot] || {};
                    return hours.start && hours.end ? this.clockText(hours.start) + '–' + this.clockText(hours.end) + ' (custom)' : 'Custom hours';
                },
                {{-- render()'s $restDays, for one day: a Rest day, or a day left on the assigned shift that rests it. --}}
                restsOn(weekday) {
                    const choice = this.days[weekday];
                    return choice === 'REST' || (this.standing && choice === 'DEFAULT' && ! this.standingWeek.includes(weekday));
                },
            }"
        >
            <flux:heading size="lg" id="default-schedule-heading">Default Schedule</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                @include('livewire.roster.partials.effective-from', ['model' => 'weekFrom', 'fieldId' => 'week-from', 'hint' => false])
            </div>

            <div class="hris-table-wrap rounded-lg border border-border" wire:key="default-schedule-{{ $employeeId }}">
                <table class="hris-table hris-table-pin-first">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th>Shift</th>
                            <th>Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([1, 2, 3, 4, 5, 6, 0] as $weekday)
                            @php
                                $saved = $savedShifts[$weekday] ?? null;
                                // A copy whose pattern was deleted can only be kept as it is.
                                $keepable = $saved !== null && ! $saved->is_rest_day
                                    && ! CustomHours::isCustom($saved->shift_type_id, $saved->shift_name, $saved->shift_data)
                                    && ! in_array($saved->shift_type_id, $patternIds, true);
                            @endphp
                            <tr wire:key="week-row-{{ $weekday }}">
                                <td class="whitespace-nowrap align-top">
                                    <p class="pt-1.5 font-medium">{{ HrisFormat::weekday($weekday, short: false) }}</p>
                                    <p class="text-xs text-muted-foreground" data-rest-day-hint x-show="restsOn({{ $weekday }})" @style(['display: none' => ! in_array($weekday, $restDays, true)])>Rest day</p>
                                </td>
                                <td class="min-w-[11rem] align-top" wire:key="shift-cell-{{ $weekday }}">
                                    <x-kit.select id="shift-day-{{ $weekday }}" size="sm" wire:model="shiftDays.{{ $weekday }}" aria-label="{{ HrisFormat::weekday($weekday, short: false) }} shift">
                                        @if ($standing === null)
                                            <flux:select.option value="">Choose a shift…</flux:select.option>
                                        @endif
                                        {{-- The custom hours added below come first, apart from the
                                             patterns, so a set just added is found at once: every
                                             slot is here, hidden while it is not in use. --}}
                                        @for ($slot = 0; $slot < $sets; $slot++)
                                            <flux:select.option
                                                value="CUSTOM:{{ $slot }}"
                                                :hidden="! in_array($slot, $customSlots, true)"
                                                x-bind:hidden="! slotInUse({{ $slot }})"
                                                x-text="customHoursLabel({{ $slot }})"
                                            >{{ $customOptions['CUSTOM:'.$slot] }}</flux:select.option>
                                        @endfor
                                        <hr @if ($customSlots === []) hidden @endif x-bind:hidden="inUse.length === 0">
                                        @foreach ($patterns as $p)
                                            <flux:select.option value="{{ $p->id }}">{{ $p->name }} · {{ Hris::patternHours($p) }}</flux:select.option>
                                        @endforeach
                                        @if ($keepable)
                                            <flux:select.option value="KEEP">{{ $saved->shift_name }} · {{ Hris::weekdayHours($saved) }} (pattern deleted)</flux:select.option>
                                        @endif
                                        <flux:select.option value="REST">Rest day</flux:select.option>
                                        {{-- There is no default shift: an employee without a shift
                                             assigned before gets a shift or Rest day on every day.
                                             One given a shift before keeps it on the days left to it. --}}
                                        @if ($standing !== null)
                                            <flux:select.option value="DEFAULT">Assigned shift</flux:select.option>
                                        @endif
                                    </x-kit.select>
                                </td>
                                <td class="min-w-[12rem] align-top">
                                    <x-kit.select id="loc-day-{{ $weekday }}" size="sm" wire:model="locationDays.{{ $weekday }}" aria-label="{{ HrisFormat::weekday($weekday, short: false) }} location">
                                        <flux:select.option value="DEFAULT">Assigned worksite ({{ $assignedSite?->name ?? 'none set' }})</flux:select.option>
                                        <flux:select.option value="WFH">Work from home</flux:select.option>
                                        {{-- An inactive site is offered only where this weekday already names it. --}}
                                        @foreach ($sites as $s)
                                            @continue(! $s->is_active && ($savedPlaces[$weekday] ?? null) !== $s->id)
                                            <flux:select.option value="{{ $s->id }}">{{ $s->name }}{{ $s->is_active ? '' : ' (inactive)' }}</flux:select.option>
                                        @endforeach
                                    </x-kit.select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Hours that are not in Shifting Settings. Each set is offered on
                 every day above, like a pattern. --}}
            <section class="space-y-2" data-custom-hours>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-medium">Custom Hours</p>
                    <flux:button size="sm" variant="outline" icon="plus" x-on:click="addSet()" x-bind:disabled="inUse.length >= max">Add Hours</flux:button>
                </div>
                <div class="space-y-2" x-show="inUse.length > 0" @style(['display: none' => $customSlots === []])>
                    <div class="hris-table-wrap rounded-lg border border-border">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Break From</th>
                                    <th>Break To</th>
                                    <th>Grace (min)</th>
                                    <th class="w-10"><span class="sr-only">Remove</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @for ($slot = 0; $slot < $sets; $slot++)
                                    <tr wire:key="custom-hours-{{ $slot }}" x-show="slotInUse({{ $slot }})" @style(['display: none' => ! in_array($slot, $customSlots, true)])>
                                        <td class="min-w-[9rem]"><x-kit.time-field id="custom-{{ $slot }}-start" wire:model="customHours.{{ $slot }}.start" aria-label="Custom hours {{ $slot + 1 }} start" /></td>
                                        <td class="min-w-[9rem]"><x-kit.time-field id="custom-{{ $slot }}-end" wire:model="customHours.{{ $slot }}.end" aria-label="Custom hours {{ $slot + 1 }} end" /></td>
                                        <td class="min-w-[9rem]"><x-kit.time-field id="custom-{{ $slot }}-break-start" wire:model="customHours.{{ $slot }}.break_start" aria-label="Custom hours {{ $slot + 1 }} break from" /></td>
                                        <td class="min-w-[9rem]"><x-kit.time-field id="custom-{{ $slot }}-break-end" wire:model="customHours.{{ $slot }}.break_end" aria-label="Custom hours {{ $slot + 1 }} break to" /></td>
                                        <td class="w-24"><flux:input type="number" size="sm" min="0" max="600" wire:model="customHours.{{ $slot }}.grace" aria-label="Custom hours {{ $slot + 1 }} grace minutes" /></td>
                                        <td class="text-right"><flux:button size="sm" variant="ghost" icon="trash" x-on:click="removeSet({{ $slot }})" aria-label="Remove custom hours {{ $slot + 1 }}" /></td>
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ $standing === null ? 'Removing a set leaves the days that use it to be chosen again.' : 'Removing a set puts the days that use it back on Assigned shift.' }}</p>
                </div>
            </section>

            {{-- Sticky, so Save stays in reach however long the table is. --}}
            <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 flex items-center justify-end gap-2 border-t border-border bg-inherit px-6 py-3">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="saveDefaultSchedule" wire:loading.attr="disabled" wire:target="saveDefaultSchedule">
                    Save Default Schedule
                </flux:button>
            </div>
        </div>
        @endif
    </flux:modal>

    @include('livewire.roster.partials.schedule-review', ['backAction' => 'backToEditor'])
</div>
