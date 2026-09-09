{{--
    Port of src/components/SortHeader.tsx — a sortable column header.

    Renders the <th> itself so it can carry `aria-sort`, which is what screen
    readers announce. Must sit inside a Livewire component that `use`s
    App\Livewire\Concerns\WithSorting: the caret and aria-sort read
    `$wire.sortKey` / `$wire.sortDir` reactively, and clicking calls
    `$wire.sortBy(field)`.

    Usage:
        <x-kit.sort-header field="name" label="Employee" />
        <x-kit.sort-header field="amount" align="right">Amount</x-kit.sort-header>
--}}
@props([
    'field',
    'label' => null,
    'align' => 'left',
])
<th
    aria-sort="none"
    x-bind:aria-sort="$wire.sortKey === @js($field) ? ($wire.sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
    {{ $attributes }}
>
    <button
        type="button"
        x-on:click="$wire.sortBy(@js($field))"
        {{-- SortHeader.tsx:37-41 --}}
        class="group inline-flex cursor-pointer items-center gap-1 font-[inherit] uppercase tracking-[inherit] transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring{{ $align === 'right' ? ' flex-row-reverse' : '' }}"
        x-bind:class="$wire.sortKey === @js($field) && 'text-foreground'"
    >
        {{ $label !== null ? $label : $slot }}
        {{-- SortIcon (SortHeader.tsx:50-57): faint both-ways chevron on hover while
             inactive; a solid arrow for the active direction. --}}
        <flux:icon.chevron-up-down
            x-show="$wire.sortKey !== @js($field)"
            class="size-3 opacity-0 transition-opacity group-hover:opacity-50"
        />
        <flux:icon.arrow-up
            style="display: none"
            x-show="$wire.sortKey === @js($field) && $wire.sortDir === 'asc'"
            class="size-3"
        />
        <flux:icon.arrow-down
            style="display: none"
            x-show="$wire.sortKey === @js($field) && $wire.sortDir === 'desc'"
            class="size-3"
        />
    </button>
</th>
