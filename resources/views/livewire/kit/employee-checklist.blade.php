{{--
    View for App\Livewire\Kit\EmployeeChecklist — see that class for the API.

    An inline checklist, not a popover: the payroll "Add employees" recipe
    (payroll/run-workspace.blade.php) reduced to what a dialog field needs — a
    search box, a summary of who is ticked, and a scrolling list of active
    employees with a native checkbox per row. The LIST is server-rendered from
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

    {{-- Who is ticked right now, whether or not the search is showing them. --}}
    <p class="text-xs text-muted-foreground" aria-live="polite" data-kit-checklist-summary>
        {{ $summary }}
    </p>

    <div
        id="{{ $listId }}"
        role="group"
        aria-label="{{ $ariaLabel }}"
        wire:key="ecl-list"
        class="max-h-72 overflow-y-auto rounded-md border border-border"
    >
        @if (count($visible) === 0)
            <p class="px-3 py-6 text-center text-sm text-muted-foreground">
                No employee matches that search.
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
                    <span class="block truncate font-medium">{{ \App\Support\EmployeeName::display($employee) }}</span>
                    <span class="block truncate text-xs text-muted-foreground">
                        <span class="num">{{ $employee->employee_code }}</span>{{ $employee->position ? ' · '.$employee->position : '' }}
                    </span>
                </span>
            </label>
        @endforeach
    </div>
</div>
