@props(['active' => false, 'href' => null])
@php($classes = 'inline-flex items-center justify-center rounded-md px-3 py-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary '.($active ? 'bg-card text-foreground shadow-sm' : 'hover:text-foreground'))
@if ($href !== null)
    <a href="{{ $href }}" @if ($active) aria-current="page" @endif {{ $attributes->class([$classes]) }}>{{ $slot }}</a>
@else
    <button type="button" aria-pressed="{{ $active ? 'true' : 'false' }}" {{ $attributes->class([$classes]) }}>{{ $slot }}</button>
@endif
