{{-- The brand, in the one of its three forms the place has room for — all cut from the
     same lockup in public/, so they never drift:
       full      the B mark, the wordmark and the tagline. The sign-in and landing pages,
                 where the brand is the subject and the tagline is legible.
       wordmark  the B mark and the wordmark, no tagline. The printed payslip and report
                 headers, where the lockup would be squeezed under ~220px and the tagline
                 would be unreadable.
       mark      the B alone, square. The sidebar — alone in the collapsed rail, beside the
                 name set in type while it is open — and anything under ~120px; the
                 favicons are its small copies. --}}
{{-- `display` is the img's display utilities (a block by default); the collapsed rail passes
     "hidden sidebar-rail:block", which `block` in the merged class list would fight. --}}
@props(['variant' => 'full', 'display' => 'block'])
@php
    [$file, $alt, $width, $height] = match ($variant) {
        'wordmark' => ['bestworkflows-wordmark.png', 'BestWorkflows', 2061, 411],
        'mark' => ['bestworkflows-mark.png', 'BestWorkflows', 448, 448],
        default => ['bestworkflows-horizontal-view.png', 'BestWorkflows — Local Compliance, Global Workflows', 2172, 724],
    };
@endphp
<img
    src="{{ asset($file) }}"
    alt="{{ $alt }}"
    width="{{ $width }}"
    height="{{ $height }}"
    {{ $attributes->class([$display, 'h-auto max-w-full rounded-md object-contain']) }}
/>
