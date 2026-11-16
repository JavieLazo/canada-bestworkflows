@props(['id', 'label'])
<div x-data="workPopover" x-on:resize.window="if (open) place()" x-on:scroll.window.capture="if (open) place()" x-on:task-property-saved.window="if (open && $refs.panel.id.startsWith('task-' + $event.detail.taskId + '-')) $refs.panel.hidePopover()" class="contents">
    {{ $trigger }}
    <div id="{{ $id }}" x-ref="panel" wire:ignore.self popover="auto" role="dialog" aria-label="{{ $label }}" {{ $attributes->class('ppm-filter-popover') }}
        x-on:beforetoggle.self="opening($event)" x-on:toggle.self="open = $event.newState === 'open'">
        {{ $slot }}
    </div>
</div>
