{{--
    View for App\Livewire\Kit\EmployeeChecklist — see that class for the API.

    An inline checklist, not a popover: the payroll "Add employees" recipe
    (payroll/run-workspace.blade.php) reduced to what a dialog field needs — a
    search box, a summary of who is ticked, and a scrolling employee list
    with a native checkbox per row. The LIST is server-rendered from
    the scoped, searched query; a tick round-trips through toggle(), which
    re-checks the id against the actor's directory.

    Each row is a <label> wrapping its checkbox, so clicking the name toggles
    the box once (the label forwards the click) and there is no double-fire to
    guard against.
--}}
<div class="space-y-2" data-kit-employee-checklist="{{ $name }}">
    <div class="relative">
        <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <input
            id="{{ $searchId }}"
            type="text"
            aria-label="Search {{ strtolower($ariaLabel) }}"
            aria-controls="{{ $listId }}"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            @disabled($disabled)
            wire:model.live.debounce.150ms="search"
            class="h-10 w-full rounded-lg border border-input bg-transparent pl-9 pr-3 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
        />
    </div>

    @if ($withFilters)
        <div class="grid min-w-0 gap-3 sm:grid-cols-2" aria-label="Employee filters">
            <div class="min-w-0 space-y-1.5">
                <span class="block text-xs font-medium text-muted-foreground">Position</span>
                <x-kit.filter-select label="Position filter" wire:model.live="position" :options="$positionOptions" />
            </div>
            <div class="min-w-0 space-y-1.5">
                <span class="block text-xs font-medium text-muted-foreground">Active status</span>
                <x-kit.filter-select label="Active status filter" wire:model.live="active" :options="['ALL' => 'Active and inactive', 'ACTIVE' => 'Active employees', 'INACTIVE' => 'Inactive employees']" />
            </div>
            <div class="min-w-0 space-y-1.5">
                <span class="block text-xs font-medium text-muted-foreground">Employment type</span>
                <x-kit.filter-select label="Employment type filter" wire:model.live="employmentStatus" :options="$employmentOptions" />
            </div>
            <div class="min-w-0 space-y-1.5">
                <span class="block text-xs font-medium text-muted-foreground">Sort by</span>
                <x-kit.select aria-label="Sort employees" wire:model.live="sort">
                    <option value="name-asc">Name: A–Z</option>
                    <option value="name-desc">Name: Z–A</option>
                    <option value="employee-id-asc">Employee ID: A–Z</option>
                    <option value="employee-id-desc">Employee ID: Z–A</option>
                    <option value="position">Position, then name</option>
                </x-kit.select>
            </div>
        </div>

        <div class="space-y-3 rounded-lg border border-border bg-muted/30 p-3">
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs" aria-live="polite" data-kit-checklist-summary>
                <p class="text-muted-foreground"><span class="num font-semibold text-foreground">{{ number_format($matchingCount) }}</span> matching · <span class="num font-semibold text-foreground">{{ number_format(count($selectedIds)) }}</span> selected</p>
                @if ($search !== '' || $position !== 'ALL' || $active !== 'ALL' || $employmentStatus !== 'ALL' || $sort !== 'name-asc')
                    <button type="button" wire:click="resetFilters" @disabled($disabled) class="font-medium text-primary hover:underline disabled:opacity-50">Reset filters</button>
                @endif
            </div>
            <div class="flex flex-wrap gap-2" aria-label="Employee selection actions">
                <flux:button type="button" size="sm" wire:click="selectAll" wire:loading.attr="disabled" wire:target="selectAll,deselectAll,deselectMatching" :disabled="$disabled || $matchingCount === 0 || $matchedSelectedCount === $matchingCount" class="grow sm:grow-0">{{ $search !== '' || $position !== 'ALL' || $active !== 'ALL' || $employmentStatus !== 'ALL' ? 'Select all matching' : 'Select all' }}</flux:button>
                <flux:button type="button" size="sm" wire:click="deselectMatching" wire:loading.attr="disabled" wire:target="selectAll,deselectAll,deselectMatching" :disabled="$disabled || $matchedSelectedCount === 0" class="grow sm:grow-0">Deselect matching</flux:button>
                <flux:button type="button" size="sm" wire:click="deselectAll" wire:loading.attr="disabled" wire:target="selectAll,deselectAll,deselectMatching" :disabled="$disabled || $selectedIds === []" class="grow sm:grow-0">Deselect all</flux:button>
            </div>
            <p class="text-xs leading-relaxed text-muted-foreground">Filters change the list below. Your selection stays the same until you select or deselect employees.@if ($selectionLimit !== null) Choose up to {{ number_format($selectionLimit) }} employees.@endif</p>
            @error('value') <p class="text-xs text-danger" role="alert">{{ $message }}</p> @enderror
        </div>
    @else
        {{-- Who is ticked right now, whether or not the search is showing them. --}}
        <p class="text-xs text-muted-foreground" aria-live="polite" data-kit-checklist-summary>
            {{ $summary }}
        </p>
    @endif

    <div
        id="{{ $listId }}"
        role="group"
        aria-label="{{ $ariaLabel }}"
        wire:key="ecl-list"
        class="max-h-72 overflow-y-auto rounded-md border border-border"
    >
        @if (count($visible) === 0)
            <p class="px-3 py-6 text-center text-sm text-muted-foreground">
                {{ $withFilters ? 'No employees match these filters. Try another search or reset the filters.' : 'No employee matches that search.' }}
            </p>
        @endif

        @foreach ($visible as $employee)
            @php $on = in_array($employee->id, $selectedIds, true); @endphp
            <label
                wire:key="ecl-option-{{ $employee->id }}"
                for="{{ $listId }}-{{ $employee->id }}"
                class="flex cursor-pointer items-center gap-3 border-b border-border px-3 py-2 text-sm last:border-b-0 hover:bg-accent hover:text-accent-foreground {{ $disabled ? 'cursor-not-allowed opacity-50' : '' }}"
            >
                <input
                    id="{{ $listId }}-{{ $employee->id }}"
                    type="checkbox"
                    name="{{ $name }}[]"
                    value="{{ $employee->id }}"
                    aria-label="Select {{ \App\Support\EmployeeName::display($employee) }}"
                    class="size-4 rounded border-border accent-[var(--color-primary)]"
                    @checked($on)
                    @disabled($disabled)
                    wire:click="toggle('{{ $employee->id }}')"
                />
                <span class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                    <span class="num text-xs font-semibold">{{ \App\Support\EmployeeName::initials($employee) }}</span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block break-words font-medium">{{ \App\Support\EmployeeName::display($employee) }}</span>
                    {{-- The Employee ID is optional; position and inactive status
                         distinguish names when reports include former staff. --}}
                    @php
                        $identity = \App\Support\EmployeeIdentity::besideName($employee);
                        $tail = \App\Support\EmployeeIdentity::meta(
                            trim((string) $employee->position) !== '' ? $employee->position : 'No position',
                            $employee->is_active ? null : 'inactive',
                        );
                    @endphp
                    <span class="block break-words text-xs text-muted-foreground">
                        <span class="num">{{ $identity }}</span>{{ $identity !== '' && $tail !== '' ? ' · ' : '' }}{{ $tail }}
                    </span>
                </span>
            </label>
        @endforeach
    </div>
</div>
