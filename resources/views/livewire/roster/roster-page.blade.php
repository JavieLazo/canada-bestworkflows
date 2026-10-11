{{--
    Shift Roster — the port of src/app/(protected)/roster/roster-page.tsx and
    add-shift-dialog.tsx. The reference's columns in its order (ID, Employee,
    Shift pattern, Hours, Break, Grace), same filters, same default sort
    (employee, ascending), same dialog fields and copy — plus, with no
    counterpart in the reference, a Location column after Shift pattern and
    a row-actions column with weekly and date-specific schedule dialogs.
    Each weekday can override hours and location; a temporary schedule —
    one date or a From–To period — takes priority over that recurring
    schedule. The standing shift and worksite remain defaults.

    SCHEDULES ARE DATED: every dialog carries an Effective from date, a
    change that needs reviewing is held in the review dialog, each row
    opens its schedule history, and the table shows the schedule in effect
    today. The commands decide all of it; see RosterPage.

    Deliberate divergences from the reference, besides the initials that stand
    in for the photo (see the Employee cell): the row control is a menu whose
    pick a confirm dialog commits, not the commit-on-change select
    (roster-page.tsx:300-318), commented at the cell and the modal; and a
    FLEXIBLE row reads as its daily hours in the Hours cell and both dialogs'
    "already works" lines (Roster\Hris::hoursOf, which carries the note), with
    an em dash in the Grace cell (commented there), never the filler
    8:00 AM–5:00 PM / 0 min the reference printed from the stored row.
    The header has no "Manage patterns" link to Records → Shifting Settings
    (roster-page.tsx:169-175); the Records sidebar entry already reaches
    that page. It has no As of date, Export CSV, Import CSV or Scheduled
    changes either: the roster is a screen, not a file.
--}}
@php
    use App\Livewire\Roster\Hris;
    use App\Domain\Attendance\Shift as DomainShift;
    use App\Support\HrisFormat;

    $defaultShift = DomainShift::default();
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Shift Roster</h1>
            <p class="text-sm text-muted-foreground">
                Set each employee’s default shift, then choose different shifts and work sites by weekday or date.
                A change starts on its effective date. Earlier dates keep the schedule they had.
            </p>
        </div>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search employees"
                placeholder="Search ID, name or position"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />

            <x-kit.filter-select label="Filter by status" class="w-[11rem]" wire:model.live="status">
                <flux:select.option value="ALL">All statuses</flux:select.option>
                @foreach ($statuses as $s)
                    <flux:select.option value="{{ $s }}">{{ $s }}</flux:select.option>
                @endforeach
                <hr>
                <flux:select.option value="INACTIVE">Inactive</flux:select.option>
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by position" class="w-[13rem]" wire:model.live="position">
                <flux:select.option value="ALL">All positions</flux:select.option>
                @foreach ($positions as $p)
                    <flux:select.option value="{{ $p }}">{{ $p }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by pattern" class="w-[13rem]" wire:model.live="pattern">
                <flux:select.option value="ALL">All patterns</flux:select.option>
                @foreach ($patterns as $p)
                    <flux:select.option value="{{ $p->id }}">{{ $p->name }}</flux:select.option>
                @endforeach
                <hr>
                <flux:select.option value="UNASSIGNED">No pattern</flux:select.option>
            </x-kit.filter-select>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <flux:button variant="primary" icon="plus" wire:click="openAssign">
                    Assign shift
                </flux:button>
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="employee" label="Employee" />
                        <th>Default shift</th>
                        {{-- Where they work — no counterpart in the reference: the
                             place most working days go, then the exceptions by day. A
                             weekday with no entry is the Assigned Worksite from Employee
                             Records, geofenced against that site. A Location filter (by
                             site, or WFH days) is deferred: the summary is a derived
                             cell, and the filters above are the reference's. --}}
                        <th>Location</th>
                        <x-kit.sort-header field="shift" label="Hours" />
                        <th>Break</th>
                        <th class="text-right">Grace</th>
                        <th class="w-10"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            /** @var \App\Modules\Workforce\Contracts\DirectoryEmployee $employee */
                            $employee = $row['employee'];
                            /** @var \App\Modules\Timekeeping\Contracts\Views\ShiftView|null $shift */
                            $shift = $row['shift'];
                            // The stored pattern's name, or null for no row / an orphaned row
                            // (its pattern deleted: FK nullOnDelete) — both read "No pattern".
                            $currentName = $shift?->shift_type_id === null ? null : ($patternNames[$shift->shift_type_id] ?? null);
                            // Where they work most working days, and the exceptions: a
                            // working weekday with no row is the Assigned Worksite from
                            // Employee Records, from the sites already in hand.
                            $assignedSite = $employee->work_site_id === null ? null : $sites->get($employee->work_site_id);
                            $places = Hris::placeSummary(
                                $row['locations'],
                                Hris::workingWeekdays($shift?->work_days, array_map(static fn ($day): bool => ! $day->is_rest_day, $row['weeklyShifts'])),
                                $assignedSite?->id,
                                $assignedSite?->name,
                            );
                        @endphp
                        <tr wire:key="row-{{ $employee->id }}" @class(['opacity-60' => ! $employee->is_active])>
                            <td class="num whitespace-nowrap align-middle">{{ \App\Support\EmployeeIdentity::index($employee) }}</td>
                            <td>
                                <div class="flex items-center gap-3">
                                    {{-- Avatar (h-9 w-9): photo_path exists on the model but no
                                         photo-serving route is wired; initials stand in (source
                                         showed the photo when present, roster-page.tsx:280-287). --}}
                                    <span class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                        <span class="num text-xs font-semibold">{{ Hris::initials($employee) }}</span>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ Hris::employeeName($employee) }}</p>
                                        <p class="truncate text-xs text-muted-foreground">
                                            {{ $employee->position ?? '—' }}{{ $employee->is_active ? '' : ' · '.Hris::employmentStatusLabel($employee->employment_status) }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                {{-- The row's pattern control. DELIBERATE DIVERGENCE from the
                                     commit-on-change <Select> of roster-page.tsx:300-318: a pick
                                     here only stages (pickPattern) and the confirm dialog below
                                     writes. "No pattern" is the reference's placeholder
                                     (roster-page.tsx:309). The stored pattern is checked and has
                                     no click. Flux's dropdown contract: the trigger is the first
                                     button and the menu the last child; no `icon` prop on the
                                     button, because Flux then wraps and centres the slot, whereas
                                     bare children let flex-1 pin the chevron right; the menu is a
                                     top-layer popover, so it escapes the card's overflow-hidden
                                     and the table's scroll cap. menu.radio is avoided because its
                                     JS flips the check before the server answers. --}}
                                <div wire:key="pattern-{{ $employee->id }}-{{ $shift?->shift_type_id ?? 'none' }}">
                                    <flux:dropdown position="bottom" align="start">
                                        <flux:button
                                            variant="outline"
                                            class="w-48"
                                            aria-label="{{ Hris::employeeName($employee) }} shift pattern: {{ $currentName ?? 'No pattern' }}"
                                            wire:loading.attr="disabled"
                                            wire:target="pickPattern, confirmAssign"
                                        >
                                            <span class="min-w-0 flex-1 truncate text-start">{{ $currentName ?? 'No pattern' }}</span>
                                            <flux:icon.chevron-down variant="micro" class="shrink-0 text-muted-foreground" />
                                        </flux:button>
                                        <flux:menu>
                                            @forelse ($patterns as $p)
                                                @if ($p->id === $shift?->shift_type_id)
                                                    <flux:menu.item icon="check">{{ $p->name }}</flux:menu.item>
                                                @else
                                                    <flux:menu.item wire:click="pickPattern('{{ $employee->id }}', '{{ $p->id }}')">{{ $p->name }}</flux:menu.item>
                                                @endif
                                            @empty
                                                <flux:menu.item disabled>No shift patterns yet</flux:menu.item>
                                            @endforelse
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </td>
                            <td>
                                {{-- The place they work most days, then each other place with
                                     its days in small muted text: one line for most people,
                                     the exceptions second, never a day-by-day list.
                                     Capped so a phone wraps a long site name inside the cell
                                     instead of widening the table; Hris::placeSummary carries
                                     the rule. --}}
                                <div class="min-w-[11rem] max-w-[16rem]">
                                    @if ($places['main'] !== null)
                                        <p>{{ $places['main'] }}</p>
                                    @else
                                        <p class="text-muted-foreground">No worksite set</p>
                                    @endif
                                    @if ($places['others'] !== [])
                                        <dl class="mt-0.5 grid grid-cols-[auto_minmax(0,1fr)] gap-x-2 text-xs text-muted-foreground">
                                            @foreach ($places['others'] as $other)
                                                <dt class="whitespace-nowrap">{{ $other['days'] }}</dt>
                                                <dd>{{ $other['label'] }}</dd>
                                            @endforeach
                                        </dl>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if ($row['weeklyShifts'] !== [])
                                    <div class="min-w-[12rem] max-w-[18rem] space-y-1">
                                        <p class="text-xs text-muted-foreground">Default: <span class="num">{{ $shift === null ? '8:00 AM–5:00 PM' : Hris::hoursOf($shift) }}</span></p>
                                        @foreach ([1, 2, 3, 4, 5, 6, 0] as $weekday)
                                            @if (isset($row['weeklyShifts'][$weekday]))
                                                <p><span class="text-muted-foreground">{{ HrisFormat::weekday($weekday) }}</span> <span class="num">{{ Hris::weekdayHours($row['weeklyShifts'][$weekday]) }}</span></p>
                                            @endif
                                        @endforeach
                                    </div>
                                @else
                                    <span class="num whitespace-nowrap">{{ Hris::hoursOf($shift) }}</span>
                                @endif
                                {{-- Scheduled changes and temporary schedules still ahead. --}}
                                @if (($upcoming[$employee->id] ?? 0) > 0)
                                    <p class="mt-1 text-xs text-primary">
                                        <button type="button" class="hover:underline" wire:click="openHistory('{{ $employee->id }}')">{{ $upcoming[$employee->id] }} upcoming {{ $upcoming[$employee->id] === 1 ? 'change' : 'changes' }}</button>
                                    </p>
                                @endif
                            </td>
                            <td class="num whitespace-nowrap text-muted-foreground">{{ $row['weeklyShifts'] !== [] ? 'By daily shift' : Hris::breakOf($shift) }}</td>
                            {{-- A FLEXIBLE row stores grace 0 by construction, so the reference's
                                 "0 min" described nothing; an em dash, as the Records table shows it. --}}
                            <td class="num whitespace-nowrap text-right text-muted-foreground">
                                {{ $row['weeklyShifts'] !== [] ? 'By daily shift' : ($shift === null || $shift->mode === DomainShift::MODE_FLEXIBLE ? '—' : $shift->grace_minutes.' min') }}
                            </td>
                            {{-- The two writes are hidden without all_data + ROSTER;
                                 openLocations() re-resolves the id and every save
                                 re-checks the grant regardless. The history is a read,
                                 scoped like the row itself. --}}
                            <td class="text-right">
                                <x-kit.row-actions :label="Hris::employeeName($employee)">
                                    @if ($canEditLocations)
                                        <flux:menu.item icon="map-pin" wire:click="openLocations('{{ $employee->id }}')">Weekly schedule…</flux:menu.item>
                                        <flux:menu.item icon="calendar-days" wire:click="openDateSchedule('{{ $employee->id }}')">Temporary schedule…</flux:menu.item>
                                    @endif
                                    <flux:menu.item icon="clock" wire:click="openHistory('{{ $employee->id }}')">Schedule history…</flux:menu.item>
                                </x-kit.row-actions>
                            </td>
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

    {{-- Assign shift dialog — add-shift-dialog.tsx. --}}
    <flux:modal name="assign-shift" class="w-full max-w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Assign shift</flux:heading>
                <flux:text class="mt-1">
                    Assign working hours to an employee. Everyone without one is measured against the
                    {{ Hris::fmtHm($defaultShift->startTime) }}&ndash;{{ Hris::fmtHm($defaultShift->endTime) }}
                    default until then. Weekday selections in Weekly schedule take priority over this default.
                </flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2 sm:col-span-2">
                    <div class="flex min-h-5 items-center">
                        <flux:label id="assign-employee-label" for="assign-employee">Employee</flux:label>
                    </div>
                    {{-- The searchable kit picker: type any part of an ID, a
                         name or a position. DELIBERATE DIVERGENCE from the
                         grouped shadcn Select of add-shift-dialog.tsx:53-61, which
                         listed the unassigned first; here the line under the field
                         says whether the person picked has a shift yet. Actives
                         only, as that select was. The picker's value is #[Locked],
                         so the wire:key re-mounts it when openAssign() clears the
                         pick or a pick lands. --}}
                    <livewire:kit.employee-picker
                        name="assign-employee"
                        :value="$dialogEmployeeId"
                        :active-only="true"
                        trigger-id="assign-employee"
                        labelled-by="assign-employee-label"
                        wire:key="assign-employee-picker-{{ $dialogEmployeeId === '' ? 'none' : $dialogEmployeeId }}"
                    />
                    @if ($dialogExisting !== null)
                        <p class="text-xs text-warning">
                            {{ Hris::employeeName($dialogSelected) }} already works
                            <span class="num">{{ Hris::hoursOf($dialogExisting) }}</span>.
                            Saving changes it from the effective date. Earlier dates keep it.
                        </p>
                    @elseif ($dialogSelected !== null)
                        <p class="text-xs text-muted-foreground">{{ Hris::employeeName($dialogSelected) }} has no shift yet.</p>
                    @endif
                </div>

                <div class="space-y-2 sm:col-span-2">
                    <flux:label>Shift pattern</flux:label>
                    <div wire:key="dialog-pattern-{{ $dialogPatternId === '' ? 'none' : $dialogPatternId }}">
                        <x-kit.select
                            aria-label="Shift pattern"
                            placeholder="Select pattern"
                            wire:change="dialogPatternChanged($event.target.value)"
                        >
                            @foreach ($patterns as $p)
                                <flux:select.option value="{{ $p->id }}" :selected="$dialogPatternId === $p->id">{{ $p->name }}</flux:select.option>
                            @endforeach
                        </x-kit.select>
                    </div>
                    @if ($dialogChosen !== null)
                        <p class="num text-xs text-muted-foreground">{{ Hris::patternSummary($dialogChosen) }}</p>
                    @endif
                </div>

                @include('livewire.roster.partials.effective-from', ['model' => 'assignFrom', 'fieldId' => 'assign-from'])

                <div class="flex items-end sm:col-span-2">
                    <flux:button
                        variant="primary"
                        icon="plus"
                        wire:click="dialogSave"
                        :disabled="$dialogEmployeeId === '' || $dialogPatternId === ''"
                    >
                        Assign shift
                    </flux:button>
                </div>
            </div>
        </div>
    </flux:modal>

    {{-- Confirm dialog for a row-menu pick — no reference dialog: the source
         wrote on change (roster-page.tsx:300-318), see the cell comment. The
         staged pair lives in confirmEmployeeId / confirmPatternId; Cancel and
         Escape leave it staged, which is harmless, and confirmAssign() clears
         it before writing.

         Accessible name: Flux forwards only class/style/autofocus from
         <flux:modal> to its <dialog>, so the heading carries an id and x-init
         points the dialog's aria-labelledby at it once Alpine boots (the
         dialog-project-delete trick); the dialog is wire:ignore.self, so the
         attribute survives every re-render. --}}
    <flux:modal
        name="confirm-assign"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'confirm-assign-heading')"
    >
        <div class="space-y-4">
            <div>
                <flux:heading size="lg" id="confirm-assign-heading">
                    @if ($confirmPattern !== null && $confirmEmployee !== null)
                        Assign {{ $confirmPattern->name }} to {{ Hris::employeeName($confirmEmployee) }}?
                    @else
                        Assign shift?
                    @endif
                </flux:heading>
                @if ($confirmPattern !== null)
                    <flux:text class="num mt-2">{{ Hris::patternSummary($confirmPattern) }}</flux:text>
                @endif
                @if ($confirmExisting !== null)
                    <p class="mt-2 text-sm text-warning">
                        They currently work <span class="num">{{ Hris::hoursOf($confirmExisting) }}</span>.
                        Saving changes it from the effective date. Earlier dates keep it.
                    </p>
                @endif
            </div>
            <div class="grid gap-4">
                @include('livewire.roster.partials.effective-from', ['model' => 'confirmFrom', 'fieldId' => 'confirm-from'])
            </div>
            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="confirmAssign">Assign shift</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Weekly locations dialog — no counterpart in the reference. Seven
         selects, Monday first and Sunday last, bound to locationDays (the one
         unlocked draft on this page: SaveRosterLocations re-validates every
         key and value). openLocations() seeds them from the stored week and
         stages locationsEmployeeId; saveLocations() writes. The wire:key on
         the rows re-mounts the selects whenever the employee changes, so a
         reopened dialog shows that week, not the last one edited. Rest days
         are a hint, not a lock: a place set for one simply waits for a
         pattern that works that day.

         Accessible name: the confirm-assign trick — the heading carries an
         id and x-init points the dialog's aria-labelledby at it. --}}
    <flux:modal
        name="weekly-locations"
        class="w-full max-w-3xl"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'weekly-locations-heading')"
    >
        <div class="space-y-4">
            <div>
                <flux:heading size="lg" id="weekly-locations-heading">
                    Weekly schedule{{ $locationsEmployee !== null ? ' · '.Hris::employeeName($locationsEmployee) : '' }}
                </flux:heading>
                <flux:text class="mt-1">
                    Choose a shift and location for each day. Default shift keeps
                    {{ $locationsEmployee === null ? 'the employee’s existing schedule' : ($locationsBaseShift === null ? '8:00 AM–5:00 PM, Monday–Friday' : Hris::hoursOf($locationsBaseShift)) }}.
                    A selected pattern works on that weekday; choose Rest day for a day off.
                    Overnight hours and location belong to the day the shift starts.
                </flux:text>
                <flux:text class="mt-2 text-sm">
                    This weekly schedule repeats from its effective date. Earlier dates keep the
                    schedule they had, and finalized payslips stay unchanged. Work from home allows
                    punches from anywhere; Assigned worksite uses
                    {{ $locationsAssignedSite?->name ?? 'the worksite in Employee Records' }}.
                </flux:text>
            </div>

            <div class="space-y-3" wire:key="weekly-locations-{{ $locationsEmployeeId ?: 'none' }}">
                @foreach ([1, 2, 3, 4, 5, 6, 0] as $day)
                    <div class="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-[5.5rem_minmax(0,1fr)_minmax(0,1fr)]">
                        <div class="self-start sm:pt-7">
                            <p class="text-sm font-medium">{{ HrisFormat::weekday($day, short: false) }}</p>
                            @if (in_array($day, $locationsRestDays, true))
                                <p class="text-xs text-muted-foreground" data-rest-day-hint>Rest day</p>
                            @endif
                        </div>
                        <div class="min-w-0 space-y-1">
                            <label class="text-xs text-muted-foreground" for="shift-day-{{ $day }}">Shift</label>
                            <x-kit.select id="shift-day-{{ $day }}" wire:model.live="shiftDays.{{ $day }}" aria-label="{{ HrisFormat::weekday($day, short: false) }} shift">
                                <flux:select.option value="DEFAULT">Default shift</flux:select.option>
                                <flux:select.option value="REST">Rest day</flux:select.option>
                                @if (isset($locationsSavedShifts[$day]) && ! $locationsSavedShifts[$day]->is_rest_day && $locationsSavedShifts[$day]->shift_type_id === null)
                                    <flux:select.option value="KEEP">{{ $locationsSavedShifts[$day]->shift_name }}</flux:select.option>
                                @endif
                                @foreach ($patterns as $p)
                                    <flux:select.option value="{{ $p->id }}">{{ $p->name }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                            @php
                                $selectedPattern = $patterns->firstWhere('id', $shiftDays[$day] ?? 'DEFAULT');
                            @endphp
                            @if ($selectedPattern !== null)
                                <p class="text-xs text-muted-foreground">{{ Hris::patternSummary($selectedPattern) }}</p>
                            @elseif (($shiftDays[$day] ?? '') === 'KEEP' && isset($locationsSavedShifts[$day]))
                                <p class="text-xs text-muted-foreground">{{ Hris::weekdayHours($locationsSavedShifts[$day]) }} · pattern deleted</p>
                            @endif
                        </div>
                        <div class="min-w-0 space-y-1">
                            <label class="text-xs text-muted-foreground" for="loc-day-{{ $day }}">Location</label>
                            <x-kit.select
                                id="loc-day-{{ $day }}"
                                wire:model="locationDays.{{ $day }}"
                                aria-label="{{ HrisFormat::weekday($day, short: false) }} location"
                            >
                                <flux:select.option value="DEFAULT">Assigned worksite ({{ $locationsAssignedSite?->name ?? 'none set' }})</flux:select.option>
                                <flux:select.option value="WFH">Work from home</flux:select.option>
                                {{-- An inactive site is offered only where this
                                     weekday already names it. --}}
                                @foreach ($sites as $s)
                                    @continue(! $s->is_active && ($locationsSaved[$day] ?? null) !== $s->id)
                                    <flux:select.option value="{{ $s->id }}">{{ $s->name }}{{ $s->is_active ? '' : ' (inactive)' }}</flux:select.option>
                                @endforeach
                            </x-kit.select>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                @include('livewire.roster.partials.effective-from', ['model' => 'weekFrom', 'fieldId' => 'week-from'])
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button
                    variant="primary"
                    wire:click="saveLocations"
                    wire:loading.attr="disabled"
                    wire:target="saveLocations"
                >
                    Save schedule
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Temporary schedule — one date or a From–To period. The
         first date, last date and the three choices are drafts the commands
         validate; the schedule being changed is a locked id loaded from the
         employee's own history. A shift keeps rest days unless work on them
         is chosen by name; a saved schedule is changed or cancelled, never
         overwritten. --}}
    <flux:modal
        name="date-schedule"
        class="w-full max-w-xl"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'date-schedule-heading')"
    >
        <div class="space-y-4">
            <div>
                <flux:heading size="lg" id="date-schedule-heading">{{ $editingSchedule === null ? 'Temporary schedule' : 'Change temporary schedule' }}{{ $dateEmployee === null ? '' : ' · '.Hris::employeeName($dateEmployee) }}</flux:heading>
                <flux:text class="mt-1">
                    A different shift or location for one date, or for every date from the first to the last.
                    The weekly schedule continues before and after it. The shift applies on working days and
                    keeps rest days. For an overnight shift, choose the dates it starts on.
                </flux:text>
            </div>

            @if ($editingSchedule !== null && $editingSchedule->state === 'ACTIVE')
                <p class="rounded-lg bg-muted px-3 py-2 text-sm" data-running-note>
                    This schedule started on <span class="num">{{ HrisFormat::date($editingSchedule->from) }}</span>.
                    The dates already worked keep it. A change applies from the next date it can start.
                </p>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="schedule-date" class="text-sm font-medium">First date</label>
                    <x-kit.date-field id="schedule-date" wire:model.live="scheduleDate" aria-label="First date" :disabled="$editingSchedule !== null && $editingSchedule->state === 'ACTIVE'" />
                </div>
                <div class="space-y-1">
                    <label for="schedule-end" class="text-sm font-medium">Last date</label>
                    <x-kit.date-field id="schedule-end" wire:model.live="scheduleEnd" aria-label="Last date" />
                    <p class="text-xs text-muted-foreground">Leave blank for one date.</p>
                </div>
            </div>
            @if ($effectiveDate !== null)
                <p class="rounded-lg bg-muted px-3 py-2 text-sm">
                    <span class="text-muted-foreground">Scheduled on the first date:</span>
                    {{ ! $effectiveDate['is_work_day'] ? 'Rest day' : ($effectiveDate['mode'] === DomainShift::MODE_FLEXIBLE ? HrisFormat::hoursPerDay($effectiveDate['required_hours']) : Hris::fmtHm($effectiveDate['start_time']).'–'.Hris::fmtHm($effectiveDate['end_time'])) }}
                    · {{ $effectiveDate['location_label'] }}
                </p>
            @endif
            <div class="space-y-1" wire:key="date-shift-{{ $dateEmployeeId }}-{{ $scheduleDate }}-{{ $editingAssignmentId }}">
                <x-kit.select label="Shift" wire:model="dateShiftChoice" aria-label="Shift for these dates">
                    <flux:select.option value="DEFAULT">Use weekly schedule</flux:select.option>
                    <flux:select.option value="REST">Rest day</flux:select.option>
                    @if ($editingSchedule !== null && $editingSchedule->shift_choice === 'KEEP')
                        <flux:select.option value="KEEP">{{ $editingSchedule->shift }}</flux:select.option>
                    @endif
                    @foreach ($patterns as $p)
                        <flux:select.option value="{{ $p->id }}">{{ $p->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            </div>
            {{-- A shift change keeps rest days: work on a date the weekly
                 schedule rests is this separate, explicit choice. --}}
            @if ($scheduleEnd !== '')
                <div wire:key="date-rest-range-{{ $dateEmployeeId }}-{{ $editingAssignmentId }}">
                    <flux:checkbox wire:model="dateWorkOnRestDay" label="Schedule work on rest days" description="Rest days inside these dates stay rest days unless this is ticked." />
                </div>
            @elseif ($effectiveDate !== null && ! $effectiveDate['usually_works'])
                <div wire:key="date-rest-{{ $dateEmployeeId }}-{{ $scheduleDate }}-{{ $editingAssignmentId }}">
                    <flux:checkbox wire:model="dateWorkOnRestDay" label="Schedule work on this rest day" description="The weekly schedule rests on this date. Tick this to apply the shift above to it." />
                </div>
            @endif
            <div class="space-y-1" wire:key="date-location-{{ $dateEmployeeId }}-{{ $scheduleDate }}-{{ $editingAssignmentId }}">
                <x-kit.select label="Location" wire:model="dateLocationChoice" aria-label="Location for these dates">
                    <flux:select.option value="DEFAULT">Use weekly schedule</flux:select.option>
                    <flux:select.option value="WFH">Work from home</flux:select.option>
                    @foreach ($sites as $site)
                        @continue(! $site->is_active && $editingSchedule?->location_choice !== $site->id)
                        <flux:select.option value="{{ $site->id }}">{{ $site->name }}{{ $site->is_active ? '' : ' (inactive)' }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            </div>
            <flux:text class="text-sm">
                Past dates cannot be changed. Today can change only until today’s shift starts.
                @if ($editingSchedule !== null)
                    Choose “Use weekly schedule” for both fields to cancel this temporary schedule.
                @endif
            </flux:text>

            @if ($savedSchedules !== [])
                <div class="space-y-2">
                    <p class="text-sm font-medium">Saved temporary schedules</p>
                    <div class="max-h-44 space-y-1 overflow-y-auto">
                        @foreach ($savedSchedules as $saved)
                            <div wire:key="saved-{{ $saved->id }}" @class(['flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3 py-2 text-sm', 'border-primary' => $saved->id === $editingAssignmentId, 'border-border' => $saved->id !== $editingAssignmentId])>
                                <div class="min-w-0 flex-1">
                                    <span class="num">{{ $saved->from === $saved->applies_through ? HrisFormat::date($saved->from) : HrisFormat::date($saved->from).' to '.HrisFormat::date($saved->applies_through) }}</span>
                                    <span class="text-muted-foreground"> · {{ $saved->shift ?? 'Weekly shift' }} · {{ $saved->place ?? 'Weekly location' }}{{ $saved->state === 'ACTIVE' ? ' · started' : '' }}</span>
                                </div>
                                <div class="flex shrink-0 gap-3">
                                    <button type="button" class="font-medium text-primary hover:underline" wire:click="editTemporary('{{ $saved->id }}')">Change</button>
                                    <button type="button" class="font-medium text-danger hover:underline" wire:click="cancelTemporary('{{ $saved->id }}')" wire:confirm="{{ $saved->state === 'ACTIVE' ? 'End this temporary schedule? The dates already worked keep it.' : 'Cancel this temporary schedule?' }}">{{ $saved->state === 'ACTIVE' ? 'End' : 'Cancel' }}</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="flex flex-wrap justify-end gap-2">
                @if ($editingSchedule !== null)
                    <flux:button variant="ghost" wire:click="newTemporary">New schedule</flux:button>
                @endif
                <flux:modal.close><flux:button variant="outline">Close</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="saveDateSchedule" wire:loading.attr="disabled" wire:target="saveDateSchedule">{{ $editingSchedule === null ? 'Save temporary schedule' : 'Save changes' }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Review — what a change affects, before it is saved. The
         lines are the command's own report, held in locked state; Confirm
         saves with the token of exactly this report, so a change whose impact
         moved meanwhile comes back here instead of being saved. --}}
    @include('livewire.roster.partials.schedule-review')

    {{-- Schedule history — a read, scoped like the row. Cancel
         appears only where the command would allow it, and the command
         decides again. --}}
    <flux:modal
        name="schedule-history"
        class="w-full max-w-2xl"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'schedule-history-heading')"
    >
        <div class="space-y-5">
            <div>
                <flux:heading size="lg" id="schedule-history-heading">Schedule history{{ $historyEmployee === null ? '' : ' · '.Hris::employeeName($historyEmployee) }}</flux:heading>
                <flux:text class="mt-1">Each change applies from its date until the next one. Cancelled changes are kept here and never applied.</flux:text>
            </div>

            @if ($history !== null)
                <section class="space-y-2">
                    <p class="text-sm font-medium">Default shift and weekly schedule</p>
                    @forelse ($history['changes'] as $change)
                        <div wire:key="change-{{ $change->id }}" class="rounded-lg border border-border px-3 py-2 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="num font-medium">From {{ HrisFormat::date($change->effective_from) }}</span>
                                <span @class(['pill', 'pill-info' => $change->state === 'UPCOMING', 'pill-success' => $change->state === 'IN_EFFECT', 'pill-muted' => $change->state === 'CANCELLED'])>{{ ['UPCOMING' => 'Scheduled', 'IN_EFFECT' => 'In effect', 'CANCELLED' => 'Cancelled'][$change->state] }}</span>
                                @if ($change->source !== 'MANUAL')
                                    <span class="pill pill-muted">{{ $change->source === 'IMPORT' ? 'Import' : 'Pattern edit' }}</span>
                                @endif
                                @if ($change->can_cancel)
                                    <button type="button" class="ml-auto font-medium text-danger hover:underline" wire:click="cancelChange('{{ $change->id }}')" wire:confirm="Cancel this scheduled change? The current schedule continues.">Cancel</button>
                                @endif
                            </div>
                            <ul class="mt-1 text-muted-foreground">
                                @foreach ($change->sets as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Saved{{ $change->made_by === null ? '' : ' by '.$change->made_by }}{{ $change->made_at === null ? '' : ' · '.$change->made_at }}@if ($change->state === 'CANCELLED') · cancelled{{ $change->cancelled_by === null ? '' : ' by '.$change->cancelled_by }}{{ $change->cancelled_at === null ? '' : ' · '.$change->cancelled_at }}@endif
                            </p>
                        </div>
                    @empty
                        <p class="text-sm text-muted-foreground">No dated change yet.</p>
                    @endforelse
                    @if ($history['assumed_before'] !== null)
                        <p class="rounded-lg bg-muted px-3 py-2 text-sm" data-assumed-note>
                            <span class="font-medium">Before {{ HrisFormat::date($history['assumed_before']) }}: assumed.</span>
                            Schedule history was not kept before that date, so earlier dates use the schedule the employee had then.
                            @if ($history['may_differ_through'] !== null)
                                The audit trail records a schedule change on or before {{ HrisFormat::date($history['may_differ_through']) }}, so the schedule may have differed up to that date.
                            @endif
                        </p>
                    @endif
                </section>

                <section class="space-y-2">
                    <p class="text-sm font-medium">Temporary schedules</p>
                    @forelse ($history['temporary'] as $row)
                        <div wire:key="temporary-{{ $row->id }}" class="rounded-lg border border-border px-3 py-2 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="num font-medium">{{ $row->from === $row->to ? HrisFormat::date($row->from) : HrisFormat::date($row->from).' to '.HrisFormat::date($row->to) }}</span>
                                <span @class(['pill', 'pill-info' => $row->state === 'UPCOMING', 'pill-success' => $row->state === 'ACTIVE', 'pill-muted' => in_array($row->state, ['ENDED', 'CANCELLED'], true)])>{{ ['UPCOMING' => 'Scheduled', 'ACTIVE' => 'In effect', 'ENDED' => 'Ended', 'CANCELLED' => 'Cancelled'][$row->state] }}</span>
                                @if ($row->can_change)
                                    <button type="button" class="ml-auto font-medium text-danger hover:underline" wire:click="cancelTemporary('{{ $row->id }}')" wire:confirm="{{ $row->state === 'ACTIVE' ? 'End this temporary schedule? The dates already worked keep it.' : 'Cancel this temporary schedule?' }}">{{ $row->state === 'ACTIVE' ? 'End' : 'Cancel' }}</button>
                                @endif
                            </div>
                            <p class="mt-1 text-muted-foreground">
                                {{ $row->shift ?? 'Weekly shift' }}{{ $row->hours === null ? '' : ' · '.$row->hours }} · {{ $row->place ?? 'Weekly location' }}{{ $row->works_rest_days ? ' · rest days worked' : '' }}
                            </p>
                            @if ($row->state !== 'CANCELLED' && $row->applies_through !== $row->to)
                                <p class="mt-1 text-muted-foreground">Applied through <span class="num">{{ HrisFormat::date($row->applies_through) }}</span>.</p>
                            @endif
                            <p class="mt-1 text-xs text-muted-foreground">
                                Saved{{ $row->made_by === null ? '' : ' by '.$row->made_by }}{{ $row->made_at === null ? '' : ' · '.$row->made_at }}@if ($row->cancelled_at !== null) · {{ $row->state === 'CANCELLED' ? 'cancelled' : 'changed' }}{{ $row->cancelled_by === null ? '' : ' by '.$row->cancelled_by }} · {{ $row->cancelled_at }}@endif
                            </p>
                        </div>
                    @empty
                        <p class="text-sm text-muted-foreground">No temporary schedule yet.</p>
                    @endforelse
                </section>
            @endif

            <div class="flex justify-end">
                <flux:modal.close><flux:button variant="outline">Close</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
