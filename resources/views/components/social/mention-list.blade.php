{{--
    The list the @mention picker opens (resources/js/mention-picker.js), under
    the box it serves or above it (`above`, for a box at the bottom of the
    screen like Chat's). Teleported to the body, so a card or a scrolling
    thread can't clip it.

    Usage, inside the picker's x-data:
        <x-social.mention-list />
        <x-social.mention-list above />
--}}
@props(['above' => false])

<template x-teleport="body">
    <div
        x-show="open"
        x-cloak
        x-anchor.{{ $above ? 'top' : 'bottom' }}-start.offset.6="field"
        x-transition.opacity.duration.100ms
        class="z-50 w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-border bg-popover p-1 text-popover-foreground shadow-lift"
        role="listbox"
        aria-label="Mention someone"
        x-on:mousedown.prevent
    >
        <template x-for="(item, i) in items" x-bind:key="item.key">
            <button
                type="button"
                role="option"
                x-bind:aria-selected="i === active"
                x-on:click="choose(item)"
                x-on:mouseenter="active = i"
                class="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-sm"
                x-bind:class="i === active ? 'bg-accent text-accent-foreground' : ''"
            >
                <span class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary-soft text-[0.65rem] font-semibold text-primary">
                    <template x-if="item.photo"><img x-bind:src="item.photo" alt="" class="size-full object-cover" /></template>
                    <template x-if="! item.photo"><span x-text="item.initials"></span></template>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium" x-text="(item.kind === 'everyone' ? '@' : '') + item.name"></span>
                    <span x-show="item.sub" class="block truncate text-xs text-muted-foreground" x-text="item.sub"></span>
                </span>
            </button>
        </template>
    </div>
</template>
