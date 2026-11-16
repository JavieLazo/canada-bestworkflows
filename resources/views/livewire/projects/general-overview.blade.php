@php
    use App\Domain\Projects\ProjectPolicy;
    use App\Livewire\Projects\Support\OverviewCharts;
    use App\Modules\WorkManagement\Application\OverviewPeriod;
    use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
    use App\Support\EmployeeName;
    $work = $outlook['totals'];
    $openProjects = array_sum(array_diff_key($outlook['statuses'], array_flip(['COMPLETED', 'CANCELLED'])));
    $projectUrl = fn (array $filters = []) => route('projects.index', array_filter(['portfolio' => $portfolio, 'section' => 'projects', ...$filters], fn (string $value): bool => $value !== 'ALL'));
    $cards = [
        ['Open tasks', OverviewCharts::trend($period, $trends['open']['current'], $trends['open']['previous'], 'stock', false), 'Tasks open at the end of each '.$period->unit.', leaving out any cancelled since.'],
        ['New tasks', OverviewCharts::trend($period, $trends['created']['current'], $trends['created']['previous'], 'count', null), 'Tasks added in this period.'],
        ['Completed', OverviewCharts::trend($period, $trends['completed']['current'], $trends['completed']['previous'], 'count', true), 'Tasks marked complete in this period.'],
        ['On time', OverviewCharts::trend($period, $trends['onTime']['current'], $trends['onTime']['previous'], 'rate', true), 'Share of completed tasks finished by their due date.'],
    ];
    $attention = [
        ['Overdue', $work->overdue, 'OVERDUE', 'clock', 'ppm-chip-danger', 'Open tasks past their due date.'],
        ['Due soon', $work->dueSoon, 'DUE_SOON', 'calendar', 'ppm-chip-warning', 'Open tasks due today or in the next '.ProjectPolicy::HEALTH_TASK_DUE_SOON_DAYS.' days.'],
        ['Unassigned', $outlook['unassigned'], 'UNASSIGNED', 'user-plus', 'ppm-chip-muted', 'Open tasks nobody is assigned to yet.'],
        ['Awaiting review', $work->review, 'REVIEW', 'inbox-arrow-down', 'ppm-chip-primary', 'Handed-in tasks and responses waiting for a reviewer.'],
    ];
    // Furthest along at the bottom; this order also keeps blue between the magenta and the gray for colorblind readers.
    $stages = ['submitted' => 'Submitted', 'progress' => 'In progress', 'pending' => 'Not started'];
    $aheadMax = max([1, ...array_map(fn (array $column): int => $column['progress'] + $column['submitted'] + $column['pending'], $outlook['ahead'])]);
    $aheadPoints = array_map(fn (array $column): array => ['title' => $column['title'], 'rows' => [
        ...array_map(fn (string $stage): array => ['label' => $stages[$stage], 'value' => number_format($column[$stage]), 'key' => $stage], array_keys($stages)),
        ['label' => 'Total', 'value' => number_format($column['progress'] + $column['submitted'] + $column['pending']), 'key' => 'total'],
    ]], $outlook['ahead']);
    $loadMax = max([1, ...$workload->pluck('active')->all()]);
    $loadPoints = $workload->map(fn (array $entry): array => ['title' => EmployeeName::display($entry['employee']), 'rows' => [
        ['label' => 'Overdue', 'value' => number_format($entry['overdue']), 'key' => 'overdue'],
        ['label' => 'Not overdue', 'value' => number_format($entry['active'] - $entry['overdue']), 'key' => 'progress'],
        ['label' => 'Awaiting review', 'value' => number_format($entry['review']), 'key' => 'total'],
        ['label' => 'Open in all', 'value' => number_format($entry['active']), 'key' => 'total'],
    ]])->values()->all();
    $statusOrder = ['ACTIVE', 'PLANNING', 'ON_HOLD', 'COMPLETED', 'CANCELLED'];
    $dueText = fn ($date): string => $date === null ? 'No date' : $date->format($date->format('Y') === substr($period->today, 0, 4) ? 'M j' : 'M j, Y');
