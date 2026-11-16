{{-- A short explanation on hover or focus that a click or tap keeps open. The card sits in the top layer, so no card's clipping cuts it off. --}}
@props(['text', 'label'])
@php($hintId = 'hint-'.substr(md5($label.$text), 0, 10))
<span x-data="overviewHint" class="ppm-hint" x-on:keydown.escape.window="close(true)" x-on:click.outside="close(true)">
    <button x-ref="trigger" type="button" {{ $attributes->class('ppm-hint-trigger') }} aria-label="{{ $label }}" aria-describedby="{{ $hintId }}" x-bind:aria-expanded="pinned.toString()"
        x-on:mouseenter="open()" x-on:mouseleave="close()" x-on:focus="open()" x-on:blur="close()" x-on:click="toggle()">{{ $slot }}</button>
    <span x-ref="hint" id="{{ $hintId }}" popover="manual" role="tooltip" class="ppm-hovercard ppm-hovercard-text">{{ $text }}</span>
</span>
