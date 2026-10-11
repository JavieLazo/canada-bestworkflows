{{--
    A person's (or a group's) round avatar: the portrait when there is one,
    otherwise their initials (or an icon, for a group) on one of four tints.

    Initials follow the sidebar's rule: first and last word, not the first
    two, so a second given name never stands in for the surname. The tint is
    picked from the name, so the same person wears the same colour on every
    card and every chat row.

    Kept in components/social with the other Community and Chat pieces, not
    in the kit, since KitTest pins exactly what the kit holds.

    Usage:
        <x-social.avatar :name="$person->name" :photo="$person->photo" size="md" />
        <x-social.avatar :name="$group->name" icon="user-group" size="sm" />
--}}
@props([
    'name',
    'photo' => null,
    'icon' => null,
    'size' => 'md',
])
@php
    $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
    $initials = mb_strtoupper(
        mb_substr($words[0] ?? '', 0, 1)
        .(count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '')
    );

    $tints = [
        'bg-primary-soft text-primary',
        'bg-success-soft text-success',
        'bg-danger-soft text-danger',
        'bg-secondary text-secondary-foreground',
    ];

    $sizes = [
        'xs' => ['box' => 'size-6 text-[0.6rem]', 'icon' => 'size-3.5'],
        'sm' => ['box' => 'size-8 text-xs', 'icon' => 'size-4'],
        'md' => ['box' => 'size-10 text-sm', 'icon' => 'size-5'],
        'lg' => ['box' => 'size-16 text-lg', 'icon' => 'size-7'],
    ];
    $sized = $sizes[$size] ?? $sizes['md'];
@endphp
<span
    {{ $attributes->class([
        'inline-flex shrink-0 select-none items-center justify-center overflow-hidden rounded-full font-semibold',
        $sized['box'],
        $photo ? 'bg-muted' : $tints[crc32($name) % count($tints)],
    ]) }}
    aria-hidden="true"
>
    @if ($photo)
        <img src="{{ $photo }}" alt="" class="size-full object-cover" />
    @elseif ($icon)
        <flux:icon :name="$icon" variant="outline" class="{{ $sized['icon'] }}" />
    @else
        {{ $initials }}
    @endif
</span>
