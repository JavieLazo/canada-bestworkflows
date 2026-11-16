@if ($overviewClients->isNotEmpty())
    <section class="ppm-surface ppm-chart-card" aria-label="Client delivery">
        <header>
            <h3 class="ppm-section-title">Client delivery</h3>
            <a href="{{ route('projects.clients', ['portfolio' => $portfolio]) }}" class="ppm-card-link" wire:navigate>All clients <span aria-hidden="true">↗</span></a>
        </header>
        <ul class="ppm-dashboard-list divide-y divide-border/60">
            @foreach ($overviewClients->take(4) as $deliveryClient)
                @php
                    $clientWork = $overviewClientTotals[$deliveryClient->id];
                    $projectCount = $overviewClientCounts[$deliveryClient->id] ?? 0;
                @endphp
                <li wire:key="delivery-client-{{ $deliveryClient->id }}">
                    <a href="{{ route('projects.index', ['portfolio' => $portfolio, 'client' => $deliveryClient->id, 'section' => 'overview']) }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3 hover:bg-accent focus-visible:outline-2 focus-visible:outline-primary">
                        <div class="min-w-0 flex-1 basis-36"><h4 class="truncate text-sm font-medium" title="{{ $deliveryClient->name }}">{{ $deliveryClient->name }}</h4><span class="num text-xs text-muted-foreground">{{ $projectCount }} {{ $projectCount === 1 ? 'project' : 'projects' }}</span></div>
                        <x-work.progress :progress="$clientWork->progress()" class="w-40 shrink-0" />
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
