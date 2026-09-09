{{--
    Inline Lucide icons (lucide.dev, ISC) — the exact set the PROJECTS screens
    use (projects-page.tsx, project-detail.tsx and the three dialogs import
    them from lucide-react). Inlined for the same reason the shell inlines its
    module icons: Flux ships Heroicons and the reference look is Lucide.

    @include('livewire.projects.partials.icon', ['icon' => 'clock-3', 'class' => 'size-4'])
--}}
@php($__stroke = 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"')
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" {!! $__stroke !!} class="{{ $class ?? 'size-4' }}" aria-hidden="true">
@switch($icon)
    @case('clock-3')
        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16.5 12"/>
        @break
    @case('clipboard-check')
        <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>
        @break
    @case('alert-triangle')
        <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>
        @break
    @case('user-round-x')
        <path d="M2 21a8 8 0 0 1 11.873-7"/><circle cx="10" cy="8" r="5"/><path d="m17 17 5 5"/><path d="m22 17-5 5"/>
        @break
    @case('download')
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>
        @break
    @case('plus')
        <path d="M5 12h14"/><path d="M12 5v14"/>
        @break
    @case('arrow-left')
        <path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>
        @break
    @case('edit-3')
        <path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/>
        @break
    @case('message-square')
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        @break
    @case('paperclip')
        <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
        @break
    @case('trash-2')
        <path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>
        @break
    @case('ban')
        <circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>
        @break
    @case('check')
        <path d="M20 6 9 17l-5-5"/>
        @break
    @case('undo-2')
        <path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/>
        @break
    @case('save')
        <path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>
        @break
    @case('send')
        <path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>
        @break
@endswitch
</svg>
