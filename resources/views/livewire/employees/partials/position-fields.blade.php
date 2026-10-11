{{--
    Employee Details' Department, Unit and Position, read or edit — on the
    record page (partials/section) and on the hire wizard's Employee Details
    step. Three grid cells, each in a `contents` wrapper keyed
    `<prefix>-department`, `<prefix>-unit` and `<prefix>-position`.

    Expects:
        $prefix     the wire:key prefix ('employment' on the record, 'add' in the wizard)
        $editing    bool
        $employee   the record view (read mode, and the title a record keeps); null in the wizard
        $formState  the Livewire form array (edit mode)
        $choices    PositionPicker::options() (edit mode): departments, units, positions,
                    and the department and unit the drafts point at
        $keep       the stored title that is not on the Position list, offered as
                    "… (not on the Position list)" under EmployeePosition::KEEP; '' for none
        $error      ?string — Position's message
        $required   bool — asterisk on Position

    Read: Department | Unit, then Position. Department is "—" for a record
    with no linked position and "Not placed" for one linked to a position
    placed nowhere; Unit is "—" when the position sits in the department
    itself. A title off the Position list reads with a muted line saying so.

    Edit: Department and Unit only narrow the Position list (form.department_id,
    form.unit_id — drafts the save never reads); Position binds
    form.position_id, the one value stored.
--}}
@php
    use App\Livewire\Employees\Support\PositionPicker;
    use App\Modules\Workforce\Application\EmployeePosition;

    $linked = isset($employee) && is_string($employee->position_id) && $employee->position_id !== '';
    $department = $editing ? $choices['department'] : '';
    $chosenNothing = $department === '';
    $notPlaced = $department === PositionPicker::NOT_PLACED;
@endphp
@if (! $editing)
    <div wire:key="{{ $prefix }}-department" class="contents">
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Department</dt>
            <dd class="mt-1 break-words text-sm">{{ $linked ? ($employee->department_name ?? 'Not placed') : '—' }}</dd>
        </div>
    </div>
    <div wire:key="{{ $prefix }}-unit" class="contents">
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Unit</dt>
            <dd class="mt-1 break-words text-sm">{{ $linked ? ($employee->unit_name ?? '—') : '—' }}</dd>
        </div>
    </div>
    <div wire:key="{{ $prefix }}-position" class="contents">
        <div class="min-w-0">
            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Position</dt>
            <dd class="mt-1 break-words text-sm">{{ $employee->position ?? '—' }}@if ($employee->position_unlisted)<span class="mt-0.5 block text-xs text-muted-foreground">{{ $employee->position }} is not on the Position list.</span>@endif</dd>
        </div>
    </div>
@else
    <div wire:key="{{ $prefix }}-department" class="contents">
        <div class="space-y-2">
            <flux:label>Department</flux:label>
            <x-kit.select wire:model.live="form.department_id" aria-label="Department">
                <flux:select.option value="">Choose a department</flux:select.option>
                <flux:select.option value="{{ PositionPicker::NOT_PLACED }}">Not placed</flux:select.option>
                @foreach ($choices['departments'] as $option)
                    <flux:select.option value="{{ $option['id'] }}">{{ $option['name'] }}</flux:select.option>
                @endforeach
            </x-kit.select>
        </div>
    </div>
    <div wire:key="{{ $prefix }}-unit" class="contents">
        <div class="space-y-2" wire:key="{{ $prefix }}-unit-for-{{ $department }}">
            <flux:label>Unit</flux:label>
            @if ($chosenNothing)
                <x-kit.select wire:model.live="form.unit_id" aria-label="Unit">
                    <flux:select.option value="">Choose a department first</flux:select.option>
                </x-kit.select>
            @elseif ($notPlaced)
                {{-- A Not placed position has no unit to narrow by. --}}
                <x-kit.select wire:model.live="form.unit_id" aria-label="Unit" disabled>
                    <flux:select.option value="">—</flux:select.option>
                </x-kit.select>
            @else
                <x-kit.select wire:model.live="form.unit_id" aria-label="Unit">
                    <flux:select.option value="">No unit — in the department itself</flux:select.option>
                    @foreach ($choices['units'] as $option)
                        <flux:select.option value="{{ $option['id'] }}">{{ $option['name'] }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            @endif
        </div>
    </div>
    <div wire:key="{{ $prefix }}-position" class="contents">
        <div class="space-y-2" wire:key="{{ $prefix }}-position-for-{{ $department }}-{{ $choices['unit'] }}">
            <flux:label>
                Position
                @if ($required)
                    <span aria-hidden="true" class="ml-0.5 text-danger">*</span>
                    <span class="sr-only">(required)</span>
                @endif
            </flux:label>
            <x-kit.select
                wire:model.live="form.{{ EmployeePosition::FORM_KEY }}"
                aria-label="Position"
                :invalid="(bool) $error"
            >
                <flux:select.option value="" disabled hidden>Not set</flux:select.option>
                @if ($keep !== '')
                    <flux:select.option value="{{ EmployeePosition::KEEP }}">{{ $keep }} (not on the Position list)</flux:select.option>
                @endif
                @foreach ($choices['positions'] as $option)
                    <flux:select.option value="{{ $option['id'] }}">{{ $option['name'] }}</flux:select.option>
                @endforeach
            </x-kit.select>
            <p class="text-xs text-muted-foreground">{{ $chosenNothing ? 'Choose a department to pick a position from the list.' : 'Only the positions of the chosen department or unit are listed.' }}</p>
            @if ($error)
                <p class="text-xs text-danger">{{ $error }}</p>
            @endif
        </div>
    </div>
@endif
