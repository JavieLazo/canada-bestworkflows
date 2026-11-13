{{-- The Reports home: a few graphs, then every report by name in one frame. Nothing here is read
     twice — each graph is its owner's own report drawn small, and each name opens that report's page.
     The search only hides names in the browser; it asks the server nothing. --}}
<div class="space-y-6" x-data="{ q: '' }">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <h1 class="text-2xl font-bold tracking-tight">Reports</h1>
        <div class="w-full sm:w-72">
            <flux:input type="search" icon="magnifying-glass" placeholder="Search reports" aria-label="Search reports" x-model="q" />
        </div>
    </div>

    @if ($charts !== [])
        <div class="grid items-stretch gap-4 lg:grid-cols-3" aria-label="Report graphs" x-show="q === ''">
            @foreach ($charts as $chart)
                @php
                    $peak = max([1.0, ...array_column($chart['points'], 'value')]);
                @endphp
                <figure class="hris-card flex min-w-0 flex-col gap-3 px-5 py-4" wire:key="home-chart-{{ $loop->index }}">
                    <figcaption>
                        <a href="{{ $chart['href'] }}" wire:navigate class="text-sm font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-primary">{{ $chart['title'] }}</a>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ $chart['unit'] }}</p>
                    </figcaption>
                    @if ($chart['points'] === [])
                        <p class="text-sm text-muted-foreground">Nothing to show yet.</p>
                    @elseif ($chart['kind'] === 'columns')
                        @php
                            // A column never fills its slot: 20px at most, the rest of the band is air.
                            $slot = 294 / count($chart['points']);
                            $width = min(20, $slot * 0.5);
                            $last = array_key_last($chart['points']);
                        @endphp
                        <svg viewBox="0 0 340 232" class="hris-chart mt-auto w-full" role="img" aria-label="{{ $chart['title'] }}. {{ collect($chart['points'])->map(fn ($point) => $point['label'].': '.$point['display'])->implode(', ') }}.">
                            @foreach ([0.5, 1] as $step)
                                <line class="hris-chart-grid" x1="36" x2="330" y1="{{ 206 - $step * 160 }}" y2="{{ 206 - $step * 160 }}" />
                                <text class="hris-chart-tick" text-anchor="end" x="30" y="{{ 210 - $step * 160 }}">{{ \App\Livewire\Reports\Support\HomeCharts::compact($peak * $step) }}</text>
                            @endforeach
                            <text class="hris-chart-tick" text-anchor="end" x="30" y="210">0</text>
                            @foreach ($chart['points'] as $index => $point)
                                @php
                                    $center = 36 + ($index + 0.5) * $slot;
                                    $top = 206 - $point['value'] / $peak * 160;
                                    $left = $center - $width / 2;
                                    $right = $center + $width / 2;
                                @endphp
                                <rect class="hris-chart-hit" x="{{ $center - $slot / 2 }}" y="30" width="{{ $slot }}" height="176"><title>{{ $point['label'] }}: {{ $point['display'] }}</title></rect>
                                <path class="hris-chart-bar" d="M{{ $left }},206 V{{ min(206, $top + 4) }} Q{{ $left }},{{ $top }} {{ $left + 4 }},{{ $top }} H{{ $right - 4 }} Q{{ $right }},{{ $top }} {{ $right }},{{ min(206, $top + 4) }} V206 Z" />
                                <text class="hris-chart-tick" text-anchor="middle" x="{{ $center }}" y="224">{{ $point['label'] }}</text>
                                @if ($index === $last)
                                    <text class="hris-chart-value" text-anchor="middle" x="{{ $center }}" y="{{ max(12, $top - 7) }}">{{ $point['display'] }}</text>
                                @endif
                            @endforeach
                            <line class="hris-chart-axis" x1="36" x2="330" y1="206" y2="206" />
                        </svg>
                    @else
                        <ol class="space-y-3" aria-label="{{ $chart['title'] }} values">
                            @foreach ($chart['points'] as $point)
                                <li title="{{ $point['label'] }}: {{ $point['display'] }}">
                                    <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                                        <span class="min-w-0 truncate">{{ $point['label'] }}</span>
                                        <span class="num shrink-0 text-xs font-semibold">{{ $point['display'] }}</span>
                                    </div>
                                    <div class="h-2" aria-hidden="true"><div class="hris-chart-fill h-2" style="width: {{ max(1, min(100, $point['value'] / $peak * 100)) }}%"></div></div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </figure>
            @endforeach
        </div>
    @endif

    <section class="hris-card overflow-hidden" aria-label="All reports">
        <div class="grid md:grid-cols-3 md:divide-x md:divide-border">
            @foreach ($columns as $groups)
                <div class="min-w-0">
                    @foreach ($groups as $group)
                        <div x-show="q === '' || @js(array_map(fn ($link) => strtolower($link['label']), $group['links'])).some((name) => name.includes(q.toLowerCase()))">
                            <h2 class="border-b border-border bg-muted px-5 py-2.5 text-[0.7rem] font-semibold uppercase tracking-wider text-muted-foreground">{{ $group['heading'] }}</h2>
                            @foreach ($group['links'] as $link)
                                @if ($link['allowed'])
                                    <a href="{{ route('reports.show', array_filter(['report' => $link['type'], 'view' => $link['view']])) }}" wire:navigate
                                        x-show="q === '' || @js(strtolower($link['label'])).includes(q.toLowerCase())"
                                        class="flex items-center gap-2 border-b border-border px-5 py-3 text-sm font-medium transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none">
                                        <span class="min-w-0 flex-1">{{ $link['label'] }}</span>
                                        <flux:icon.chevron-right variant="micro" class="shrink-0 text-muted-foreground" />
                                    </a>
                                @else
                                    <p x-show="q === '' || @js(strtolower($link['label'])).includes(q.toLowerCase())"
                                        class="flex flex-wrap items-center gap-2 border-b border-border px-5 py-3 text-sm font-medium text-muted-foreground">
                                        <span>{{ $link['label'] }}</span>
                                        <span class="pill pill-muted">{{ $link['needs'] }}</span>
                                    </p>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </section>
</div>
