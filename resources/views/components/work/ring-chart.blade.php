@props(['items', 'value', 'label'])
@php
    $total = array_sum(array_column($items, 'value'));
    $offset = 0;
@endphp
<div {{ $attributes->class('flex flex-wrap items-center gap-6') }}>
    <div class="relative size-32 shrink-0">
        <svg viewBox="0 0 100 100" class="size-full -rotate-90" role="img" aria-label="{{ $label }} distribution">
            <circle cx="50" cy="50" r="40" fill="none" stroke-width="10" class="stroke-muted" />
            @foreach ($items as $item)
                @php($length = $total > 0 ? $item['value'] / $total * 251.327 : 0)
                @if ($length > 0)<a href="{{ $item['href'] }}" aria-label="{{ $item['label'] }}: {{ $item['value'] }}" wire:navigate><circle cx="50" cy="50" r="40" fill="none" stroke-width="10" stroke="{{ $item['color'] }}" stroke-dasharray="{{ max(0, $length - 1.5) }} 251.327" stroke-dashoffset="{{ -$offset }}" class="hover:opacity-70 focus:opacity-70" /></a>
                @endif
                @php($offset += $length)
            @endforeach
        </svg>
        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"><span class="num text-3xl font-semibold tracking-tight">{{ $value }}</span><span class="mt-1 text-xs text-muted-foreground">{{ $label }}</span></div>
    </div>
    <div class="ppm-chart-legend min-w-32 flex-1">
        @foreach ($items as $item)<a href="{{ $item['href'] }}" wire:navigate><span class="size-2 shrink-0 rounded-full" style="background: {{ $item['color'] }}" aria-hidden="true"></span><span class="flex-1 text-muted-foreground">{{ $item['label'] }}</span><span class="num font-medium">{{ $item['value'] }}</span><flux:icon.chevron-right class="size-3 text-muted-foreground" /></a>
        @endforeach
    </div>
</div>
