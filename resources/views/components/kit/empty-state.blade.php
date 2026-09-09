{{--
    The reference's empty-table pattern, made reusable.

    Inside a <tbody>, the source renders one full-width muted row — e.g.
    "No holiday matches those filters." (holidays-page.tsx:236-238,
    reference-list.tsx:246-250) — and outside tables a centered muted
    paragraph — "Nothing to catch up on." (notifications-page.tsx:95-99).
    Pass `colspan` to get the row form; leave it off for the block form.
    `icon` (a Flux icon name) and `hint` are optional dress-up for module
    screens that show a fuller empty state.

    Usage:
        <tr wire:key="empty"><x-kit.empty-state :colspan="7" title="No holiday matches those filters." /></tr>

        <x-kit.empty-state icon="inbox" title="Nothing to catch up on."
            hint="Notifications about your requests will land here." />
--}}
@props([
    'title',
    'hint' => null,
    'icon' => null,
    'colspan' => null,
])
@if ($colspan !== null)
    <td colspan="{{ $colspan }}" {{ $attributes->class('py-10 text-center text-muted-foreground') }}>
        @if ($icon)
            <flux:icon :name="$icon" class="mx-auto mb-3 size-8 text-muted-foreground/60" />
        @endif
        {{ $title }}
        @if ($hint)
            <span class="mt-1 block text-xs text-muted-foreground/80">{{ $hint }}</span>
        @endif
    </td>
@else
    <div {{ $attributes->class('px-5 py-12 text-center text-sm text-muted-foreground') }}>
        @if ($icon)
            <flux:icon :name="$icon" class="mx-auto mb-3 size-8 text-muted-foreground/60" />
        @endif
        <p>{{ $title }}</p>
        @if ($hint)
            <p class="mt-1 text-xs text-muted-foreground/80">{{ $hint }}</p>
        @endif
    </div>
@endif
