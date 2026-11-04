{{--
    The full-screen photo viewer Community and Chat share. The behaviour lives
    in resources/js/photo-viewer.js; this is only its markup.

    Opened by a window event, so any thumbnail anywhere can open it without a
    component per photo:

        x-on:click="$dispatch('lightbox', { images: @js($urls), index: 0 })"

    Photos that aren't saved yet (still waiting in a post box) pass
    `download: false`, since there's nothing to download yet.

    Across the top: which photo and the zoom, then zoom out, zoom in, back to
    100%, download, and close. Livewire is told to leave it alone, since its
    only state is Alpine's. It's a <dialog> opened as a modal, so it lands on
    top of any dialog it's opened from and stays clickable there.

    Every button lightens on hover, sinks a touch when pressed, and shows a
    ring when reached by keyboard. The arrows' chevrons also lean the way
    they go. Hover only applies where there's a mouse (Tailwind's hover:), so
    a tap on a phone never leaves one stuck lit.
--}}
@php
    $press = 'transition-[background-color,scale] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)] active:scale-[0.97] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';
    $control = 'inline-flex size-10 cursor-pointer items-center justify-center rounded-full text-white hover:bg-white/15 active:bg-white/25 disabled:cursor-default disabled:opacity-40 disabled:hover:bg-transparent disabled:active:scale-100 '.$press;
    $arrow = 'group absolute top-1/2 inline-flex size-11 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full bg-white/10 text-white ring-1 ring-white/15 backdrop-blur-sm hover:bg-white/20 active:bg-white/30 '.$press;
    $chevron = 'size-6 transition-transform duration-150 ease-[cubic-bezier(0.23,1,0.32,1)] motion-reduce:transition-none';
@endphp

<div
    wire:ignore
    x-data="photoViewer"
    x-on:lightbox.window="show($event.detail)"
    x-on:keydown.window="key($event)"
>
    <dialog
        x-ref="layer"
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        x-on:cancel.prevent="close()"
        class="fixed inset-0 z-50 m-0 flex size-full max-h-none max-w-none flex-col overflow-hidden border-0 bg-black/95 p-0 text-white backdrop:bg-transparent"
        aria-label="Photo viewer"
    >
        <div class="flex shrink-0 items-center gap-1 px-3 py-2 sm:px-4">
            <p class="num min-w-0 flex-1 truncate px-1 text-sm text-white/80" aria-live="polite">
                <span x-show="images.length > 1" x-text="(index + 1) + ' of ' + images.length + ' · '"></span><span x-text="percent + '%'"></span>
            </p>
            <button type="button" x-on:click="zoomBy(-0.25)" x-bind:disabled="scale <= 1" class="{{ $control }}" aria-label="Zoom out" title="Zoom out (−)">
                <flux:icon.magnifying-glass-minus class="size-5" />
            </button>
            <button type="button" x-on:click="zoomBy(0.25)" x-bind:disabled="scale >= 6" class="{{ $control }}" aria-label="Zoom in" title="Zoom in (+)">
                <flux:icon.magnifying-glass-plus class="size-5" />
            </button>
            <button type="button" x-on:click="reset()" x-bind:disabled="scale === 1" class="{{ $control }}" aria-label="Back to 100%" title="Back to 100% (0)">
                <flux:icon.arrows-pointing-in class="size-5" />
            </button>
            <a x-show="canDownload" x-bind:href="download" download class="{{ $control }}" aria-label="Download" title="Download">
                <flux:icon.arrow-down-tray class="size-5" />
            </a>
            <button type="button" x-ref="close" x-on:click="close()" class="{{ $control }}" aria-label="Close" title="Close (Esc)">
                <flux:icon.x-mark class="size-5" />
            </button>
        </div>

        {{-- touch-none: two fingers zoom the photo here, not the whole page. --}}
        <div
            x-ref="stage"
            x-on:wheel.prevent="wheel($event)"
            x-on:click.self="if (! moved) close()"
            class="relative flex min-h-0 flex-1 touch-none select-none items-center justify-center overflow-hidden px-4 pb-4 sm:px-16"
        >
            <img
                x-ref="photo"
                x-bind:src="images[index]"
                alt=""
                draggable="false"
                x-on:pointerdown="down($event)"
                x-on:pointermove="move($event)"
                x-on:pointerup="up($event)"
                x-on:pointercancel="up($event)"
                x-on:dblclick.prevent="toggleAt($event.clientX, $event.clientY)"
                x-bind:style="'transform: ' + transform"
                x-bind:class="{
                    'cursor-zoom-in': scale === 1,
                    'cursor-grab': scale > 1 && gesture === null,
                    'cursor-grabbing': scale > 1 && gesture !== null,
                    'transition-transform duration-150 ease-out': gesture === null,
                }"
                class="max-h-full max-w-full rounded-lg object-contain shadow-lift will-change-transform"
            />

            <template x-if="images.length > 1">
                <div>
                    <button
                        type="button"
                        x-on:click="step(-1)"
                        class="{{ $arrow }} left-2 sm:left-4"
                        aria-label="Previous photo"
                        title="Previous (←)"
                    >
                        <flux:icon.chevron-left class="{{ $chevron }} group-hover:-translate-x-0.5" />
                    </button>
                    <button
                        type="button"
                        x-on:click="step(1)"
                        class="{{ $arrow }} right-2 sm:right-4"
                        aria-label="Next photo"
                        title="Next (→)"
                    >
                        <flux:icon.chevron-right class="{{ $chevron }} group-hover:translate-x-0.5" />
                    </button>
                </div>
            </template>
        </div>

        <div x-show="images.length > 1" class="flex shrink-0 justify-center px-4 pb-4">
            <div x-ref="strip" class="flex max-w-full gap-2 overflow-x-auto p-1">
                <template x-for="(src, i) in images" x-bind:key="i">
                    <button
                        type="button"
                        x-on:click="go(i)"
                        class="size-14 shrink-0 cursor-pointer overflow-hidden rounded-md ring-2 transition"
                        x-bind:class="i === index ? 'ring-white opacity-100' : 'ring-transparent opacity-50 hover:opacity-100'"
                        x-bind:aria-label="'Photo ' + (i + 1) + ' of ' + images.length"
                        x-bind:aria-current="i === index"
                    >
                        <img x-bind:src="src" alt="" loading="lazy" draggable="false" class="size-full object-cover" />
                    </button>
                </template>
            </div>
        </div>
    </dialog>
</div>