@endphp
<section class="space-y-4" aria-label="Visible work overview">
    <div class="ppm-range-row">
        <x-work.popover id="overview-range" label="Date range">
            <x-slot:trigger>
                <flux:button x-ref="trigger" size="sm" variant="outline" icon="calendar" icon:trailing="chevron-down" popovertarget="overview-range" aria-haspopup="dialog" x-bind:aria-expanded="open.toString()">{{ $period->label }}</flux:button>
            </x-slot:trigger>
            <div class="ppm-range-options" x-on:overview-range-picked.window="$refs.panel.hidePopover()">
                @foreach (OverviewPeriod::PRESETS as $key => $name)
                    <button type="button" class="ppm-range-option" wire:click="pickRange('{{ $key }}')" x-on:click="$refs.panel.hidePopover()" aria-pressed="{{ $period->key === $key ? 'true' : 'false' }}">
                        <span>{{ $name }}</span>
                        @if ($period->key === $key)
                            <flux:icon.check variant="micro" class="size-4 text-primary" />
                        @endif
                    </button>
                @endforeach
            </div>
            <div class="space-y-3 border-t border-border pt-4">
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-2"><flux:label>From</flux:label><x-kit.date-field wire:model="draftFrom" aria-label="Range from date" /></div>
                    <div class="space-y-2"><flux:label>Through</flux:label><x-kit.date-field wire:model="draftTo" aria-label="Range through date" /></div>
                </div>
                @error('range')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
                <flux:button size="sm" variant="primary" class="w-full" wire:click="applyRange">Show these dates</flux:button>
            </div>
        </x-work.popover>
        <div class="ppm-legend" aria-hidden="true"><span><i class="ppm-key ppm-key-line"></i>{{ $period->label }}</span><span><i class="ppm-key ppm-key-dash"></i>{{ $period->previousLabel }}</span></div>
    </div>

    <div class="ppm-trends">
        @foreach ($cards as [$label, $chart, $about])
            <article class="ppm-surface ppm-kpi" wire:key="trend-{{ $loop->index }}-{{ $period->key }}-{{ $from }}-{{ $to }}">
                <header class="ppm-kpi-head">
                    <h2 class="ppm-kpi-label">{{ $label }}</h2>
                    <x-work.hint :text="$about" :label="'About '.strtolower($label)"><flux:icon.information-circle variant="outline" class="size-4" /></x-work.hint>
                </header>
                <div class="ppm-kpi-value">
                    <strong>{{ $chart['text'] }}</strong>
                    @if ($chart['delta'] !== null)
                        <x-work.hint :text="$chart['delta']['sentence']" :label="$chart['delta']['sentence']" class="ppm-delta ppm-delta-{{ $chart['delta']['tone'] }}"><flux:icon :name="$chart['delta']['arrow']" variant="micro" class="size-3.5" />{{ $chart['delta']['text'] }}</x-work.hint>
                    @endif
                </div>
                <div class="ppm-spark" x-data="overviewChart(@js($chart['points']))" tabindex="0" role="img"
                    aria-label="{{ $label }}, {{ strtolower($period->label) }}: {{ $chart['text'] }}.{{ $chart['delta'] === null ? '' : ' '.$chart['delta']['sentence'] }}"
                    x-on:pointermove="track($event)" x-on:pointerleave="hide()" x-on:focus="show(points.length - 1)" x-on:blur="hide()"
                    x-on:keydown.arrow-right.prevent="step(1)" x-on:keydown.arrow-left.prevent="step(-1)" x-on:keydown.escape="hide()">
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                        @foreach ($chart['previousLines'] as $line)
                            <polyline points="{{ $line }}" class="ppm-spark-previous" vector-effect="non-scaling-stroke" />
                        @endforeach
                        @if ($chart['area'] !== null)
                            <polygon points="{{ $chart['area'] }}" class="ppm-spark-area" />
                        @endif
                        @foreach ($chart['lines'] as $line)
                            <polyline points="{{ $line }}" class="ppm-spark-line" vector-effect="non-scaling-stroke" />
                        @endforeach
                    </svg>
                    <div x-ref="plot" class="ppm-spark-plot" aria-hidden="true">
                        <span class="ppm-spark-rule" x-show="point" x-bind:style="point ? { left: point.x + '%' } : {}"></span>
                        <span class="ppm-spark-dot is-previous" x-show="point && point.py !== null" x-bind:style="point ? { left: point.x + '%', top: point.py + '%' } : {}"></span>
                        <span class="ppm-spark-dot" x-show="point && point.y !== null" x-bind:style="point ? { left: point.x + '%', top: point.y + '%' } : {}"></span>
                    </div>
                    <div x-ref="card" popover="manual" class="ppm-hovercard" aria-hidden="true">
                        <template x-for="row in (point ? point.rows : [])" :key="row.key">
                            <div class="ppm-hovercard-row"><i class="ppm-key" x-bind:class="'ppm-key-' + row.key"></i><span x-text="row.label"></span><strong x-text="row.value"></strong></div>
                        </template>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    <section class="ppm-surface ppm-band" aria-label="Needs attention">
        <h2 class="sr-only">Needs attention</h2>
        @foreach ($attention as [$label, $value, $queue, $icon, $chip, $about])
            <div x-data="overviewHint" class="contents">
                <a x-ref="trigger" href="{{ $projectUrl(['queue' => $queue]) }}" class="ppm-band-cell" aria-describedby="band-hint-{{ $queue }}" wire:navigate
                    x-on:mouseenter="open()" x-on:mouseleave="close()" x-on:focus="open()" x-on:blur="close()">
                    <span class="flex items-center gap-2 text-xs text-muted-foreground"><span class="ppm-chip-icon {{ $value > 0 ? $chip : 'ppm-chip-muted' }}"><flux:icon :name="$icon" variant="micro" class="size-3.5" /></span>{{ $label }}</span>
                    <strong class="mt-2 block text-2xl font-semibold tracking-tight {{ $value > 0 ? '' : 'text-muted-foreground' }}">{{ number_format($value) }}</strong>
                </a>
                <span x-ref="hint" id="band-hint-{{ $queue }}" popover="manual" role="tooltip" class="ppm-hovercard ppm-hovercard-text">{{ $about }}</span>
            </div>
        @endforeach
    </section>

    <div class="grid gap-4 lg:grid-cols-12">
        <section class="ppm-surface ppm-chart-card {{ $budgets === null ? 'lg:col-span-12' : 'lg:col-span-8' }}" aria-label="Projects">
            <header>
                <h2 class="ppm-section-title">Projects</h2>
                <a href="{{ $projectUrl() }}" class="ppm-card-link" wire:navigate>All projects <span aria-hidden="true">↗</span></a>
            </header>
            <nav class="flex flex-wrap gap-2" aria-label="Projects by status">
                @foreach ($statusOrder as $state)
                    <a href="{{ $projectUrl(['status' => $state]) }}" class="ppm-status-chip" wire:navigate><strong>{{ number_format($outlook['statuses'][$state] ?? 0) }}</strong><span>{{ ProjectPolicy::STATUSES[$state] }}</span></a>
                @endforeach
            </nav>
            <div>
                <div class="ppm-attn-row ppm-attn-head" aria-hidden="true"><span class="flex items-center gap-1.5">Needs attention <span class="ppm-count">{{ $outlook['atRisk'] }}</span></span><span>Progress</span><span>Status</span><span>Due</span></div>
                @forelse ($outlook['attention'] as ['project' => $project, 'totals' => $totals, 'health' => $health])
                    @php($progress = $totals->progress())
                    <a href="{{ route('projects.show', $project->id) }}" class="ppm-attn-row" wire:key="attention-{{ $project->id }}" wire:navigate>
                        <span class="min-w-0"><span class="block truncate text-sm font-medium" title="{{ $project->name }}">{{ $project->name }}</span><span class="block truncate text-xs text-muted-foreground">{{ $project->client_name ?? 'Internal' }}</span></span>
                        <span class="flex items-center gap-2" aria-label="{{ $progress['percent'] }}% of tasks done"><span class="ppm-meter {{ $health === 'OVERDUE' ? 'is-danger' : 'is-warning' }}"><span style="width: {{ $progress['percent'] }}%"></span></span><span class="num w-9 shrink-0 text-right text-xs text-muted-foreground">{{ $progress['percent'] }}%</span></span>
                        <span class="text-xs tabular-nums {{ $health === 'OVERDUE' ? 'ppm-text-danger' : 'text-warning' }}">{{ $health === 'OVERDUE' ? number_format($totals->overdue).' overdue' : 'Due soon' }}</span>
                        <span class="num text-xs {{ $project->due_date !== null && $project->due_date->format('Y-m-d') < $period->today ? 'ppm-text-danger' : 'text-muted-foreground' }}">{{ $dueText($project->due_date) }}</span>
                    </a>
                @empty
                    <div class="ppm-dashboard-empty min-h-40"><flux:icon :name="$openProjects > 0 ? 'check-circle' : 'folder'" class="size-8 {{ $openProjects > 0 ? 'text-success' : 'text-muted-foreground/40' }}" /><span>{{ $openProjects > 0 ? 'Every open project is on track.' : 'No open projects.' }}</span></div>
                @endforelse
            </div>
        </section>

        @if ($budgets !== null)
            @php($money = fn (int $minor, string $currency): string => FinanceAmount::format($minor).' '.$currency)
            @php($budgetPoints = array_map(fn (array $row): array => ['title' => $row['project']->name, 'rows' => [
                ['label' => 'Approved budget', 'value' => $money($row['approved'], $row['currency']), 'key' => 'total'],
                ['label' => 'Spent', 'value' => $money($row['paid'], $row['currency']), 'key' => $row['risk'] === 'OVER' ? 'overdue' : 'warning'],
                ['label' => $row['paid'] > $row['approved'] ? 'Over by' : 'Remaining', 'value' => $money(abs($row['approved'] - $row['paid']), $row['currency']), 'key' => 'total'],
                ['label' => 'Work done', 'value' => $row['done'] === null ? 'No tasks yet' : $row['done'].'%', 'key' => 'mark'],
            ]], $budgets['rows']))
            <section class="ppm-surface ppm-chart-card lg:col-span-4" aria-label="Budget">
                <header>
                    <div class="flex items-center gap-1"><h2 class="ppm-section-title">Budget</h2><x-work.hint text="Open projects over budget, or spending well ahead of their work." label="About the budget"><flux:icon.information-circle variant="outline" class="size-4" /></x-work.hint></div>
                    <div class="ppm-legend" aria-hidden="true"><span><i class="ppm-key ppm-key-overdue"></i>Over budget</span><span><i class="ppm-key ppm-key-warning"></i>Ahead of work</span><span><i class="ppm-key ppm-key-mark"></i>Work done</span></div>
                </header>
                @if ($budgets['rows'] === [])
                    <div class="ppm-dashboard-empty min-h-40">
                        @if ($budgets['budgeted'] === 0)
                            <flux:icon.banknotes class="size-8 text-muted-foreground/40" /><span>No approved budgets yet.</span>
                        @else
                            <flux:icon.check-circle class="size-8 text-success" /><span>Every approved budget is on track.</span>
                        @endif
                    </div>
                @else
                    <div class="ppm-budget-list" x-data="overviewChart(@js($budgetPoints))" x-ref="plot">
                        <div class="ppm-budget-head" aria-hidden="true"><span class="flex items-center gap-1.5">Needs a look <span class="ppm-count">{{ $budgets['atRisk'] }}</span></span><span>Spent</span></div>
                        @foreach ($budgets['rows'] as $index => $row)
                            <a href="{{ route('projects.show', ['project' => $row['project']->id, 'view' => 'finance']) }}" class="ppm-budget-row" wire:key="budget-{{ $row['project']->id }}" wire:navigate
                                aria-label="{{ $row['project']->name }}, {{ $row['project']->client_name ?? 'Internal' }}: {{ $row['spent'] === null ? 'over budget' : $row['spent'].'% of the approved budget spent' }}{{ $row['done'] === null ? '' : ', '.$row['done'].'% of work done' }}"
                                x-on:pointerenter="show({{ $index }}, $el)" x-on:pointerleave="hide()" x-on:focus="show({{ $index }}, $el)" x-on:blur="hide()">
                                <span class="flex items-end justify-between gap-3">
                                    <span class="min-w-0"><span class="block truncate text-sm font-medium">{{ $row['project']->name }}</span><span class="block truncate text-xs text-muted-foreground">{{ $row['project']->client_name ?? 'Internal' }}</span></span>
                                    <strong class="num text-sm font-semibold">{{ $row['spent'] === null ? 'Over' : number_format($row['spent']).'%' }}</strong>
                                </span>
                                <span class="ppm-burn">
                                    <span class="ppm-meter ppm-meter-lg {{ $row['risk'] === 'OVER' ? 'is-danger' : 'is-warning' }}"><span style="width: {{ min(100, $row['spent'] ?? 100) }}%"></span></span>
                                    @if ($row['done'] !== null)
                                        <i class="ppm-burn-mark" style="left: {{ $row['done'] }}%"></i>
                                    @endif
                                </span>
                            </a>
                        @endforeach
                        <div x-ref="card" popover="manual" class="ppm-hovercard" aria-hidden="true">
                            <p class="ppm-hovercard-title" x-text="point ? point.title : ''"></p>
                            <template x-for="row in (point ? point.rows : [])" :key="row.label">
                                <div class="ppm-hovercard-row"><i class="ppm-key" x-bind:class="'ppm-key-' + row.key"></i><span x-text="row.label"></span><strong x-text="row.value"></strong></div>
                            </template>
                        </div>
                    </div>
                @endif
                @if ($budgets['unbudgeted'] !== [])
                    <div class="mt-auto">
                        <x-work.popover id="unbudgeted-projects" label="Open projects without an approved budget">
                            <x-slot:trigger><flux:button x-ref="trigger" size="sm" variant="ghost" popovertarget="unbudgeted-projects" aria-haspopup="dialog">{{ count($budgets['unbudgeted']) }} without an approved budget</flux:button></x-slot:trigger>
                            <h3 class="text-sm font-medium">No approved budget</h3>
                            @foreach ($budgets['unbudgeted'] as $project)
                                <a href="{{ route('projects.show', ['project' => $project->id, 'view' => 'finance']) }}" class="min-w-0 text-sm hover:text-primary" wire:navigate><span class="block truncate">{{ $project->name }}</span><span class="block truncate text-xs text-muted-foreground">{{ $project->client_name ?? 'Internal' }}</span></a>
                            @endforeach
                        </x-work.popover>
                    </div>
                @endif
            </section>
        @endif

        <section class="ppm-surface ppm-chart-card lg:col-span-8" aria-label="Work ahead">
            <header>
                <div class="flex items-center gap-1"><h2 class="ppm-section-title">Work ahead</h2><x-work.hint text="Open tasks by due week and stage." label="About work ahead"><flux:icon.information-circle variant="outline" class="size-4" /></x-work.hint></div>
                <div class="ppm-legend" aria-hidden="true">@foreach ($stages as $stage => $name)<span><i class="ppm-key ppm-key-{{ $stage }}"></i>{{ $name }}</span>
                @endforeach
                </div>
            </header>
            <div class="ppm-ahead" x-data="overviewChart(@js($aheadPoints))" x-ref="plot" tabindex="0" role="img"
                aria-label="Open tasks by due date: {{ collect($outlook['ahead'])->map(fn (array $column): string => $column['label'].' '.($column['progress'] + $column['submitted'] + $column['pending']))->implode(', ') }}"
                x-on:pointerleave="hide()" x-on:blur="hide()" x-on:focus="step(1)" x-on:keydown.arrow-right.prevent="step(1)" x-on:keydown.arrow-left.prevent="step(-1)" x-on:keydown.escape="hide()">
                @foreach ($outlook['ahead'] as $index => $column)
                    @php($total = $column['progress'] + $column['submitted'] + $column['pending'])
                    <div class="ppm-ahead-col {{ $column['key'] === 'overdue' ? 'is-overdue' : '' }}" data-index="{{ $index }}" x-on:pointerenter="show({{ $index }}, $el)">
                        <div class="ppm-ahead-plot">
                            <span class="ppm-ahead-total {{ $total === 0 ? 'is-empty' : ($column['key'] === 'overdue' ? 'ppm-text-danger' : '') }}">{{ number_format($total) }}</span>
                            <div class="ppm-ahead-bar" style="height: calc((100% - 1.5rem) * {{ round($total / $aheadMax, 4) }})">
                                @foreach (array_keys($stages) as $stage)
                                    @if ($column[$stage] > 0)
                                        <span class="ppm-fill-{{ $stage }}" style="flex: {{ $column[$stage] }} 1 0%"></span>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        <span class="ppm-ahead-label">{{ $column['label'] }}</span>
                    </div>
                @endforeach
                <div x-ref="card" popover="manual" class="ppm-hovercard" aria-hidden="true">
                    <p class="ppm-hovercard-title" x-text="point ? point.title : ''"></p>
                    <template x-for="row in (point ? point.rows : [])" :key="row.key">
                        <div class="ppm-hovercard-row" x-bind:class="row.key === 'total' ? 'is-total' : ''"><i class="ppm-key" x-bind:class="'ppm-key-' + row.key"></i><span x-text="row.label"></span><strong x-text="row.value"></strong></div>
                    </template>
                </div>
            </div>
        </section>

        <section class="ppm-surface ppm-chart-card lg:col-span-4" aria-label="Workload">
            <header>
                <div class="flex items-center gap-1"><h2 class="ppm-section-title">Workload</h2><x-work.hint text="Open tasks per person; a shared task counts for each." label="About workload"><flux:icon.information-circle variant="outline" class="size-4" /></x-work.hint></div>
                <div class="ppm-legend" aria-hidden="true"><span><i class="ppm-key ppm-key-overdue"></i>Overdue</span><span><i class="ppm-key ppm-key-progress"></i>Not overdue</span></div>
            </header>
            @if ($workload->isEmpty())
                <div class="ppm-dashboard-empty min-h-40"><flux:icon.user-group class="size-8 text-muted-foreground/40" /><span>Nobody has open tasks.</span></div>
            @else
                <div class="ppm-load" x-data="overviewChart(@js($loadPoints))" x-ref="plot" tabindex="0" role="img"
                    aria-label="Open tasks per person: {{ $workload->map(fn (array $entry): string => EmployeeName::display($entry['employee']).' '.$entry['active'].' open, '.$entry['overdue'].' overdue')->implode('; ') }}"
                    x-on:pointerleave="hide()" x-on:blur="hide()" x-on:focus="step(1)" x-on:keydown.arrow-down.prevent="step(1)" x-on:keydown.arrow-up.prevent="step(-1)" x-on:keydown.escape="hide()">
                    @foreach ($workload as $index => $entry)
                        <div class="ppm-load-row" data-index="{{ $index }}" x-on:pointerenter="show({{ $index }}, $el)" wire:key="load-{{ $entry['employee']->id }}">
                            <span class="ppm-avatar">{{ EmployeeName::initials($entry['employee']) }}</span>
                            <span class="min-w-0 truncate text-sm">{{ EmployeeName::display($entry['employee']) }}</span>
                            <span class="ppm-load-track"><span class="ppm-load-bar" style="width: {{ round(100 * $entry['active'] / $loadMax, 2) }}%">
                                @if ($entry['overdue'] > 0)
                                    <span class="ppm-fill-overdue" style="flex: {{ $entry['overdue'] }} 1 0%"></span>
                                @endif
                                @if ($entry['active'] > $entry['overdue'])
                                    <span class="ppm-fill-progress" style="flex: {{ $entry['active'] - $entry['overdue'] }} 1 0%"></span>
                                @endif
                            </span></span>
                            <span class="num w-8 text-right text-sm font-semibold">{{ number_format($entry['active']) }}</span>
                        </div>
                    @endforeach
                    <div x-ref="card" popover="manual" class="ppm-hovercard" aria-hidden="true">
                        <p class="ppm-hovercard-title" x-text="point ? point.title : ''"></p>
                        <template x-for="row in (point ? point.rows : [])" :key="row.key">
                            <div class="ppm-hovercard-row"><i class="ppm-key" x-bind:class="'ppm-key-' + row.key"></i><span x-text="row.label"></span><strong x-text="row.value"></strong></div>
                        </template>
                    </div>
                </div>
            @endif
        </section>
    </div>
</section>
