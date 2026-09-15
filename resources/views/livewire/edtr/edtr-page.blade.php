{{--
    eDTR & Attendance — the port of copems/src/app/(protected)/edtr/edtr-page.tsx.
    See App\Livewire\Edtr\EdtrPage for the state and actions behind this.

    The shadcn Tabs have no Flux-free equivalent, so the TabsList/TabsTrigger
    pair is approximated with Tailwind on plain buttons; switching tabs
    re-renders server-side, which matches Radix unmounting inactive content.
--}}
@use('App\Livewire\Edtr\Support\Hris')
@use('App\Domain\Attendance\DayRecord')
@use('Illuminate\Support\Facades\Storage')

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
            >Punch station</button>
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
                <div class="hris-card grid gap-4 p-5 sm:grid-cols-3">
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
                                trigger-id="dtr-employee"
                                labelled-by="dtr-employee-label"
                                wire:key="dtr-employee-picker-{{ $dtrEmployee }}"
                            />
                        @endif
                    </div>
                    <div class="space-y-2">
                        <div class="flex min-h-5 items-center justify-between gap-2">
                            <flux:label>Period start</flux:label>
                            <button
                                type="button"
                                wire:click="thisCutoff"
                                class="text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                            >This cut-off</button>
                        </div>
                        <x-kit.date-field wire:model.live="start" aria-label="Period start" />
                    </div>
                    <div class="space-y-2">
                        <div class="flex min-h-5 items-center">
                            <flux:label>Period end</flux:label>
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
                                        src="{{ Storage::disk('public')->url($employee->photo_path) }}"
                                        alt="{{ Hris::employeeName($employee) }}"
                                        class="aspect-square h-full w-full object-cover"
                                    />
                                @else
                                    <span class="num flex h-full w-full items-center justify-center text-sm font-semibold">
                                        {{ strtoupper(mb_substr($employee->first_name, 0, 1).mb_substr($employee->last_name, 0, 1)) }}
                                    </span>
                                @endif
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2 font-bold">
                                    {{ Hris::employeeName($employee) }}
                                    <span class="num pill pill-muted">{{ $employee->employee_code }}</span>
                                    @if ($employee->is_active === false)
                                        <span class="pill pill-danger">Inactive</span>
                                    @endif
                                </p>
                                <p class="truncate text-sm text-muted-foreground">
                                    {{ $employee->position ?? '—' }}{{ $employee->employment_status ? ' · '.Hris::employmentStatusLabel($employee->employment_status) : '' }}
                                </p>
                            </div>

                            <div class="text-sm text-muted-foreground">
                                <p class="text-xs font-medium uppercase tracking-wide">Shift</p>
                                <p class="num text-foreground">{{ $shiftLabel }}</p>
                            </div>
                        </section>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        @foreach ([
                            ['Days present', (string) $totals->present, 'text-foreground'],
                            ['Worked', Hris::fmtDuration($totals->regularMinutes()), Hris::durationTone($totals->regularMinutes(), 'text-success')],
                            ['Late', Hris::fmtDuration($totals->lateMinutes), Hris::durationTone($totals->lateMinutes, 'text-danger')],
                            ['Undertime', Hris::fmtDuration($totals->undertimeMinutes), Hris::durationTone($totals->undertimeMinutes, 'text-warning')],
                            ['Overtime', Hris::fmtDuration($totals->overtimeMinutes), Hris::durationTone($totals->overtimeMinutes, 'text-success')],
                            ['Absences', (string) $totals->absences, 'text-danger'],
                        ] as [$statLabel, $statValue, $statTone])
                            <div class="hris-card p-4" wire:key="stat-{{ $statLabel }}">
                                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $statLabel }}</p>
                                <p class="num mt-1 text-2xl font-bold {{ $statTone }}">{{ $statValue }}</p>
                            </div>
                        @endforeach
                    </div>

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
                        the corner-rounding the card's clip used to do.

                        Until it stops fitting. A table wider than the card does
                        NOT scroll the page sideways: the shell clips the x-axis
                        (`overflow-x: clip` on the body and on the content
                        column in layouts/app), so the overflow is cut off, not
                        reachable — and what gets cut is the right-hand end,
                        Remarks and the correction button, the two columns
                        anyone acts on. So the card measures itself and takes
                        hris-table-wrap only while the table is wider than it:
                        the x-axis then scrolls inside the card, as every other
                        wide table here does, and for that stretch the header
                        rides up with the page instead of pinning. Widen the
                        screen, or collapse the sidebar, and the wrap comes off.

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
                                    <th class="w-10"><span class="sr-only">Correct</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (count($rows) === 0)
                                    <tr wire:key="dtr-empty">
                                        <x-kit.empty-state :colspan="12" :title="$nothingToShow" />
                                    </tr>
                                @endif
                                @foreach ($rows as $row)
                                    @if (! $row instanceof DayRecord)
                                        {{-- A day the period holds but the engine has not computed:
                                             today onward, rendered blank rather than ABSENT. --}}
                                        @php($date = $row)
                                        <tr class="text-muted-foreground" wire:key="dtr-{{ $date }}">
                                            <td class="num whitespace-nowrap">{{ Hris::fmtDate($date) }}</td>
                                            <td class="num">—</td>
                                            <td class="num">—</td>
                                            <td class="num">—</td>
                                            <td class="num">—</td>
                                            <td class="num text-right">—</td>
                                            <td class="num text-right">—</td>
                                            <td class="num text-right">—</td>
                                            <td class="num text-right">—</td>
                                            <td class="num text-right">—</td>
                                            <td>
                                                @if ($canEditRemarks)
                                                    <flux:input
                                                        class:input="h-8"
                                                        aria-label="Remark for {{ Hris::fmtDate($date) }}"
                                                        wire:model.blur="remarkDrafts.{{ $date }}"
                                                        placeholder="—"
                                                    />
                                                @else
                                                    <span class="{{ ($storedRemarks[$date] ?? '') !== '' ? '' : 'text-muted-foreground' }}">{{ ($storedRemarks[$date] ?? '') !== '' ? $storedRemarks[$date] : '—' }}</span>
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                @if ($canEditRemarks)
                                                    <flux:button
                                                        variant="ghost"
                                                        size="sm"
                                                        square
                                                        icon="pencil"
                                                        wire:click="openCorrection('{{ $date }}')"
                                                        aria-label="Correct punches for {{ Hris::fmtDate($date) }}"
                                                    />
                                                @endif
                                            </td>
                                        </tr>
                                    @else
                                        @php($auto = Hris::autoRemark($row, $holidays, $leaveTypes))
                                        @php($worked = $row->regularMinutes())
                                        <tr wire:key="dtr-{{ $row->date }}">
                                            <td class="num whitespace-nowrap">{{ Hris::fmtDate($row->date) }}</td>
                                            <td class="num">{{ $row->timeIn ? Hris::fmtTime($row->timeIn) : '—' }}</td>
                                            <td class="num">{{ $row->breakOut ? Hris::fmtTime($row->breakOut) : '—' }}</td>
                                            <td class="num">{{ $row->breakIn ? Hris::fmtTime($row->breakIn) : '—' }}</td>
                                            <td class="num">{{ $row->timeOut ? Hris::fmtTime($row->timeOut) : '—' }}</td>
                                            <td class="num text-right text-muted-foreground">{{ $row->breakMinutes ? Hris::fmtDuration($row->breakMinutes) : '—' }}</td>
                                            <td class="num text-right {{ Hris::durationTone($worked, 'text-success') }}">{{ Hris::fmtDuration($worked) }}</td>
                                            <td class="num text-right {{ Hris::durationTone($row->lateMinutes, 'text-danger') }}">{{ Hris::fmtDuration($row->lateMinutes) }}</td>
                                            <td class="num text-right {{ Hris::durationTone($row->undertimeMinutes, 'text-warning') }}">{{ Hris::fmtDuration($row->undertimeMinutes) }}</td>
                                            <td class="num text-right {{ Hris::durationTone($row->overtimeMinutes, 'text-success') }}">{{ Hris::fmtDuration($row->overtimeMinutes) }}</td>
                                            <td>
                                                @if ($canEditRemarks)
                                                    <flux:input
                                                        class:input="h-8"
                                                        aria-label="Remark for {{ Hris::fmtDate($row->date) }}"
                                                        wire:model.blur="remarkDrafts.{{ $row->date }}"
                                                        placeholder="{{ $auto !== '' ? $auto : '—' }}"
                                                    />
                                                @else
                                                    @php($shown = ($storedRemarks[$row->date] ?? '') !== '' ? $storedRemarks[$row->date] : $auto)
                                                    <span class="{{ ($storedRemarks[$row->date] ?? '') !== '' ? '' : 'text-muted-foreground' }}">{{ $shown !== '' ? $shown : '—' }}</span>
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                @if ($canEditRemarks)
                                                    <flux:button
                                                        variant="ghost"
                                                        size="sm"
                                                        square
                                                        icon="pencil"
                                                        wire:click="openCorrection('{{ $row->date }}')"
                                                        aria-label="Correct punches for {{ Hris::fmtDate($row->date) }}"
                                                    />
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="hris-card p-8 text-center text-sm text-muted-foreground">
                        Select an employee to compute their daily time record.
                    </p>
                @endif
            </div>
        @endif
    </div>

    {{--
        Correcting a day's punches. A missed punch out leaves the day
        INCOMPLETE and paying nothing, and the engine will not invent an end
        time for it — so somebody has to say what happened. Saving replaces
        the day's punches and records what it replaced in the audit trail.
    --}}
    <flux:modal wire:model.self="correctionOpen" class="w-full max-w-lg">
        @if ($correctingDate !== null)
            <div class="space-y-2">
                <flux:heading size="lg">Correct {{ Hris::fmtDate($correctingDate) }}</flux:heading>
                <flux:text>
                    {{ $employeeLabel }} · these replace the punches on this day. Clearing every time takes the
                    day off the record entirely. The change is audited.
                </flux:text>
            </div>

            <form class="mt-4 grid gap-4 sm:grid-cols-2" wire:submit="saveCorrection">
                @foreach ([
                    ['time_in', 'First Time In'],
                    ['break_out', 'First Time Out'],
                    ['break_in', 'Second Time In'],
                    ['time_out', 'Final Time Out'],
                ] as [$slotKey, $slotLabel])
                    <div class="space-y-2" wire:key="correct-{{ $slotKey }}">
                        <flux:label>{{ $slotLabel }}</flux:label>
                        <x-kit.time-field wire:model="correctionForm.{{ $slotKey }}" aria-label="{{ $slotLabel }}" />
                    </div>
                @endforeach

                <div class="space-y-2 sm:col-span-2">
                    <flux:label for="correction-note">Remark</flux:label>
                    <flux:input
                        id="correction-note"
                        wire:model="correctionForm.note"
                        placeholder="Why this day was corrected"
                    />
                </div>

                @if ($correctionError)
                    <p role="alert" class="text-xs text-danger sm:col-span-2">{{ $correctionError }}</p>
                @endif

                <div class="flex justify-end gap-2 sm:col-span-2">
                    <flux:button type="button" variant="outline" wire:click="$set('correctionOpen', false)">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCorrection">
                        <span wire:loading wire:target="saveCorrection" class="mr-2 inline-flex">
                            <flux:icon.loading class="size-4" />
                        </span>
                        Save punches
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
