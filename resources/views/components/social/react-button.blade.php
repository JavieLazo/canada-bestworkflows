{{--
    The React button posts and comments share. A click leaves a Like, or takes
    back whatever you already picked. Resting on it for a moment offers all six.

    The six grow out of the button rather than popping up: the bar fades in
    while rising and scaling up a little from its corner by the button, and
    the emoji follow 30ms apart. With reduced motion on, it only fades.

    `method` is the Livewire action to call with the target's id and the
    reaction type. `variant` is "bar" for a post's action bar, or "link" for
    the small text button under a comment.

    Usage:
        <x-social.react-button method="react" :target="$post->id" :mine="$post->mine" />
        <x-social.react-button method="reactToComment" :target="$comment->id" :mine="$comment->reaction" variant="link" />
--}}
@use('App\Support\SocialFormat')
@props([
    'method',
    'target',
    'mine' => null,
    'variant' => 'bar',
])
@php
    $picked = $mine !== null ? (SocialFormat::REACTIONS[$mine] ?? null) : null;
    $bar = $variant === 'bar';
@endphp

<div
    @class(['relative', 'inline-flex' => ! $bar])
    x-data="{ pick: false, timer: null }"
    x-on:mouseenter="clearTimeout(timer); timer = setTimeout(() => pick = true, 350)"
    x-on:mouseleave="clearTimeout(timer); timer = setTimeout(() => pick = false, 250)"
>
    <button
        type="button"
        wire:click="{{ $method }}('{{ $target }}', '{{ $mine ?? 'LIKE' }}')"
        x-on:click="clearTimeout(timer); pick = false"
        aria-pressed="{{ $picked !== null ? 'true' : 'false' }}"
        @class([
            'flex h-9 w-full cursor-pointer items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground' => $bar,
            'inline-flex cursor-pointer items-center gap-1 font-semibold hover:underline' => ! $bar,
            'text-primary' => $picked !== null,
            'text-muted-foreground' => $picked === null && $bar,
        ])
    >
        @if ($picked !== null)
            <span @class(['leading-none', 'text-base' => $bar]) aria-hidden="true">{{ $picked['emoji'] }}</span>
            {{ $picked['label'] }}
        @else
            @if ($bar)
                <flux:icon.hand-thumb-up class="size-4" />
            @endif
            Like
        @endif
    </button>

    <div
        x-show="pick"
        x-cloak
        x-transition:enter="transition-[opacity,translate,scale] duration-200 ease-[cubic-bezier(0.23,1,0.32,1)]"
        x-transition:enter-start="translate-y-1 scale-95 opacity-0 motion-reduce:translate-y-0 motion-reduce:scale-100"
        x-transition:enter-end="translate-y-0 scale-100 opacity-100"
        x-transition:leave="transition-[opacity,translate,scale] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]"
        x-transition:leave-start="translate-y-0 scale-100 opacity-100"
        x-transition:leave-end="translate-y-1 scale-95 opacity-0 motion-reduce:translate-y-0 motion-reduce:scale-100"
        class="absolute bottom-full left-0 z-20 mb-1 flex origin-bottom-left gap-0.5 rounded-full border border-border bg-popover p-1 shadow-lift"
        role="group"
        aria-label="Choose a reaction"
    >
        @foreach (SocialFormat::REACTIONS as $type => $reaction)
            {{-- The wrapper carries the entrance and its delay, so the hover lift on the button never waits for it. --}}
            <span
                class="flex transition-[opacity,translate] duration-150 ease-[cubic-bezier(0.23,1,0.32,1)] starting:translate-y-1.5 starting:opacity-0 motion-reduce:starting:translate-y-0"
                style="transition-delay: {{ $loop->index * 30 }}ms"
            >
                <button
                    type="button"
                    wire:click="{{ $method }}('{{ $target }}', '{{ $type }}')"
                    x-on:click="pick = false"
                    title="{{ $reaction['label'] }}"
                    aria-label="{{ $reaction['label'] }}"
                    @class([
                        'flex cursor-pointer items-center justify-center rounded-full transition-transform duration-150 hover:-translate-y-0.5 hover:scale-125',
                        'size-9 text-xl' => $bar,
                        'size-8 text-lg' => ! $bar,
                        'bg-primary-soft' => $mine === $type,
                    ])
                >{{ $reaction['emoji'] }}</button>
            </span>
        @endforeach
    </div>
</div>
