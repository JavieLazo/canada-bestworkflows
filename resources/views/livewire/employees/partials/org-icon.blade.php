{{--
    Inline Lucide icons (lucide.dev, ISC) for the Organization view: the
    department building, the unit's people (the sidebar's `users` paths), the
    card's chevron and the header's Expand all / Collapse all / Close. Inlined
    because Flux ships Heroicons and the shell's look is Lucide.

    @include('livewire.employees.partials.org-icon', ['icon' => 'building-2', 'class' => 'size-3.5 shrink-0'])
--}}
@php($__stroke = 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"')
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" {!! $__stroke !!} class="{{ $class ?? 'size-4' }}" aria-hidden="true">
@switch($icon)
    @case('building-2')
        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>
        @break
    @case('users')
        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>
        @break
    @case('chevron-down')
        <path d="m6 9 6 6 6-6"/>
        @break
    @case('chevrons-up-down')
        <path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>
        @break
    @case('chevrons-down-up')
        <path d="m7 22 5-5 5 5"/><path d="m7 2 5 5 5-5"/>
        @break
    @case('x')
        <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
        @break
@endswitch
</svg>
