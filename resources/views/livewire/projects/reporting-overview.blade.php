@use('App\Modules\WorkManagement\Domain\Projects\FinanceAmount')
<div class="contents">
    @if ($results['indicators'] > 0 || array_sum($reports) > 0)
        <section class="ppm-surface ppm-chart-card" aria-label="Results and reporting overview">
            <header><h3 class="ppm-section-title">Results & reporting</h3>@if ($projectId !== null)<a class="ppm-card-link" href="{{ route('projects.show', ['project' => $projectId, 'view' => 'reporting']) }}" wire:navigate>Open reporting <span aria-hidden="true">↗</span></a>
                @endif
            </header>
            @if ($results['indicators'] > 0)
                <button type="button" wire:click="openSource('results')" class="flex flex-wrap items-center gap-5 rounded-lg text-left focus-visible:outline-2 focus-visible:outline-primary" aria-label="View projects with recorded results">
                    <span class="relative size-28 shrink-0"><svg viewBox="0 0 100 100" class="size-full -rotate-90" aria-hidden="true"><circle cx="50" cy="50" r="40" fill="none" stroke-width="9" class="stroke-muted" /><circle cx="50" cy="50" r="40" fill="none" stroke-width="9" class="stroke-success" stroke-dasharray="{{ 251.327 * $results['met'] / max(1, $results['planned']) }} 251.327" /></svg><span class="num absolute inset-0 flex flex-col items-center justify-center text-2xl font-semibold">{{ $results['met'] }}<span class="mt-1 text-xs font-normal text-muted-foreground">targets met</span></span></span>
                    <span class="flex-1 space-y-3 text-xs"><span class="flex justify-between gap-4"><span class="text-muted-foreground">Planned targets</span><strong class="num">{{ $results['planned'] }}</strong></span><span class="flex justify-between gap-4"><span class="text-muted-foreground">Actuals recorded</span><strong class="num">{{ $results['recorded'] }} / {{ $results['indicators'] }}</strong></span><span class="flex justify-between gap-4 {{ $results['missing_evidence'] > 0 ? 'text-warning' : 'text-muted-foreground' }}"><span>Evidence missing</span><strong class="num">{{ $results['missing_evidence'] }}</strong></span></span>
                </button>
            @endif
            @php($reportCount = $reports['draft'] + $reports['submitted'] + $reports['returned'] + $reports['accepted'])
            @if ($reportCount > 0)
                <button type="button" wire:click="openSource('reports')" class="space-y-3 rounded-lg text-left focus-visible:outline-2 focus-visible:outline-primary" aria-label="View projects with reports"><span class="flex items-center justify-between text-xs font-medium">Reports<flux:icon.arrow-up-right class="size-3 text-muted-foreground" /></span><span class="flex h-3 overflow-hidden rounded-full bg-muted" aria-hidden="true">@foreach (['draft' => 'bg-muted-foreground', 'submitted' => 'bg-primary', 'returned' => 'bg-warning', 'accepted' => 'bg-success'] as $key => $color)<span class="{{ $color }}" style="width: {{ 100 * $reports[$key] / $reportCount }}%"></span>
                    @endforeach
                    </span><span class="grid grid-cols-2 gap-x-5 gap-y-2 text-xs">@foreach (['draft' => 'Draft', 'submitted' => 'Awaiting review', 'returned' => 'Returned', 'accepted' => 'Accepted'] as $key => $label)<span class="flex justify-between gap-3"><span class="text-muted-foreground">{{ $label }}</span><strong class="num">{{ $reports[$key] }}</strong></span>
                    @endforeach
                    </span></button>
                @if ($reports['overdue'] > 0)<button type="button" wire:click="openSource('reports')" class="flex items-center gap-2 text-xs text-warning" title="Draft or returned reports past their deadline"><flux:icon.clock class="size-3.5" />Past due<span class="ppm-count">{{ $reports['overdue'] }}</span></button>
                @endif
            @endif
        </section>
    @endif
    @foreach ($funding as $currency)
        <section class="ppm-surface ppm-chart-card" aria-label="Funding and contribution summary in {{ $currency['currency'] }}">
            <header><h3 class="ppm-section-title">Funding & contributions</h3><div class="flex items-center gap-2"><span class="pill pill-muted">{{ $currency['currency'] }}</span><flux:button size="sm" variant="ghost" square icon="arrow-up-right" wire:click="openSource('funding', '{{ $currency['currency'] }}')" aria-label="View funding sources in {{ $currency['currency'] }}" /></div></header>
            @foreach (['FUNDING' => 'Funding receipts', 'CASH' => 'Cash contributions', 'IN_KIND' => 'In-kind delivered value'] as $kind => $label)
                @php($series = $currency['series'][$kind])
                @if ($series['sources'] > 0)
                    <button type="button" wire:click="openSource('funding', '{{ $currency['currency'] }}')" class="min-w-0 space-y-2 rounded-lg text-left focus-visible:outline-2 focus-visible:outline-primary"><span class="flex items-center justify-between gap-2 text-xs text-muted-foreground">{{ $label }}<flux:icon.arrow-up-right class="size-3" /></span><span class="num block break-words text-xl font-semibold">{{ FinanceAmount::format($series['known'] > 0 ? $series['received'] : $series['unplanned']) }}</span><span class="num block text-xs text-muted-foreground">{{ $series['known'] > 0 ? FinanceAmount::format($series['committed']).' committed' : 'Commitment not set' }}</span>
                        @if ($series['known'] > 0 && $series['committed'] > 0)<span class="block h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true"><span class="block h-full rounded-full {{ $kind === 'IN_KIND' ? 'bg-success' : 'bg-primary' }}" style="width: {{ min(100, 100 * $series['received'] / $series['committed']) }}%"></span></span>
                        @endif
                        @if ($series['sources'] > $series['known'])<span class="num block text-xs text-warning">{{ $series['sources'] - $series['known'] }} commitments unset · {{ FinanceAmount::format($series['unplanned']) }} recorded</span>
                        @endif
                    </button>
                @endif
            @endforeach
            @if ($currency['unlinked'] > 0 || $currency['inactive'] > 0 || $currency['inactive_inkind'] > 0 || $currency['unvalued'] > 0)
                <dl class="grid grid-cols-2 gap-4 border-t border-border pt-4">
                    @foreach (['unlinked' => ['Unlinked receipts', 'Receipts without a funding or contribution source link', 'link-slash'], 'unvalued' => ['Unvalued deliveries', 'In-kind deliveries without a recorded valuation', 'cube'], 'inactive' => ['Inactive receipts', 'Receipts from discontinued sources', 'archive-box'], 'inactive_inkind' => ['Inactive in-kind', 'In-kind value from discontinued sources', 'archive-box']] as $key => [$label, $hint, $icon])
                        @if ($currency[$key] > 0)
                            <div title="{{ $hint }}"><dt class="flex items-center gap-1.5 text-xs text-muted-foreground"><flux:icon :name="$icon" class="size-3.5 shrink-0" />{{ $label }}</dt><dd class="num mt-1 text-lg font-semibold {{ $key === 'unvalued' ? 'text-warning' : '' }}">{{ $key === 'unvalued' ? $currency[$key] : FinanceAmount::format($currency[$key]) }}</dd></div>
                        @endif
                    @endforeach
                </dl>
            @endif
        </section>
    @endforeach
    <div class="absolute"><flux:modal wire:model.self="sourcesOpen" class="w-full max-w-xl"><div class="space-y-4"><flux:heading size="lg" level="2">{{ ['results' => 'Results', 'reports' => 'Reports', 'funding' => 'Funding · '.$sourceCurrency][$sourceKind] }}</flux:heading><x-work.source-projects :projects="$sourceProjects" :view="$sourceKind === 'funding' ? 'finance' : 'reporting'" /></div></flux:modal></div>
</div>
