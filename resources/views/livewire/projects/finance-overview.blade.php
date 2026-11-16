<div class="contents">
    @foreach ($financialTotals as $financialRow)
        @php($sourceAction = "openSource('".$financialRow->currency."')")
        <section class="ppm-surface ppm-chart-card" aria-label="Visible project finance in {{ $financialRow->currency }}" wire:key="finance-overview-{{ $financialRow->currency }}">
            <header><h3 class="ppm-section-title">Finance</h3>
                @if ($projectId !== null)<a class="ppm-card-link" href="{{ route('projects.show', ['project' => $projectId, 'view' => 'finance']) }}" wire:navigate>Open finance <span aria-hidden="true">↗</span></a>
                @else<span class="text-xs text-muted-foreground">Projects with financial access</span>
                @endif
            </header>
            @if ($financialRow->budgetedProjects > 0 || $financialRow->receivedMinor > 0 || $financialRow->paidMinor > 0)
                <x-work.finance-totals :totals="$financialRow" :budget-action="$sourceAction" :receipt-action="$sourceAction" :expense-action="$sourceAction" />
            @else
                <button type="button" wire:click="{{ $sourceAction }}" class="flex items-center gap-3 rounded-lg py-4 text-left focus-visible:outline-2 focus-visible:outline-primary"><span class="pill pill-muted">{{ $financialRow->currency }}</span><span class="flex-1 text-sm text-muted-foreground">{{ $financialRow->projects }} {{ $financialRow->projects === 1 ? 'project' : 'projects' }} · No approved budget or cash records</span><flux:icon.arrow-up-right class="size-4 text-muted-foreground" /></button>
            @endif
        </section>
    @endforeach
    <div class="absolute"><flux:modal wire:model.self="sourcesOpen" class="w-full max-w-xl"><div class="space-y-4"><flux:heading size="lg" level="2">Finance · {{ $sourceCurrency }}</flux:heading><x-work.source-projects :projects="$sourceProjects" view="finance" /></div></flux:modal></div>
</div>
