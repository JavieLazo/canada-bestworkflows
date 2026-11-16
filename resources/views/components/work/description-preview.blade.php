@props(['text', 'id' => null])
@php($descriptionKey = $id ?? 'work-description-'.md5($text))
<div wire:key="{{ $descriptionKey }}" class="max-w-3xl space-y-1" x-data="{ expanded: false, overflow: false, measure() { const text = this.$refs?.text; if (text && !this.expanded) this.overflow = text.scrollHeight > text.clientHeight + 1; } }"
    x-init="$nextTick(() => { measure(); document.fonts.ready.then(() => measure()); })" x-on:resize.window.debounce.150ms="measure()">
    <p id="{{ $descriptionKey }}" x-ref="text" class="line-clamp-2 whitespace-pre-line break-words text-sm leading-relaxed text-muted-foreground" x-bind:class="{ 'line-clamp-2': !expanded }">{{ $text }}</p>
    <button type="button" x-cloak x-show="overflow || expanded" x-on:click="expanded = !expanded; $nextTick(() => measure())" aria-expanded="false" x-bind:aria-expanded="expanded.toString()" aria-controls="{{ $descriptionKey }}" class="rounded text-xs font-medium text-muted-foreground hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" x-text="expanded ? 'Show less' : 'Read more'">Read more</button>
</div>
