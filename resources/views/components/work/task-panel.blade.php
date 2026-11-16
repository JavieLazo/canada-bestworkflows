@props(['title', 'code' => null, 'creating' => false])
@php
    $panelKey = \Illuminate\Support\Str::slug($attributes->get('name') ?: ($attributes->wire('model')->value() ?? 'task'));
    $headingId = 'work-panel-'.$panelKey;
@endphp
<flux:modal
    variant="flyout"
    :dismissible="false"
    {{ $attributes->class('ppm-task-panel') }}
    x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', '{{ $headingId }}')"
>
    <div class="ppm-panel-layout">
        <header class="ppm-panel-header">
            <div class="flex min-w-0 items-center gap-2 text-xs text-muted-foreground pr-8">
                <span @class(['min-w-0', 'hidden sm:inline-flex' => ! $creating, 'inline-flex' => $creating])>{{ $meta ?? '' }}</span>
                @if ($creating)<flux:heading level="2" size="lg" :id="$headingId">{{ $title }}</flux:heading>
                @elseif ($code)<span class="hidden sm:inline" aria-hidden="true">/</span><span class="num max-w-48 shrink-0 truncate" title="{{ $code }}">{{ $code }}</span>
                @endif
                <span class="ml-auto">{{ $headingActions ?? '' }}</span>
            </div>
        </header>
        <div class="ppm-panel-body">
            @if (! $creating)
                <div class="ppm-panel-title" id="{{ $headingId }}">
                    @isset($heading){{ $heading }}
                    @else<flux:heading level="2" size="lg" class="break-words !text-xl !font-semibold !leading-snug !tracking-tight">{{ $title }}</flux:heading>
                    @endisset
                </div>
            @endif
            {{ $slot }}
        </div>
        @isset($footer)
            <footer class="ppm-panel-footer">{{ $footer }}</footer>
        @endisset
    </div>
</flux:modal>
