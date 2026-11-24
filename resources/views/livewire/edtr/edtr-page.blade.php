{{--
    eDTR & Attendance — the port of copems/src/app/(protected)/edtr/edtr-page.tsx.
    See App\Livewire\Edtr\EdtrPage for the state and actions behind this.

    The shadcn Tabs have no Flux-free equivalent, so the TabsList/TabsTrigger
    pair is approximated with Tailwind on plain buttons; switching tabs
    re-renders server-side, which matches Radix unmounting inactive content.
--}}
@use('App\Livewire\Edtr\Support\Hris')
@use('App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy')
@use('App\Domain\Attendance\DayRecord')
@use('App\Domain\Attendance\AttendanceLog', 'DomainAttendanceLog')
@use('App\Domain\Attendance\Shift', 'DomainShift')
@use('App\Modules\Timekeeping\Domain\Roster\LocationKind')
@use('App\Modules\Timekeeping\Domain\Attendance\PunchSlots')
@use('App\Modules\Timekeeping\Domain\Attendance\CorrectionWindow')
@use('App\Support\HrisFormat')
@use('App\Domain\Attendance\CalendarDays')
@use('App\Platform\Files\PortraitUrl')

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">eDTR &amp; Attendance</h1>
            <p class="text-sm text-muted-foreground">
                Punch in and out with geofence validation, then review the computed daily time record.
            </p>
        </div>
        <livewire:shell.live-clock class="hris-card shrink-0 px-5 py-3 text-right" wire:key="edtr-clock" />
    </div>

    {{-- TabsList — shadcn's muted strip with the active trigger raised. --}}
    <div>
        <div class="inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground" role="tablist">
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'punch' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'punch')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'punch' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >Punch Station</button>
            <button
                type="button"
                role="tab"
                aria-selected="{{ $tab === 'dtr' ? 'true' : 'false' }}"
                wire:click="$set('tab', 'dtr')"
                class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition-all {{ $tab === 'dtr' ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground' }}"
            >Daily Time Record</button>
        </div>

        @if ($tab === 'punch')
            <div class="mt-4">
                <div class="grid gap-4 xl:grid-cols-[minmax(0,27rem)_minmax(0,1fr)]">
                    <livewire:edtr.punch-station
                        :locked-employee-id="$selfOnly ? ($actor->employeeId ?? null) : null"
                        :show-clock="false"
                        wire:key="edtr-punch-station-{{ $selfOnly ? ($actor->employeeId ?? 'none') : 'kiosk' }}"
                    />

                    <livewire:edtr.live-feed wire:key="edtr-live-feed" />
                </div>
            </div>
        @else
            <div class="mt-4 space-y-4">
                {{-- "Department or unit" narrows the picker's list only (its
                     `department` prop, applied after the picker's own row
                     scope); a self-only viewer has no picker and no select. --}}
                <div class="hris-card grid gap-4 p-5 {{ $selfOnly ? 'sm:grid-cols-3' : 'sm:grid-cols-2 lg:grid-cols-4' }}">
                    @unless ($selfOnly)
                        <div class="space-y-2">
                            <div class="flex min-h-5 items-center">
                                <flux:label id="dtr-department-label" for="dtr-department">Department or Unit</flux:label>
                            </div>
                            <x-kit.select id="dtr-department" wire:model.live="department" aria-label="Department or unit">
                                @foreach ($departments as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                            <p class="text-xs text-muted-foreground">Narrows the employee list to one department and its units, or to one unit.</p>
                        </div>
                    @endunless
                    <div class="space-y-2">
                        <div class="flex min-h-5 items-center">
                            <flux:label id="dtr-employee-label" for="dtr-employee">Employee</flux:label>
                        </div>
                        @if ($selfOnly)
                            <flux:input id="dtr-employee" readonly value="{{ $selfName }}" />
                        @else
                            <livewire:kit.employee-picker
                                name="dtr-employee"
                                :value="$dtrEmployee"
                                :department="$department"
                                trigger-id="dtr-employee"
                                labelled-by="dtr-employee-label"
                                wire:key="dtr-employee-picker-{{ $dtrEmployee }}-{{ $department }}"
                            />
                        @endif
                    </div>
                    <div class="space-y-2">
                        <div class="flex min-h-5 items-center justify-between gap-2">
                            <flux:label>Period Start</flux:label>
                            <button
                                type="button"
                                wire:click="thisCutoff"
                                class="text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                            >This Cut-Off</button>
                        </div>
                        <x-kit.date-field wire:model.live="start" aria-label="Period start" />
                    </div>
                    <div class="space-y-2">
                        <div class="flex min-h-5 items-center">
                            <flux:label>Period End</flux:label>
                        </div>
                        <x-kit.date-field wire:model.live="end" aria-label="Period end" />
                    </div>
                </div>

                @if ($targetId !== '')
                    @if ($employee !== null)
                        {{-- Who the record below belongs to, read straight from their 201 file. --}}
                        <section class="hris-card flex flex-wrap items-center gap-4 p-5">
                            <span class="relative flex h-12 w-12 shrink-0 overflow-hidden rounded-full bg-muted">
                                @if ($employee->photo_path)
                                    <img
                                        src="{{ PortraitUrl::for($employee->photo_path) }}"
                                        alt="{{ Hris::employeeName($employee) }}"
                                        class="aspect-square h-full w-full object-cover"
                                    />
                                @else
                                    <span class="num flex h-full w-full items-center justify-center text-sm font-semibold">
                                        {{ \App\Support\EmployeeName::initials($employee) }}
                                    </span>
                                @endif
                            </span>

                            <div class="min-w-0 flex-1">
                                {{-- The Employee ID, and no pill at all when the
                                     company has not numbered this person: an empty
                                     pill is a grey smudge beside the name. --}}
                                @php $identity = \App\Support\EmployeeIdentity::besideName($employee); @endphp
                                <p class="flex flex-wrap items-center gap-2 font-bold">
                                    {{ Hris::employeeName($employee) }}
                                    @if ($identity !== '')
                                        <span class="num pill pill-muted">{{ $identity }}</span>
                                    @endif
                                    @if ($employee->is_active === false)
                                        <span class="pill pill-danger">Inactive</span>
                                    @endif
                                </p>
                                <p class="truncate text-sm text-muted-foreground">
                                    {{ $employee->position ?? '—' }}{{ $employee->employment_status ? ' · '.Hris::employmentStatusLabel($employee->employment_status) : '' }}
                                </p>
                            </div>

                            <div class="text-sm text-muted-foreground">
                                <p class="text-xs font-medium uppercase tracking-wide">Default Shift</p>
                                <p class="num text-foreground">{{ $shiftLabel }}</p>
                            </div>
                        </section>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {{-- Absences are NOT $totals->absences: they count a day with
                             no complete Time In and Time Out only once the day is
                             over (EdtrPage::render, Timekeeping's DayClose), so today's
                             shift is never an absence before it has even started.

                             Late, Undertime and Absences say how much of their figure
                             comes from days with NO ASSIGNED SCHEDULE: those days are
                             judged by the system default, which is nobody's schedule,
                             and must not read as the record. --}}
                        @foreach ([
                            ['Days present', (string) $totals->present, 'text-foreground', null],
                            ['Worked', Hris::fmtDuration($totals->regularMinutes()), Hris::durationTone($totals->regularMinutes(), 'text-success'), null],
                            ['Late', Hris::fmtDuration($totals->lateMinutes), Hris::durationTone($totals->lateMinutes, 'text-danger'), $unscheduled['late'] > 0 ? Hris::fmtDuration($unscheduled['late']).' on unscheduled days' : null],
                            ['Undertime', Hris::fmtDuration($totals->undertimeMinutes), Hris::durationTone($totals->undertimeMinutes, 'text-warning'), $unscheduled['undertime'] > 0 ? Hris::fmtDuration($unscheduled['undertime']).' on unscheduled days' : null],
                            ['Absences', (string) $absences, 'text-danger', $unscheduled['absences'] > 0 ? $unscheduled['absences'].' on unscheduled days' : null],
                        ] as [$statLabel, $statValue, $statTone, $statNote])
                            <div class="hris-card p-4" wire:key="stat-{{ $statLabel }}">
                                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $statLabel }}</p>
                                <p class="num mt-1 text-2xl font-bold {{ $statTone }}">{{ $statValue }}</p>
                                @if ($statNote !== null)
                                    <p class="num mt-0.5 text-xs text-muted-foreground">{{ $statNote }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Days nobody assigned a schedule for: the engine judges them
                         by its own default, and that fallback must never pass for
                         the employee's record. --}}
                    @if ($unscheduled['days'] > 0)
                        <div class="hris-card space-y-1 px-5 py-3" data-unscheduled-days="{{ $unscheduled['days'] }}">
                            <p class="text-sm font-medium">
                                <span class="num">{{ $unscheduled['days'] }}</span>
                                {{ $unscheduled['days'] === 1 ? 'day has' : 'days have' }} no assigned schedule and {{ $unscheduled['days'] === 1 ? 'needs' : 'need' }} review.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                No shift was assigned to this employee for {{ $unscheduled['days'] === 1 ? 'that date' : 'those dates' }}, so late, undertime,
                                overtime and absences there are measured against the system default
                                (8:00 AM – 5:00 PM, Monday to Friday) — not against a schedule anyone gave them.
                                Punches, captured locations and remarks are kept exactly as recorded.
                            </p>
                        </div>
                    @endif

                    {{--
                        NO overflow on this card while the table fits in it,
                        deliberately, and that is one rule with two halves.

                        No height cap: every day of the chosen period is
                        already in $rows — eachDate builds them and nothing
                        paginates — so capping the height would put a whole
                        month behind a scrollbar inside the page. The period is
                        the unit a DTR is read in, and it should be readable,
                        and printable, in one piece.

                        No `overflow-x` wrap and no `overflow-hidden` on the
                        card either, because BOTH are scrollports, and
                        hris-table pins its thead to the nearest one. Any
                        overflow here and the header pins to a box that never
                        scrolls, which is a header that scrolls away with the
                        page. With none, it pins to the page and stays legible
                        all the way down a 31-row month. hris-table-flush does
                        the corner-rounding a clip on the card would do.

                        Until it stops fitting. A table wider than the card does
                        NOT scroll the page sideways: the shell clips the x-axis
                        (`overflow-x: clip` on the body and on the content
                        column in layouts/app), so the overflow is cut off, not
                        reachable — and what gets cut is the right-hand end,
                        Remarks, the column anyone acts on. So the card
                        measures itself and takes hris-table-wrap only while
                        the table is wider than it: the x-axis then scrolls
                        inside the card, as every other wide table here does,
                        and for that stretch the header rides up with the page
                        instead of pinning. Widen the screen, or collapse the
                        sidebar, and the wrap comes off.

                        Both are observed: the card for the screen and the
                        sidebar, the table for its own content — a new period,
                        a longer remark — neither of which resizes the card.
                    --}}
                    <div
                        class="hris-card hris-table-flush"
                        x-data="{
                            overflows: false,
                            observer: null,
                            init() {
                                this.observer = new ResizeObserver(() => {
                                    this.overflows = this.$el.scrollWidth > this.$el.clientWidth;
                                });
                                this.observer.observe(this.$el);
                                this.observer.observe(this.$el.querySelector('table'));
                            },
                            destroy() {
                                this.observer.disconnect();
                            },
                        }"
                        x-bind:class="{ 'hris-table-wrap': overflows }"
                    >
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>First Time In</th>
                                    <th>First Time Out</th>
                                    <th>Second Time In</th>
                                    <th>Final Time Out</th>
                                    <th class="text-right">Break</th>
                                    <th class="text-right">Worked</th>
                                    <th class="text-right">Late</th>
                                    <th class="text-right">Undertime</th>
                                    <th class="text-right">OT</th>
                                    <th class="min-w-[14rem]">Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (count($rows) === 0)
                                    <tr wire:key="dtr-empty">
                                        <x-kit.empty-state :colspan="11" :title="$nothingToShow" />
                                    </tr>
                                @endif
                                @foreach ($rows as $row)
                                    @php($computed = $row instanceof DayRecord)
                                    @php($date = $computed ? $row->date : $row)
                                    @php($dateLabel = Hris::fmtDate($date))
                                    @php($auto = $computed ? Hris::autoRemark($row, $holidays, $leaveTypes) : '')
                                    {{--
                                        The row itself opens the correction; there is no
                                        pencil column. The <tr> carries the correction click,
                                        focus classes and wire:key — the five numeric cells
                                        keep exactly `class="num text-right <tone>"` and
                                        nothing else in the table may carry a `dtr-` key
                                        (EdtrModuleTest reads both). The row's click handler
                                        skips a click inside the Remarks cell or the OT
                                        button by looking at the target, never by
                                        stopping propagation — a stopped click would leave
                                        every `.outside` panel on the page open — and the
                                        keydown handlers are `.self`: Alpine wraps `.self`
                                        OUTSIDE `.prevent`, so Enter and Space typed into
                                        that input are neither swallowed nor turned into a
                                        row activation. The focus tint goes on the cells,
                                        not the row, because hris-table-flush rounds the
                                        last row's td corners.

                                        A day the period holds but the engine has not
                                        computed — after today — is rendered blank rather
                                        than ABSENT, and can still be opened: a correction
                                        is how a punch that never landed gets written.
                                        Today itself is computed, so its punches show as
                                        they land.

                                        A time dated after the row's own date — a night's
                                        Time Out, or on a night shift with a scheduled break
                                        any of its after-midnight punches — carries a muted
                                        "next day" tag. The time keeps its own
                                        <span> and the four time cells stay plain `num`
                                        cells: the tag sits beside the time, never in a new
                                        column, and the day it names is decided by
                                        Hris::isNextDay on the local calendar day, never on
                                        the stored UTC stamp.
                                    --}}
                                    <tr
                                        wire:key="dtr-{{ $date }}"
                                        @if ($canEditRemarks)
                                            tabindex="0"
                                            aria-label="Correct {{ $dateLabel }}"
                                            x-on:click="$event.target.closest('[data-remarks], button') || $wire.openCorrection('{{ $date }}')"
                                            x-on:keydown.enter.self.prevent="$wire.openCorrection('{{ $date }}')"
                                            x-on:keydown.space.self.prevent="$wire.openCorrection('{{ $date }}')"
                                        @endif
                                        @class([
                                            'text-muted-foreground' => ! $computed,
                                            'cursor-pointer focus:outline-none [&:focus-visible>td]:bg-accent' => $canEditRemarks,
                                        ])
                                    >
                                        {{-- The date alone: the shift, break and location a day was
                                             measured against are read in its dialog, not under the
                                             date. --}}
                                        <td class="num whitespace-nowrap">{{ $dateLabel }}</td>
                                        @if (! $computed)
                                            <td class="num">—</td>
                                            <td class="num">—</td>
                                            <td class="num">—</td>
                                            <td class="num">—</td>
                                            <td class="num text-right">—</td>
                                            <td class="num text-right">—</td>
                                            <td class="num text-right">—</td>
                                            <td class="num text-right">—</td>
                                            <td class="num text-right">—</td>
                                        @else
                                            @php($worked = $row->regularMinutes())
                                            @foreach ([$row->timeIn, $row->breakOut, $row->breakIn, $row->timeOut] as $stamp)
                                                <td class="num">
                                                    @if ($stamp)
                                                        <span>{{ Hris::fmtTime($stamp) }}</span>
                                                        @if (Hris::isNextDay($stamp, $date))
                                                            <span class="whitespace-nowrap font-sans text-xs text-muted-foreground">next day</span>
                                                        @endif
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="num text-right text-muted-foreground">{{ $row->breakMinutes ? Hris::fmtDuration($row->breakMinutes) : '—' }}</td>
                                            <td class="num text-right {{ Hris::durationTone($worked, 'text-success') }}">{{ Hris::fmtDuration($worked) }}</td>
                                            <td class="num text-right {{ Hris::durationTone($row->lateMinutes, 'text-danger') }}">{{ Hris::fmtDuration($row->lateMinutes) }}</td>
                                            <td class="num text-right {{ Hris::durationTone($row->undertimeMinutes, 'text-warning') }}">{{ Hris::fmtDuration($row->undertimeMinutes) }}</td>
                                            <td class="num text-right {{ Hris::durationTone($row->overtimeMinutes, 'text-success') }}">
                                                @php($overtime = $overtimeByDate[$date] ?? null)
                                                @if ($canEditRemarks && isset($overtimeByDate[$date]))
                                                    <button
                                                        type="button"
                                                        wire:click="openOvertime('{{ $date }}')"
                                                        title="Review overtime for {{ $dateLabel }}"
                                                        aria-label="Review overtime for {{ $dateLabel }}"
                                                        class="inline-flex min-h-8 min-w-8 items-center justify-end rounded-sm underline decoration-dotted underline-offset-4 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                    >{{ Hris::fmtDuration($row->overtimeMinutes) }}</button>
                                                @else
                                                    {{ Hris::fmtDuration($row->overtimeMinutes) }}
                                                @endif
                                                @if ($overtime !== null)
                                                    @if ($canEditRemarks && $overtime->status === OvertimeApprovalPolicy::PENDING && $overtime->ceiling > 0 && ! $overtime->posted)
                                                        <div>
                                                            <button
                                                                type="button"
                                                                wire:click="approveDayOvertime('{{ $targetId }}', '{{ $date }}', {{ $overtime->ceiling }})"
                                                                wire:loading.attr="disabled"
                                                                wire:target="approveDayOvertime,employeePicked"
                                                                aria-label="Approve {{ Hris::fmtDuration($overtime->ceiling) }} of overtime for {{ $dateLabel }}"
                                                                title="Approve {{ Hris::fmtDuration($overtime->ceiling) }} for payroll"
                                                                class="inline-flex min-h-8 items-center rounded-sm font-sans text-xs font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:text-muted-foreground"
                                                            >Approve{{ $overtime->ceiling < $overtime->computed_minutes ? ' '.Hris::fmtDuration($overtime->ceiling) : '' }}</button>
                                                        </div>
                                                    @elseif ($overtime->decision === OvertimeApprovalPolicy::APPROVED)
                                                        <div class="whitespace-nowrap font-sans text-xs text-success">{{ $overtime->status === OvertimeApprovalPolicy::APPROVED ? 'Approved' : Hris::fmtDuration($overtime->payable_minutes).' approved' }}</div>
                                                    @else
                                                        <div class="whitespace-nowrap font-sans text-xs text-muted-foreground">Not approved</div>
                                                    @endif
                                                @endif
                                            </td>
                                        @endif
                                        {{-- Edited in place: the row's click handler skips this cell.
                                             NOT `.stop` — Alpine's `.outside` listeners (the bell, the
                                             employee picker, the calendar) live on document, and a
                                             stopped click would leave them open. --}}
                                        <td data-remarks>
                                            @if ($canEditRemarks)
                                                <flux:input
                                                    class:input="h-8"
                                                    aria-label="Remark for {{ $dateLabel }}"
                                                    wire:model.blur="remarkDrafts.{{ $date }}"
                                                    placeholder="{{ $auto !== '' ? $auto : '—' }}"
                                                />
                                            @else
                                                @php($shown = ($storedRemarks[$date] ?? '') !== '' ? $storedRemarks[$date] : $auto)
                                                <span class="{{ ($storedRemarks[$date] ?? '') !== '' ? '' : 'text-muted-foreground' }}">{{ $shown !== '' ? $shown : '—' }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($canEditRemarks)
                        <p class="text-xs text-muted-foreground">Approve OT directly in its row. Select the OT amount for details, or the row to correct punches. Unapproved OT is not paid, and payroll asks before finalizing a period with OT still undecided.</p>
                    @endif
                @else
                    <p class="hris-card p-8 text-center text-sm text-muted-foreground">
                        Select an employee to compute their daily time record.
                    </p>
                @endif
            </div>
        @endif
    </div>

    {{--
        Correcting a day's punches. Overtime is reviewed in its own dialog.

        A missed punch out leaves the day INCOMPLETE and paying nothing, and
        the engine will not invent an end time for it, so somebody has to say
        what happened. Saving makes the day read as the four slots: a slot
        left at its recorded time keeps that punch (and its captured
        location), a changed one replaces it, and the audit trail records what
        stood before and after.

        LAYOUT: TASK FIRST. The header says which day (with its weekday) and
        the shift, break and location THIS date is measured against, tagged
        only Not rostered / Rest day — nothing about the employee or the
        engine's verdict. There is no name / status / auto-remark line, no
        grace, Overnight or Assigned worksite tag, no break minutes, no ×
        beside a slot and no "Saving replaces" line in the footer: the dialog
        stays uncrowded. The auto remark is the Remark placeholder. The four
        slots come next, then the remark, then the recorded
        punches FOLDED: the times are already in the slots, so the list is
        evidence. The fold starts open only when a punch sits in no slot.
        The sticky footer keeps Save punches within reach. Overtime approval
        opens from the DTR's OT amount, independently of this correction form.

        ALPINE ONLY, CONSTANT x-data. The folds and the change notes are
        client state. The folded list and every hint stay in the server HTML
        (tests read them), with x-show hiding them. Starting flags come from
        data-* attributes read once in init(); facts a render can change are
        read through $root on every use. A changing x-data expression would
        reset an open fold after a Livewire update. No x-cloak: this body
        arrives through a Livewire morph, where x-show applies before paint.

        STICKY FOOTER. The <dialog> is the scroll container and Flux pads it
        (p-6), so the footer is `sticky -bottom-6 -mx-6 -mb-6` to sit flush
        with the dialog's edge, and z-10 to stay above hris-table's sticky
        thead. Its fill is `bg-inherit` down an UNBROKEN chain from the
        <dialog>, whose fill comes from Flux: the body wrapper, the panel and
        the form all carry bg-inherit. An element added between them needs
        it too, or the footer turns transparent. scroll-pb-32 keeps a focused
        field clear of the footer.

        NIGHTS: a "Next day" tick sits beside every slot wherever the shift
        can hold one (overnight or flexible: next_day_allowed), and
        on any other shift only while a tick is still set, so it can be
        unticked. A save uses exactly the ticks beside filled boxes
        (PunchSlots::nextDayFlags), and CorrectPunches judges each of them;
        the "Untick" warning appears for exactly those. Only the punches the
        DTR reads as THIS date's row
        are listed and replaced; the previous night's after-midnight punches
        on the same calendar day are counted and pointed at, never touched. A
        slot the table shows but does not own (a Time In an unfinished
        previous night lends the row) is named above the boxes and left out
        of them.

        READY FOR A ROSTER THAT VARIES BY WEEKDAY: everything below is drawn
        from $correction — DayCorrections::view() for THIS employee and THIS
        date — never from the page header's $shiftRow / $shiftLabel. A per-day
        roster changes the query, not this dialog. The PLACE does vary by
        weekday: $correction->location is where a save of this
        date writes its inserted punches (Work from home, that weekday's site,
        or the Assigned Worksite fallback, shown by name only), resolved the way
        CorrectPunches resolves it. Nothing here is Livewire state; render()
        reads the view afresh while the dialog is open, so the punches listed
        are the ones on the table right now.

        TEST ANCHORS: the FIRST "Recorded punches" on the page is the fold's
        line (or the zero-punch line): after the facts, the slots and the
        remark, before the punch rows and the previous-day note. Never write
        that phrase above it — no tab, hint, notice or aria-label — and
        between the heading and it nothing may say "Rest day", "Work from
        home", "Assigned worksite" or a site name except the facts and the
        Remark placeholder (the auto remark, where an excused day reads "Rest
        day" or a holiday or leave name). One <tr wire:key="correct-punch-…">
        and at most one map link per punch: the list is never rendered twice.

        Flux forwards no aria-label to its <dialog>, so the heading carries an
        id and x-init points the dialog's aria-labelledby at it. A standalone
        flux:label is aria-hidden and labels nothing (only a flux:field wires
        one up). The kit time-field names its input from aria-label, which
        outranks any label, so each slot passes its label as aria-label and a
        native <label for> on the kit's id adds click-to-focus; the remark
        uses the input's label prop.
    --}}
    <flux:modal
        wire:model.self="correctionOpen"
        class="w-full max-w-2xl scroll-pb-32"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'correction-heading')"
    >
        @if ($correctingDate !== null && $correction !== null)
            {{-- Single-expression php directives on purpose. A block-style
                 php directive (the kind that ends with an "end" directive)
                 anywhere below the DTR loop makes Blade pair the loop's first
                 expression directive with it, read everything in between as
                 raw PHP, and swallow the loop. Do not write the block form
                 here, not even in a comment: blocks are extracted before
                 comments are stripped. --}}
            @php($onDate = $correction->shift)
            {{-- Owned by this row but shown in none of its four slots (a double
                 tap, a break outside the window): a save removes them. --}}
            @php($offSlot = array_values(array_filter($correction->punches, static fn ($p): bool => ! $p->in_dtr_slot)))
            @php($offSlotTimes = implode(', ', array_map(static fn ($p): string => Hris::fmtTime(DomainAttendanceLog::isoFromUtc($p->logged_at)).($p->is_carried ? ' next day' : ''), $offSlot)))
            {{-- A tick still set on a shift that cannot hold one (the shift
                 changed after the night was recorded): the ticks stay while any
                 is set, so it can be unticked, and $correctionStaleTick (render)
                 warns once one sits beside a filled box — the only kind
                 CorrectPunches counts, and so the kind it refuses. --}}
            @php($ticksShown = $correction->next_day_allowed || in_array(true, $correction->next_day, true) || in_array(true, $correctionNextDay, true))
            {{-- On a shift with no scheduled break the middle pair folds away,
                 unless either slot holds a time or is borrowed. --}}
            @php($middleOpen = $onDate->uses_scheduled_break || $correction->slots['break_out'] !== '' || $correction->slots['break_in'] !== '' || ($correctionForm['break_out'] ?? '') !== '' || ($correctionForm['break_in'] ?? '') !== '' || isset($correction->borrowed['break_out']) || isset($correction->borrowed['break_in']))

            <div
                wire:key="correct-body-{{ $correctingDate }}"
                class="bg-inherit"
                data-middle="{{ $middleOpen ? '1' : '0' }}"
                data-off-slot="{{ $offSlot === [] ? '0' : '1' }}"
                data-punches="{{ count($correction->punches) }}"
                x-data="{
                    middle: true,
                    afterMidnight: false,
                    evidence: false,
                    init() {
                        this.middle = this.$el.dataset.middle === '1';
                        this.evidence = this.$el.dataset.offSlot === '1';
                    },
                    dropsDay() {
                        const form = this.$wire.correctionForm;
                        return Number(this.$root.dataset.punches) > 0 && ['time_in', 'break_out', 'break_in', 'time_out'].every((key) => ! form[key]);
                    },
                }"
            >
                {{-- Which day, and what THIS date is measured against. --}}
                {{-- A div, not <header>: outside main/section a header is announced
                     as the page's banner landmark, even inside the dialog. --}}
                <div class="flex flex-col gap-3 border-b border-border pb-4">
                    {{-- Only the date shows. "Correct" stays for screen readers: this
                         heading is the dialog's accessible name (aria-labelledby). --}}
                    <flux:heading size="lg" level="2" id="correction-heading" class="pe-8"><span class="sr-only">Correct </span>{{ HrisFormat::weekday(CalendarDays::dayOfWeek($correctingDate)) }}, {{ Hris::fmtDate($correctingDate) }}</flux:heading>

                    <dl class="grid grid-cols-[4.5rem_minmax(0,1fr)] items-baseline gap-x-3 gap-y-1.5 text-sm">
                        <dt class="text-muted-foreground">Shift</dt>
                        <dd class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                            @if ($onDate->mode === DomainShift::MODE_FLEXIBLE)
                                @php($hours = $onDate->required_hours ?? 8)
                                <span>Flexible · {{ $hours == (int) $hours ? (int) $hours : $hours }}h a day, no set start</span>
                            @else
                                <span class="num">{{ Hris::fmtHm($onDate->start_time) }} – {{ Hris::fmtHm($onDate->end_time) }}</span>
                            @endif
                            @unless ($onDate->rostered)
                                <span class="pill pill-muted">Not rostered</span>
                            @endunless
                            @unless ($onDate->is_work_day)
                                <span class="pill pill-muted">Rest day</span>
                            @endunless
                        </dd>

                        <dt class="text-muted-foreground">Break</dt>
                        <dd class="min-w-0">
                            @if ($onDate->uses_scheduled_break)
                                <span class="num">{{ Hris::fmtHm($onDate->break_start) }} – {{ Hris::fmtHm($onDate->break_end) }}</span>
                            @else
                                <span class="text-muted-foreground">No scheduled break — use First Time In and Final Time Out.</span>
                            @endif
                        </dd>

                        {{-- Where a save of this date stamps the punches it inserts: the
                             weekday's roster row, or the Assigned Worksite when there is
                             none — named, not tagged. "No worksite" is the one case a
                             station punch refuses; a correction still lands, with no site. --}}
                        {{-- ASSIGNED, not captured: where the roster expects the
                             employee on this date. Where each punch was really taken
                             is under Recorded punches, per punch. --}}
                        <dt class="text-muted-foreground">Assigned Location</dt>
                        <dd class="min-w-0">
                            @if ($correction->location->kind !== LocationKind::WFH && $correction->location->site_id === null)
                                <span class="text-muted-foreground">No worksite</span>
                            @else
                                <span>{{ LocationKind::label($correction->location->kind, $correction->location->site_name) }}</span>
                            @endif
                        </dd>
                    </dl>
                </div>

                <div id="correction-panel-punches" class="mt-4 bg-inherit">
                    <form wire:submit="saveCorrection" class="flex flex-col gap-4 bg-inherit">
                        {{-- A day a posted payslip covers is what payroll paid: it can be
                             read here but not saved (CorrectPunches refuses it too). --}}
                        @if ($correction->posted)
                            <div role="status" data-correction-paid class="flex gap-2 rounded-md border border-warning/20 bg-warning-soft px-3 py-2 text-sm text-foreground">
                                <flux:icon.lock-closed variant="mini" class="mt-0.5 size-4 shrink-0 text-warning" />
                                <p>{{ CorrectionWindow::PAID_DAY }}</p>
                            </div>
                        @endif

                        {{-- A slot the DTR shows but this row does not own: left out of
                             the boxes, so a save cannot replace another night's punch. --}}
                        @foreach ($correction->borrowed as $borrowedKey => $borrowedAt)
                            <div role="status" wire:key="correct-borrowed-{{ $borrowedKey }}" class="flex gap-2 rounded-md border border-warning/20 bg-warning-soft px-3 py-2 text-sm text-foreground">
                                <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-warning" />
                                <p>
                                    The DTR shows {{ Hris::fmtTime($borrowedAt) }} as this date's {{ PunchSlots::LABELS[$borrowedKey] ?? $borrowedKey }}, but that punch
                                    belongs to the {{ Hris::fmtDate(CalendarDays::previous($correctingDate)) }} record, which has no Final Time Out.
                                    Correct that date first — it is left out of the boxes below.
                                </p>
                            </div>
                        @endforeach

                        {{-- Lower-case "recorded" on purpose: the fold's line below must
                             stay the page's first "Recorded punches", and this is NOT the
                             row tag's wording (a test counts that tag once, in its row). --}}
                        @if ($offSlot !== [])
                            <div role="status" class="flex gap-2 rounded-md border border-warning/20 bg-warning-soft px-3 py-2 text-sm text-foreground">
                                <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-warning" />
                                <p>
                                    {{ count($offSlot) === 1 ? '1 recorded punch' : count($offSlot).' recorded punches' }} ({{ $offSlotTimes }})
                                    {{ count($offSlot) === 1 ? 'is' : 'are' }} in none of the slots below. Saving removes
                                    {{ count($offSlot) === 1 ? 'it unless you type its time' : 'them unless you type their times' }} into a slot.
                                </p>
                            </div>
                        @endif

                        @if ($correction->next_day_allowed)
                            <p class="text-xs text-muted-foreground" data-next-day-hint>Tick Next day beside any time after midnight.</p>
                        @elseif ($correctionStaleTick)
                            <div role="status" class="flex gap-2 rounded-md border border-warning/20 bg-warning-soft px-3 py-2 text-sm text-foreground" data-next-day-hint>
                                <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-warning" />
                                <p>This shift ends the same day. A time after midnight needs an overnight shift in Shift Roster first. Untick Next day before you save.</p>
                            </div>
                        @endif

                        {{-- The four slots, 2 × 2 from 640px. Each note under a box says,
                             live, what a save does to the punch it was prefilled from. --}}
                        <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                            @foreach (PunchSlots::KEYS as $slotKey)
                                @php($slotLabel = PunchSlots::LABELS[$slotKey])
                                @php($was = $correction->slots[$slotKey])
                                @php($wasNext = $correction->next_day[$slotKey])
                                <div
                                    wire:key="correct-{{ $slotKey }}"
                                    class="flex min-w-0 flex-col gap-1.5"
                                    data-slot="{{ $slotKey }}"
                                    data-was="{{ $was }}"
                                    data-was-next="{{ $wasNext ? '1' : '0' }}"
                                    x-data="{
                                        change() {
                                            const { slot, was, wasNext } = this.$root.dataset;
                                            if (was === '') return '';
                                            const now = this.$wire.correctionForm[slot] ?? '';
                                            if (now === '') return 'cleared';
                                            const nowNext = this.$wire.correctionNextDay[slot] === true;
                                            return now !== was || nowNext !== (wasNext === '1') ? 'changed' : '';
                                        },
                                    }"
                                    @if (! $onDate->uses_scheduled_break && ($slotKey === 'break_out' || $slotKey === 'break_in'))
                                        x-show="middle"
                                    @endif
                                >
                                    <label for="correct-{{ $slotKey }}-time" class="text-sm font-medium leading-tight">{{ $slotLabel }}</label>
                                    {{-- The Next day tick sits BESIDE the kit field: the kit stays a
                                         typed box plus an AM/PM select, and its value stays HH:mm —
                                         the day is the tick's to say. No × per slot: deleting the
                                         typed time empties a slot, and "Clear all" below empties
                                         the four. --}}
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                        <x-kit.time-field id="correct-{{ $slotKey }}-time" wire:model="correctionForm.{{ $slotKey }}" aria-label="{{ $slotLabel }}" />
                                        @if ($ticksShown)
                                            <label class="inline-flex cursor-pointer items-center gap-2 py-1 text-sm text-muted-foreground">
                                                <input
                                                    type="checkbox"
                                                    wire:model="correctionNextDay.{{ $slotKey }}"
                                                    class="size-4 rounded border-border accent-[var(--color-primary)]"
                                                />
                                                Next Day<span class="sr-only"> for {{ $slotLabel }}</span>
                                            </label>
                                        @endif
                                    </div>
                                    @if ($was !== '')
                                        <p class="text-xs text-muted-foreground" x-show="change() !== ''">
                                            <span x-text="change() === 'cleared' ? 'Cleared' : 'Changed'">Changed</span> · was
                                            <span class="num">{{ Hris::fmtHm($was) }}</span>{{ $wasNext ? ' next day' : '' }}
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @unless ($onDate->uses_scheduled_break)
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    icon="plus"
                                    x-show="! middle"
                                    x-on:click="middle = true; $nextTick(() => document.getElementById('correct-break_out-time')?.focus())"
                                >Add Break Punches</flux:button>
                            @endunless
                            @if (! $correction->next_day_allowed && ! $correctionStaleTick)
                                <flux:button
                                    type="button"
                                    variant="subtle"
                                    size="sm"
                                    aria-controls="correction-after-midnight"
                                    aria-expanded="false"
                                    x-bind:aria-expanded="afterMidnight"
                                    x-on:click="afterMidnight = ! afterMidnight"
                                >Time after midnight?</flux:button>
                            @endif
                            <flux:button type="button" variant="subtle" size="sm" icon="backspace" class="ms-auto" wire:click="clearAllSlots">
                                Clear All
                            </flux:button>
                        </div>
                        @if (! $correction->next_day_allowed && ! $correctionStaleTick)
                            <p id="correction-after-midnight" class="text-xs text-muted-foreground" data-next-day-hint x-show="afterMidnight">This shift ends the same day. A time after midnight needs an overnight shift in Shift Roster first.</p>
                        @endif

                        <flux:input
                            id="correction-note"
                            label="Remark"
                            wire:model="correctionForm.note"
                            placeholder="{{ $correctionAutoRemark !== '' ? $correctionAutoRemark : 'Why this day was corrected' }}"
                        />

                        {{-- What a save supersedes, folded: the punches the DTR reads as
                             THIS date's row — its next-day ones included, the previous
                             night's never — with where each was captured, or that it was
                             entered by hand.
                             ANCHOR: the page's first "Recorded punches", in both branches. --}}
                        <div class="border-t border-border pt-3">
                            @if ($correction->punches === [])
                                <p class="text-sm">
                                    <span class="font-medium">Recorded Punches</span>
                                    <span class="text-muted-foreground">· none on this day's record.</span>
                                </p>
                            @else
                                <button
                                    type="button"
                                    class="flex min-h-8 w-full items-center gap-2 rounded-md text-left text-sm font-medium hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    aria-controls="correction-punch-list"
                                    aria-expanded="{{ $offSlot === [] ? 'false' : 'true' }}"
                                    x-bind:aria-expanded="evidence"
                                    x-on:click="evidence = ! evidence"
                                >
                                    <span class="inline-flex text-muted-foreground motion-safe:transition-transform" x-bind:class="evidence ? 'rotate-90' : ''">
                                        <flux:icon.chevron-right variant="micro" class="size-4" />
                                    </span>
                                    Recorded Punches
                                    <span class="num font-normal text-muted-foreground">{{ count($correction->punches) }}</span>
                                    @if ($offSlot !== [])
                                        <span class="pill pill-caution">{{ count($offSlot) }} not in a slot</span>
                                    @endif
                                </button>
                                <div id="correction-punch-list" class="hris-table-wrap mt-2 rounded-md border border-border" x-show="evidence">
                                    <table class="hris-table">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Captured Location</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($correction->punches as $p)
                                                <tr wire:key="correct-punch-{{ $p->id }}">
                                                    <td class="whitespace-nowrap align-top">
                                                        <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-2">
                                                            <span class="num">{{ Hris::fmtTime(DomainAttendanceLog::isoFromUtc($p->logged_at)) }}</span>
                                                            <span class="{{ $p->log_type === 'TIME_IN' ? 'pill pill-success' : 'pill pill-muted' }}">{{ str_replace('_', ' ', $p->log_type) }}</span>
                                                        </div>
                                                        @if ($p->is_carried)
                                                            <span class="mt-0.5 block text-xs text-muted-foreground">next day</span>
                                                        @endif
                                                    </td>
                                                    <td class="align-top">
                                                        {{-- A punch entered by hand — an admin's MANUAL punch at
                                                             the station, or a time a saved correction put in place
                                                             of the captured one — says only "Manually Entered",
                                                             with no place or map, even when the kiosk's fix is on
                                                             it. Any other punch shows the place it was captured at
                                                             ("Work from home", the site or an em dash) and, when
                                                             the row has coordinates, the spot on a map. --}}
                                                        @if ($p->hand_entered)
                                                            <span>Manually Entered</span>
                                                        @else
                                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                                <span>{{ LocationKind::label($p->location_kind, $p->site_name) }}</span>
                                                                @if ($p->latitude !== null && $p->longitude !== null)
                                                                    <span class="pill pill-muted">Web GPS</span>
                                                                    <a
                                                                        href="https://www.google.com/maps?q={{ $p->latitude }},{{ $p->longitude }}"
                                                                        target="_blank"
                                                                        rel="noopener noreferrer"
                                                                        class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                                                        aria-label="Map of the {{ Hris::fmtTime(DomainAttendanceLog::isoFromUtc($p->logged_at)) }} punch, in Google Maps"
                                                                    >
                                                                        Map
                                                                        <flux:icon.arrow-top-right-on-square class="size-3" />
                                                                    </a>
                                                                @endif
                                                            </div>
                                                        @endif
                                                        {{-- Owned by this row but shown in none of its four slots:
                                                             a save removes it unless it is entered above. --}}
                                                        @unless ($p->in_dtr_slot)
                                                            <p class="mt-1 flex items-start gap-1 text-xs text-foreground">
                                                                <flux:icon.exclamation-triangle variant="micro" class="mt-px size-3.5 shrink-0 text-warning" />
                                                                <span>not in a DTR slot — a save removes it unless entered</span>
                                                            </p>
                                                        @endunless
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                            {{-- The previous night's after-midnight punches sit on this calendar
                                 date but belong to that night's row: never listed as this
                                 day's, never replaced by its save. --}}
                            @if ($correction->previous_day_punches > 0)
                                <p class="mt-2 text-xs text-muted-foreground" data-previous-day-punches>
                                    {{ $correction->previous_day_punches === 1 ? '1 punch on this date belongs' : $correction->previous_day_punches.' punches on this date belong' }}
                                    to the {{ Hris::fmtDate(CalendarDays::previous($correctingDate)) }} record. Open that day to change
                                    {{ $correction->previous_day_punches === 1 ? 'it' : 'them' }}.
                                </p>
                            @endif
                        </div>

                        {{-- The job's one action area, always in view (STICKY FOOTERS above). --}}
                        <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-col gap-2 border-t border-border bg-inherit px-6 py-3">
                            @if ($correctionError)
                                <p role="alert" class="text-sm text-danger">{{ $correctionError }}</p>
                            @endif
                            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
                                {{-- A live region that speaks only when every slot is empty. It
                                     stays in the markup (a region added later is not announced).
                                     Silent, it is an empty item that takes no room; speaking below
                                     640px, it takes a line of its own, or it would share one with
                                     Cancel and push Save onto the next. --}}
                                <p role="status" class="min-w-0 text-xs sm:basis-0 sm:grow" x-bind:class="{ 'max-sm:basis-full': dropsDay() }">
                                    <span class="inline-flex items-start gap-1.5 text-foreground" x-show="dropsDay()">
                                        <flux:icon.exclamation-triangle variant="micro" class="mt-px size-4 shrink-0 text-warning" />
                                        Every slot is empty, so saving deletes all of this day's punches.
                                    </span>
                                </p>
                                <flux:button type="button" variant="outline" wire:click="$set('correctionOpen', false)">
                                    {{ $correction->posted ? 'Close' : 'Cancel' }}
                                </flux:button>
                                @unless ($correction->posted)
                                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCorrection">
                                        <span wire:loading wire:target="saveCorrection" class="mr-2 inline-flex">
                                            <flux:icon.loading class="size-4" />
                                        </span>
                                        Save Punches
                                    </flux:button>
                                @endunless
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </flux:modal>

    @include('livewire.edtr.partials.dialog-overtime')
</div>
