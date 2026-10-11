{{--
    The photos on a post or a comment, laid out the same way on both. One
    photo is shown whole. Two or more make a grid of up to four, and the
    fourth carries a "+N" for the rest. Every photo is still one click away in
    the viewer, arrows and all.

    `compact` is the comment size: at most 20rem wide, and one photo keeps
    its own shape instead of stretching across.

    Usage:
        <x-social.photo-grid :urls="$photoUrls" :key="'post-'.$post->id.'-img'" class="mt-3" />
        <x-social.photo-grid :urls="$photoUrls" compact class="mt-1.5" />
--}}
@props([
    'urls' => [],
    'compact' => false,
    'key' => null,
])
@php
    $count = count($urls);
    $single = $count === 1;
@endphp

@if ($count > 0)
    <div {{ $attributes->class([
        'grid gap-0.5',
        'grid-cols-2' => ! $single,
        'bg-border' => ! $compact || ! $single,
        'max-w-xs overflow-hidden rounded-xl' => $compact,
        'w-fit' => $compact && $single,
    ]) }}>
        @foreach (array_slice($urls, 0, 4) as $k => $src)
            <button
                type="button"
                @if ($key !== null) wire:key="{{ $key }}-{{ $k }}" @endif
                x-on:click="$dispatch('lightbox', { images: @js($urls), index: {{ $k }} })"
                @class([
                    'relative block cursor-pointer overflow-hidden bg-muted',
                    'row-span-2' => $count === 3 && $k === 0,
                ])
                aria-label="Open photo {{ $k + 1 }} of {{ $count }}"
            >
                <img
                    src="{{ $src }}"
                    alt=""
                    loading="lazy"
                    @class([
                        'w-full max-h-[32rem] object-contain' => $single && ! $compact,
                        'max-h-60 w-auto max-w-full object-cover' => $single && $compact,
                        'aspect-square h-full w-full object-cover' => ! $single,
                    ])
                />
                @if ($k === 3 && $count > 4)
                    <span @class([
                        'absolute inset-0 flex items-center justify-center bg-black/50 font-semibold text-white',
                        'text-2xl' => ! $compact,
                        'text-lg' => $compact,
                    ])>+{{ $count - 4 }}</span>
                @endif
            </button>
        @endforeach
    </div>
@endif
