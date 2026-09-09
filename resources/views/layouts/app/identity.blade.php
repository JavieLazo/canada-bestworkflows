{{--
    Portrait, name and role — the port of Identity/Portrait (AppSidebar.tsx:63-105).
    Portrait falls back to initials, then an icon.

    Expects: $photoUrl (?string), $initials (string), $fullName (string),
             $roleName (string), $hideCollapsed (string).
--}}
<span class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-sidebar-accent text-xs font-semibold text-sidebar-accent-foreground">
    @if ($photoUrl !== null)
        <img src="{{ $photoUrl }}" alt="" class="size-8 object-cover" />
    @elseif ($initials !== '')
        {{ $initials }}
    @else
        @include('layouts.app.module-icon', ['icon' => 'circle-user-round', 'class' => 'size-5'])
    @endif
</span>
<span class="grid min-w-0 flex-1 text-left leading-tight {{ $hideCollapsed }}">
    <span class="truncate text-sm font-semibold">{{ $fullName }}</span>
    <span class="num truncate text-xs text-sidebar-foreground/70">{{ $roleName }}</span>
</span>
