{{--
    Shift Roster — the port of src/app/(protected)/roster/roster-page.tsx and
    add-shift-dialog.tsx. Same columns in the same order (ID, Employee, Shift
    pattern, Hours, Break, Grace), same filters, same default sort (employee,
    ascending), same dialog fields and copy.
--}}
@php
    use App\Livewire\Roster\Hris;
    use App\Domain\Attendance\Shift as DomainShift;

    $defaultShift = DomainShift::default();
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Shift Roster</h1>
            <p class="text-sm text-muted-foreground">
                Which shift pattern each employee works. Hours, grace and breaks come from the pattern.
            </p>
        </div>
        {{-- roster-page.tsx:169-175 — a plain link to Records → Shifting Settings,
             ungated exactly as the source leaves it. Settings2 (lucide) has no
             heroicon twin; adjustments-horizontal is the closest Flux icon. --}}
        <a
            href="{{ url('/records/shifts') }}"
            class="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline"
        >
            <flux:icon.adjustments-horizontal class="h-4 w-4" />
            Manage patterns
        </a>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                aria-label="Search employees"
                placeholder="Search code, name or position"
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

            <flux:button variant="primary" class="ml-auto" icon="plus" wire:click="openAssign">
                Assign shift
            </flux:button>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="employee" label="Employee" />
                        <th>Shift pattern</th>
                        <x-kit.sort-header field="shift" label="Hours" />
                        <th>Break</th>
                        <th class="text-right">Grace</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            /** @var \App\Models\EmployeeProfile $employee */
                            $employee = $row['employee'];
                            /** @var \App\Models\Shift|null $shift */
                            $shift = $row['shift'];
                        @endphp
                        <tr wire:key="row-{{ $employee->id }}" @class(['opacity-60' => ! $employee->is_active])>
                            <td class="num whitespace-nowrap align-middle">{{ $employee->employee_code }}</td>
                            <td>
                                <div class="flex items-center gap-3">
                                    {{-- Avatar (h-9 w-9): photo_path exists on the model but no
                                         photo-serving route is wired yet; initials stand in until
                                         the Employees module adds one (source showed the photo
                                         when present, roster-page.tsx:280-287). --}}
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
                                {{-- Inline pattern assignment (roster-page.tsx:300-318). Re-keyed
                                     per attempt so a failed assign snaps back to what is stored. --}}
                                <div wire:key="pattern-{{ $employee->id }}-{{ $shift?->shift_type_id ?? 'none' }}-{{ $assignNonce }}">
                                    <flux:select
                                        class="w-48"
                                        aria-label="{{ Hris::employeeName($employee) }} shift pattern"
                                        placeholder="No pattern"
                                        wire:change="assign('{{ $employee->id }}', $event.target.value)"
                                        wire:loading.attr="disabled"
                                        wire:target="assign"
                                    >
                                        @foreach ($patterns as $p)
                                            <flux:select.option
                                                value="{{ $p->id }}"
                                                :selected="$shift?->shift_type_id === $p->id"
                                            >{{ $p->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                            </td>
                            <td class="num whitespace-nowrap">{{ Hris::hoursOf($shift) }}</td>
                            <td class="num whitespace-nowrap text-muted-foreground">{{ Hris::breakOf($shift) }}</td>
                            <td class="num text-right text-muted-foreground">
                                {{ $shift !== null ? $shift->grace_minutes.' min' : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="6" title="No employee matches those filters." />
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
                    default until then.
                </flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2 sm:col-span-2">
                    <flux:label>Employee</flux:label>
                    {{-- Employees with no shift row come first — they are why this
                         exists (add-shift-dialog.tsx:53-61). The reference used a
                         grouped shadcn Select here, not the searchable picker, so
                         this stays a plain select; <hr> is the SelectSeparator. --}}
                    <div wire:key="dialog-employee-{{ $dialogEmployeeId === '' ? 'none' : $dialogEmployeeId }}">
                        <flux:select
                            aria-label="Employee"
                            placeholder="Select employee"
                            wire:change="dialogEmployeeChanged($event.target.value)"
                        >
                            @foreach ($unassigned as $e)
                                <flux:select.option value="{{ $e->id }}" :selected="$dialogEmployeeId === $e->id">{{ $e->employee_code }} — {{ Hris::employeeName($e) }} · no shift yet</flux:select.option>
                            @endforeach
                            @if ($unassigned->isNotEmpty() && $assigned->isNotEmpty())
                                <hr>
                            @endif
                            @foreach ($assigned as $e)
                                <flux:select.option value="{{ $e->id }}" :selected="$dialogEmployeeId === $e->id">{{ $e->employee_code }} — {{ Hris::employeeName($e) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    @if ($dialogExisting !== null)
                        <p class="text-xs text-warning">
                            {{ Hris::employeeName($dialogSelected) }} already works
                            <span class="num">{{ Hris::fmtHm($dialogExisting->start_time) }}&ndash;{{ Hris::fmtHm($dialogExisting->end_time) }}</span>.
                            Saving replaces it.
                        </p>
                    @endif
                </div>

                <div class="space-y-2 sm:col-span-2">
                    <flux:label>Shift pattern</flux:label>
                    <div wire:key="dialog-pattern-{{ $dialogPatternId === '' ? 'none' : $dialogPatternId }}">
                        <flux:select
                            aria-label="Shift pattern"
                            placeholder="Select pattern"
                            wire:change="dialogPatternChanged($event.target.value)"
                        >
                            @foreach ($patterns as $p)
                                <flux:select.option value="{{ $p->id }}" :selected="$dialogPatternId === $p->id">{{ $p->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    @if ($dialogChosen !== null)
                        <p class="num text-xs text-muted-foreground">{{ Hris::patternSummary($dialogChosen) }}</p>
                    @endif
                </div>

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
</div>
