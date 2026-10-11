{{--
    Port of src/components/FilterSelect.tsx — a select used as a filter: while
    it sits on its "all" value it looks like any other select, and once
    something is picked the chevron gives way to a clear button that puts it
    back, and the chosen value reads in medium weight.

    It is <x-kit.select> with `clearValue` set to the "all" value; that
    component draws the dropdown and the clear button and binds through its
    hidden native select. Pass the binding as `wire:model` (add `.live` to
    refresh the list on change, which is what every reference screen wants).

    Usage:
        <x-kit.filter-select
            label="Status filter"
            wire:model.live="status"
            :options="['ALL' => 'All statuses', 'ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']"
        />

        or with a slot of <flux:select.option> tags instead of :options.
--}}
@props([
    /** Accessible name, also used for the clear button ("Clear status filter"). */
    'label',
    /** value => label. Include the "all" row yourself, exactly like the source's children. */
    'options' => null,
    'allValue' => 'ALL',
])
@php
    if (! $attributes->wire('model')?->directive) {
        throw new \InvalidArgumentException('<x-kit.filter-select> needs a wire:model binding.');
    }
@endphp
<x-kit.select {{ $attributes }} aria-label="{{ $label }}" :clear-value="$allValue">
    @if ($options !== null)
        @foreach ($options as $optionValue => $optionLabel)
            <flux:select.option value="{{ $optionValue }}">{{ $optionLabel }}</flux:select.option>
        @endforeach
    @else
        {{ $slot }}
    @endif
</x-kit.select>
