{{--
    Port of src/components/FilterSelect.tsx — a select used as a filter: while
    it sits on its "all" value it looks like any other select, and once
    something is picked the chevron gives way to a clear button that puts it
    back.

    The clear button is a sibling laid over the select rather than a child of
    it, exactly as the source did it (a button cannot contain another button).
    The whole thing binds through `$wire.entangle`, so the cleared value flows
    back into the Livewire property the caller named — pass the binding as
    `wire:model` (add `.live` to refresh the list on change, which is what
    every reference screen wants).

    Usage:
        <x-kit.filter-select
            label="Status filter"
            wire:model.live="status"
            :options="['ALL' => 'All statuses', 'ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']"
        />

        or with a slot of <option> tags instead of :options.
--}}
@props([
    /** Accessible name, also used for the clear button ("Clear status filter"). */
    'label',
    /** value => label. Include the "all" row yourself, exactly like the source's children. */
    'options' => null,
    'allValue' => 'ALL',
])
@php
    $model = $attributes->wire('model');

    if (! $model?->directive) {
        throw new \InvalidArgumentException('<x-kit.filter-select> needs a wire:model binding.');
    }

    $entangle = '$wire.entangle('.\Illuminate\Support\Js::from($model->value()).')'.($model->hasModifier('live') ? '.live' : '');
@endphp
<div
    {{ $attributes->whereDoesntStartWith('wire:model')->class('relative') }}
    x-data="{ v: {!! $entangle !!} }"
>
    {{-- `bg-none!` drops the native-select chevron Flux paints as a background
         image — the port of `hideIcon` (FilterSelect.tsx:39). --}}
    <flux:select
        x-model="v"
        aria-label="{{ $label }}"
        x-bind:class="v !== @js($allValue) && 'bg-none! font-medium'"
    >
        @if ($options !== null)
            @foreach ($options as $optionValue => $optionLabel)
                <flux:select.option value="{{ $optionValue }}">{{ $optionLabel }}</flux:select.option>
            @endforeach
        @else
            {{ $slot }}
        @endif
    </flux:select>

    <button
        type="button"
        style="display: none"
        x-show="v !== @js($allValue)"
        x-on:click="v = @js($allValue)"
        aria-label="Clear {{ strtolower($label) }}"
        class="absolute right-2 top-1/2 z-10 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
    >
        <flux:icon.x-mark class="size-4" />
    </button>
</div>
