{{--
    Shifting Settings — the port of
    src/app/(protected)/records/shifts/shift-types-page.tsx. Same columns in
    the same order (ID, Name, Mode, Hours, Break, Work week, Grace, Action),
    same search, same default sort (name, ascending), same dialog fields —
    mode, work-week day toggles, required hours, grace and the break rules —
    same validation messages and copy.
--}}
@php
    use App\Livewire\Records\Support\Hris;

    $flexible = $formMode === 'FLEXIBLE';
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Shifting Settings</h1>
        <p class="text-sm text-muted-foreground">
            The shift patterns that can be assigned to an employee &mdash; fixed hours, or
            flexible ones that only owe a daily total.
        </p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search shift types"
                placeholder="Search ID or name"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <x-kit.export-button :href="route('records.shifts.export', array_filter(['q' => $search, 'sort' => $sortKey, 'dir' => $sortDir]))" />
                <livewire:kit.csv-import table="records.shifts" :template="route('records.shifts.export', ['template' => 1])" key="import-shifts" />
                <flux:button variant="primary" icon="plus" wire:click="openAdd">
                    Add shift type
                </flux:button>
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="name" label="Name" />
                        <x-kit.sort-header field="mode" label="Mode" />
                        <th>Hours</th>
                        <th>Break</th>
                        <th>Work week</th>
                        <th class="text-right">Grace</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->total() === 0)
                        <tr wire:key="empty">
                            {{-- colSpan 7 as the source writes it (shift-types-page.tsx:250). --}}
                            <x-kit.empty-state :colspan="7" title="No shift type matches that search." />
                        </tr>
                    @endif
                    @foreach ($rows as $row)
                        @php $usedBy = $usage[$row->getKey()] ?? 0; @endphp
                        <tr wire:key="row-{{ $row->getKey() }}">
                            <td class="num whitespace-nowrap">{{ $row->code }}</td>
                            <td class="font-medium">{{ $row->name }}</td>
                            <td>
                                <span class="{{ $row->mode === 'FLEXIBLE' ? 'pill pill-info' : 'pill pill-muted' }}">
                                    {{ $row->mode === 'FLEXIBLE' ? 'Flexible' : 'Fixed' }}
                                </span>
                            </td>
                            <td class="num whitespace-nowrap">
                                @if ($row->mode === 'FLEXIBLE')
                                    {{ $row->required_hours ?? 0 }} h per day
                                @else
                                    {{ Hris::fmtHm($row->start_time) }}&ndash;{{ Hris::fmtHm($row->end_time) }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($row->break_start !== null && $row->break_end !== null)
                                    <span class="num">
                                        {{ Hris::fmtHm($row->break_start) }}&ndash;{{ Hris::fmtHm($row->break_end) }}
                                        @if ($row->requires_break_punch)
                                            <span class="ml-2 text-xs text-muted-foreground">clock out</span>
                                        @endif
                                    </span>
                                @elseif ($row->break_minutes > 0)
                                    <span class="num text-muted-foreground">{{ $row->break_minutes }} min unpaid</span>
                                @else
                                    <span class="text-muted-foreground">&mdash;</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">{{ Hris::describeWeek($row->work_days) }}</td>
                            <td class="num text-right text-muted-foreground">
                                {{ $row->mode === 'FLEXIBLE' ? '—' : "{$row->grace_minutes} min" }}
                            </td>
                            <td class="text-right">
                                <x-kit.row-actions :label="$row->name">
                                    <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->getKey() }}')">
                                        Edit
                                    </flux:menu.item>
                                    <flux:menu.item
                                        icon="trash"
                                        :variant="$usedBy > 0 ? 'default' : 'danger'"
                                        :disabled="$usedBy > 0"
                                        wire:click="confirmDelete('{{ $row->getKey() }}')"
                                    >
                                        {{ $usedBy > 0 ? "Delete · in use by {$usedBy}" : 'Delete' }}
                                    </flux:menu.item>
                                </x-kit.row-actions>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$rows" noun="shift types" />
    </section>

    {{-- The AlertDialog (shift-types-page.tsx:337-366). --}}
    <flux:modal wire:model.self="confirmOpen" class="w-full max-w-md">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">Delete {{ $deleting?->name }}?</flux:heading>
                <flux:text>
                    {{ $deleting?->code }} is removed for good. Employees already rostered on these
                    hours keep them &mdash; they simply stop being offered as a pattern.
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="deleteConfirmed">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- The ShiftTypeDialog (shift-types-page.tsx:369-634). --}}
    <flux:modal wire:model.self="dialogOpen" class="w-full max-w-lg">
        <div class="space-y-4">
            <div class="space-y-2">
                <flux:heading size="lg">
                    {{ $editing !== null ? 'Edit shift type' : 'Add shift type' }}
                    @if ($editing !== null)
                        <span class="num ml-2 text-sm font-normal text-muted-foreground">{{ $editing->code }}</span>
                    @endif
                </flux:heading>
                <flux:text>
                    A fixed pattern clocks against set hours. A flexible one only owes a daily
                    total, whenever it is worked. In Shift Roster, choose a pattern for each weekday or a specific date.
                </flux:text>
                {{--
                    Saving an in-use pattern re-copies it onto the employees rostered on
                    it FROM THE EFFECTIVE DATE (SaveShiftType): every earlier date
                    keeps the hours it was measured against. An edit that affects
                    anything worth a second look is held in the review dialog below.
                --}}
                @if ($editingId !== null && $editingUsage > 0)
                    <flux:text class="text-sm text-muted-foreground">
                        Saving changes the hours of the <span class="num">{{ $editingUsage }}</span> {{ $editingUsage === 1 ? 'employee' : 'employees' }}
                        rostered on this pattern from the effective date. Earlier dates keep the hours they were measured against.
                    </flux:text>
                @endif
            </div>

            <form class="grid gap-4 sm:grid-cols-2" wire:submit="save">
                <div class="space-y-2">
                    <flux:label>Name</flux:label>
                    <flux:input
                        wire:model="formName"
                        aria-invalid="{{ $formError !== null ? 'true' : 'false' }}"
                        class:input="{{ $formError !== null ? 'border-danger!' : '' }}"
                    />
                </div>

                <div class="space-y-2">
                    <flux:label>Mode</flux:label>
                    <x-kit.select aria-label="Mode" wire:model.live="formMode">
                        <flux:select.option value="FIXED">Fixed hours</flux:select.option>
                        <flux:select.option value="FLEXIBLE">Flexible</flux:select.option>
                    </x-kit.select>
                </div>

                <div class="space-y-2 sm:col-span-2">
                    <flux:label>Work week</flux:label>
                    <div class="flex flex-wrap gap-2">
                        @foreach (Hris::WEEK as $day => $label)
                            @php $on = in_array($day, $formWorkDays, true); @endphp
                            <flux:button
                                type="button"
                                size="sm"
                                class="w-14"
                                :variant="$on ? 'primary' : 'outline'"
                                aria-pressed="{{ $on ? 'true' : 'false' }}"
                                wire:click="toggleDay({{ $day }})"
                            >
                                {{ $label }}
                            </flux:button>
                        @endforeach
                    </div>
                    <p class="text-xs text-muted-foreground">
                        The default work week when this pattern is assigned to an employee. Weekday and
                        specific-date selections in Shift Roster override individual days. Payroll divides the monthly
                        rate by the employee’s resulting scheduled workdays.
                    </p>
                </div>

                @if ($flexible)
                    <div class="space-y-2">
                        <flux:label>Required hours per day</flux:label>
                        <flux:input
                            type="number"
                            min="0"
                            step="0.5"
                            class:input="num"
                            wire:model="formRequiredHours"
                        />
                    </div>
                @else
                    <div class="space-y-2">
                        <flux:label>Shift start</flux:label>
                        <x-kit.time-field wire:model="formStartTime" aria-label="Shift start" />
                    </div>
                    <div class="space-y-2">
                        <flux:label>Shift end</flux:label>
                        <x-kit.time-field wire:model="formEndTime" aria-label="Shift end" />
                    </div>
                    <div class="space-y-2">
                        <flux:label>Grace (min)</flux:label>
                        <flux:input type="number" min="0" class:input="num" wire:model="formGrace" />
                    </div>
                @endif

                <div class="flex items-center gap-3 rounded-md border border-border bg-muted/40 p-4 sm:col-span-2">
                    <flux:switch id="has-break" wire:model.live="formHasBreak" />
                    <div>
                        <flux:label for="has-break">Unpaid break</flux:label>
                        <p class="text-xs text-muted-foreground">
                            Time off the clock in the middle of the shift &mdash; lunch, typically.
                        </p>
                    </div>
                </div>

                @if ($formHasBreak && ! $flexible)
                    <div class="space-y-2">
                        <flux:label>Break start</flux:label>
                        <x-kit.time-field wire:model="formBreakStart" aria-label="Break start" />
                    </div>
                    <div class="space-y-2">
                        <flux:label>Break end</flux:label>
                        <x-kit.time-field wire:model="formBreakEnd" aria-label="Break end" />
                    </div>
                @endif

                @if ($formHasBreak)
                    <div class="space-y-2">
                        <flux:label>Break minutes</flux:label>
                        <flux:input type="number" min="0" class:input="num" wire:model="formBreakMinutes" />
                        <p class="text-xs text-muted-foreground">
                            Deducted when the break is not punched.
                        </p>
                    </div>
                @endif

                @if ($formHasBreak && ! $flexible)
                    <div class="flex items-center gap-3 sm:col-span-2">
                        <flux:switch id="requires-break-punch" wire:model="formRequiresBreakPunch" />
                        <flux:label for="requires-break-punch" class="text-sm font-normal text-muted-foreground">
                            Employees clock out and back in for the break
                        </flux:label>
                    </div>
                @endif

                @if ($editingId !== null && $editingUsage > 0)
                    @include('livewire.roster.partials.effective-from', ['model' => 'formEffectiveFrom', 'fieldId' => 'pattern-from'])
                @endif

                @if ($formError !== null)
                    <p class="text-xs text-danger sm:col-span-2">{{ $formError }}</p>
                @endif

                <div class="sm:col-span-2">
                    <flux:button
                        type="submit"
                        variant="primary"
                        :icon="$editing !== null ? 'pencil' : 'plus'"
                    >
                        {{ $editing !== null ? 'Save changes' : 'Add shift type' }}
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
    {{-- Review — what an edit affects for the employees on the pattern,
         before it is saved. Go back returns to the editor as it was left. --}}
    @include('livewire.roster.partials.schedule-review', ['backAction' => 'reviewBack'])
</div>
