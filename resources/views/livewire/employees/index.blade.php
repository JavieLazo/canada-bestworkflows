{{--
    Employee Records list — port of employees-page.tsx:180-327.

    Same filters (search, status with an Inactive row under a separator,
    position), same default sort (employee, ascending), same empty copy, same
    pills.

    Department: a sortable column (the department, its unit on a muted second
    line; "Not placed" for a position placed nowhere; "—" for a record with no
    linked position) and "Filter by department" (All departments, Not placed,
    each department with its units indented). A department lists its units'
    people too; a unit lists only its own. The CSV follows the filter but
    keeps its columns.

    DELIBERATE DIVERGENCE — the reference has ONE identifier and one column for
    it. This list carries BOTH, in this order: the ID the system mints (never
    blank, unique, what the rest of the system keys on) and the EMPLOYEE ID the
    company assigns by hand (blank until HR is given one). Both are columns and
    both are searched, because HR types whichever number is on the paper in
    front of them. The record page carries no ID field, so this list is where
    the minted one is read.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
    use App\Platform\Files\PortraitUrl;
    use App\Support\EmployeeIdentity;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Employee Records</h1>
        <p class="text-sm text-muted-foreground">
            Manage employee records: attendance, payroll, and accounts all resolve against these records.
        </p>
    </div>

    @include('livewire.employees.partials.view-switch', ['activeView' => 'employees'])

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                type="text"
                aria-label="Search employees"
                placeholder="Search ID, Employee ID, name, position or email"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />

            <x-kit.filter-select label="Filter by status" class="w-[11rem]" wire:model.live="status">
                <flux:select.option value="ALL">All statuses</flux:select.option>
                @foreach ($statuses as $s)
                    <flux:select.option value="{{ $s }}">{{ $s }}</flux:select.option>
                @endforeach
                {{-- SelectSeparator (employees-page.tsx:210): a native select has
                     no separator, so a disabled rule stands in. --}}
                <flux:select.option value="__separator__" disabled>──────────</flux:select.option>
                <flux:select.option value="INACTIVE">Inactive</flux:select.option>
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by position" class="w-[13rem]" wire:model.live="position">
                <flux:select.option value="ALL">All positions</flux:select.option>
                @foreach ($positions as $p)
                    <flux:select.option value="{{ $p }}">{{ $p }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <x-kit.filter-select label="Filter by department" class="w-[13rem]" wire:model.live="department">
                @foreach ($departments as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                {{-- Export only: Employee Records has no Import CSV. --}}
                <x-kit.export-button :href="route('employees.export', array_filter(['q' => $search, 'status' => $status, 'position' => $position, 'department' => $department, 'sort' => $sortKey, 'dir' => $sortDir]))" />
                <flux:button variant="primary" icon="user-plus" wire:click="$dispatch('open-add-employee')">
                    Add employee
                </flux:button>
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="employee_id_no" label="Employee ID" />
                        <x-kit.sort-header field="employee" label="Employee" />
                        <x-kit.sort-header field="department" label="Department" />
                        <x-kit.sort-header field="email" label="Email" />
                        {{-- DELIBERATE DIVERGENCE — headed "Employment Status"; the
                             reference says "Status" (employees-page.tsx:256-258). The
                             cell shows the employment-status pill, and "Status" reads as
                             active/inactive. --}}
                        <x-kit.sort-header field="status" label="Employment Status" />
                    </tr>
                </thead>
                <tbody>
                    @if ($visibleCount === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="6" title="No employee matches that search." />
                        </tr>
                    @endif
                    @foreach ($list as $e)
                        <tr
                            wire:key="{{ $e->id }}"
                            tabindex="0"
                            x-data
                            x-on:click="window.location = @js(route('employees.show', $e->id))"
                            x-on:keydown.enter.prevent="window.location = @js(route('employees.show', $e->id))"
                            x-on:keydown.space.prevent="window.location = @js(route('employees.show', $e->id))"
                            class="cursor-pointer transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
                        >
                            <td class="num whitespace-nowrap align-middle">{{ EmployeeIdentity::index($e) }}</td>
                            <td class="num whitespace-nowrap align-middle">{{ EmployeeIdentity::number($e) }}</td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                        @if ($e->photo_path)
                                            <img
                                                src="{{ PortraitUrl::for($e->photo_path) }}"
                                                alt="{{ EmployeeFields::employeeName($e) }}"
                                                class="h-full w-full object-cover"
                                            />
                                        @else
                                            <span class="num text-xs font-semibold">{{ EmployeeFields::initials($e) }}</span>
                                        @endif
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ EmployeeFields::employeeName($e) }}</p>
                                        <p class="truncate text-xs text-muted-foreground">{{ $e->position ?? '—' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap align-middle">
                                @if ($e->department_name !== null)
                                    <span class="block">{{ $e->department_name }}</span>
                                    @if ($e->unit_name !== null)
                                        <span class="block text-xs text-muted-foreground">{{ $e->unit_name }}</span>
                                    @endif
                                @elseif (is_string($e->position_id) && $e->position_id !== '')
                                    Not placed
                                @else
                                    —
                                @endif
                            </td>
                            <td class="whitespace-nowrap align-middle text-muted-foreground">{{ $e->email ?? '—' }}</td>
                            <td class="align-middle">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="{{ EmployeeFields::isRegular($e->employment_status) ? 'pill pill-success' : 'pill pill-info' }}">
                                        {{ EmployeeFields::employmentStatusLabel($e->employment_status) }}
                                    </span>
                                    @unless ($e->is_active)
                                        <span class="pill pill-muted">Inactive</span>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <x-kit.pagination :paginator="$list" noun="employees" />
    </section>

    <livewire:employees.add-employee-dialog />
</div>
