{{-- Numeric owner projections keep visual comparisons aligned with the supporting tables. --}}
<div class="grid items-start gap-4 lg:grid-cols-2 print:grid-cols-2" aria-label="Analysis charts">
    @foreach ($document->charts as $chart)
        @php
            $maximum = max([1, ...array_column($chart['points'], 'value')]);
        @endphp
        <figure class="hris-card min-w-0 p-5 break-inside-avoid">
            <figcaption class="text-sm font-semibold">{{ $chart['title'] }}</figcaption>
            <p class="mt-1 text-xs text-muted-foreground">{{ $chart['unit'] }} · scale starts at zero</p>
            @if (($chart['kind'] ?? 'bar') === 'line' && $chart['points'] !== [])
                @php
                    $plotPoints = [];
                    foreach ($chart['points'] as $index => $point) {
                        $plotPoints[] = (40 + $index / max(1, count($chart['points']) - 1) * 540).','.(170 - $point['value'] / $maximum * 145);
                    }
                @endphp
                <svg viewBox="0 0 620 200" class="mt-4 w-full text-primary" role="img" aria-label="{{ $chart['title'] }}. Values are listed below.">
                    <line x1="40" y1="25" x2="40" y2="170" class="stroke-border" />
                    <line x1="40" y1="170" x2="580" y2="170" class="stroke-border" />
                    <line x1="40" y1="25" x2="580" y2="25" class="stroke-border" stroke-dasharray="4 4" />
                    <text x="32" y="30" text-anchor="end" class="fill-muted-foreground text-xs">{{ number_format($maximum) }}</text>
                    <text x="32" y="173" text-anchor="end" class="fill-muted-foreground text-xs">0</text>
                    <polyline points="{{ implode(' ', $plotPoints) }}" fill="none" stroke="currentColor" stroke-width="2" />
                    @foreach ($plotPoints as $point)
                        @php
                            [$x, $y] = explode(',', $point);
                        @endphp
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="3" fill="currentColor" />
                    @endforeach
                    <text x="40" y="195" class="fill-muted-foreground text-xs">{{ $chart['points'][0]['label'] }}</text>
                    <text x="580" y="195" text-anchor="end" class="fill-muted-foreground text-xs">{{ $chart['points'][array_key_last($chart['points'])]['label'] }}</text>
                </svg>
                <details class="mt-3 print:hidden"><summary class="cursor-pointer text-xs font-medium">Exact Chart Values</summary><dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">@foreach ($chart['points'] as $point)<div class="flex justify-between gap-2"><dt>{{ $point['label'] }}</dt><dd class="num">{{ number_format($point['value'], fmod((float) $point['value'], 1.0) === 0.0 ? 0 : 1) }}</dd></div>@endforeach</dl></details>
            @else
            <ol class="mt-4 space-y-3" aria-label="{{ $chart['title'] }} values">
                @forelse ($chart['points'] as $point)
                    <li>
                        <div class="mb-1 flex items-start justify-between gap-3 text-xs"><span class="min-w-0 break-words">{{ $point['label'] }}</span><span class="num shrink-0 font-medium">{{ number_format($point['value'], fmod((float) $point['value'], 1.0) === 0.0 ? 0 : 1) }}</span></div>
                        <div class="h-2 overflow-hidden rounded-sm bg-muted" aria-hidden="true"><div class="h-full rounded-sm bg-primary" style="width: {{ max(0, min(100, $point['value'] / $maximum * 100)) }}%"></div></div>
                    </li>
                @empty
                    <li class="text-sm text-muted-foreground">No qualifying data for this chart.</li>
                @endforelse
            </ol>
            @endif
            <p class="mt-4 text-xs leading-relaxed text-muted-foreground">{{ $chart['note'] }}</p>
        </figure>
    @endforeach
</div>
