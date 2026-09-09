{{--
    Port of src/components/RowActions.tsx — the three-dot menu every settings
    table carries.

    The dropdown body is a slot of <flux:menu.item> rows, so a screen with more
    than the standard pair keeps the same trigger. For the standard settings
    pair, pass nothing extra and wire the two items yourself:

        <x-kit.row-actions :label="$row->name">
            <flux:menu.item icon="pencil" wire:click="startEdit('{{ $row->id }}')">
                Edit
            </flux:menu.item>
            <flux:menu.item
                icon="trash"
                :variant="$usedBy > 0 ? 'default' : 'danger'"
                :disabled="$usedBy > 0"
                wire:click="confirmDelete('{{ $row->id }}')"
            >
                {{ $usedBy > 0 ? "Delete · in use by {$usedBy}" : 'Delete' }}
            </flux:menu.item>
        </x-kit.row-actions>

    That delete row reproduces RowActions.tsx:42-49: blocked (and not painted
    red) while `usedBy` records still carry the option, with the count spelled
    out in the label.
--}}
@props([
    /** Names the row for screen readers — "Actions for Driver". */
    'label',
])
<flux:dropdown position="bottom" align="end">
    <flux:button
        variant="ghost"
        size="sm"
        square
        icon="ellipsis-vertical"
        aria-label="Actions for {{ $label }}"
    />

    <flux:menu>
        {{ $slot }}
    </flux:menu>
</flux:dropdown>
