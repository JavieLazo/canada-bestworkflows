{{--
    Employee Records list — port of employees-page.tsx:180-327.

    Same columns in the same order (ID · Employee · Email · Status), same
    filters (search, status with an Inactive row under a separator, position),
    same default sort (employee, ascending), same empty copy, same pills.
--}}
@php
    use App\Livewire\Employees\EmployeeFields;
    use Illuminate\Support\Facades\Storage;
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Employee Records</h1>
        <p class="text-sm text-muted-foreground">
            The 201 file every other module reads: attendance, payroll and accounts all resolve
            against these records.
        </p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input
                type="text"
                aria-label="Search employees"
                placeholder="Search code, name, position or email"
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

            <flux:button variant="primary" icon="user-plus" class="ml-auto" wire:click="$dispatch('open-add-employee')">
                Add employee
            </flux:button>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <x-kit.sort-header field="id" label="ID" />
                        <x-kit.sort-header field="employee" label="Employee" />
                        <x-kit.sort-header field="email" label="Email" />
                        <x-kit.sort-header field="status" label="Status" />
                    </tr>
                </thead>
                <tbody>
                    @if ($visibleCount === 0)
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="4" title="No employee matches that search." />
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
                            <td class="num whitespace-nowrap align-middle">{{ $e->employee_code }}</td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                                        @if ($e->photo_path)
                                            <img
                                                src="{{ Storage::disk('public')->url($e->photo_path) }}"
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
