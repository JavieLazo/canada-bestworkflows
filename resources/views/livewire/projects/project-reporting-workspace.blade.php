@php
    use App\Modules\WorkManagement\Application\ReportDefinitions;
    use App\Modules\WorkManagement\Domain\Projects\ResultValue;
@endphp
<div class="space-y-5" aria-label="Project reporting">
    @if (! $permissions['view'])
        <div class="ppm-surface p-10 text-center">Reporting is no longer available to this account.</div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-work.tabs aria-label="Reporting view">
                <x-work.tab :active="$section === 'reports'" wire:click="$set('section', 'reports')">Reports</x-work.tab>
                @if ($permissions['results'])<x-work.tab :active="$section === 'results'" wire:click="$set('section', 'results')">Targets &amp; results</x-work.tab>
                @endif
                @if ($permissions['admin'])<x-work.tab :active="$section === 'settings'" wire:click="$set('section', 'settings')">Templates & fields</x-work.tab>
                @endif
            </x-work.tabs>
            <div class="ppm-actions" role="group" aria-label="Reporting actions">
                @if ($section === 'settings' && $permissions['admin'])
                    <flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Reporting setup tools" /><flux:menu><flux:menu.item icon="document-duplicate" wire:click="useTemplates">Use general templates</flux:menu.item></flux:menu></flux:dropdown>
                    <flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="primary" icon="plus" icon:trailing="chevron-down">New</flux:button><flux:menu><flux:menu.item icon="document-text" wire:click="openTemplate">Template</flux:menu.item><flux:menu.item icon="view-columns" wire:click="openField">Additional field</flux:menu.item><flux:menu.item icon="calendar-days" wire:click="openPeriod">Reporting period</flux:menu.item></flux:menu></flux:dropdown>
                @endif
                @if ($permissions['edit'] && $section !== 'settings')
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Reporting tools" />
                        <flux:menu>
                            <flux:menu.item icon="arrow-up-tray" wire:click="$dispatch('open-project-workbook', { projectId: '{{ $projectId }}' })">Import XLSX</flux:menu.item>
                            @if ($section === 'results' && $resultsPermissions['edit'])
                                <flux:menu.item icon="plus" wire:click="openOutcome">New objective / deliverable</flux:menu.item>
                                @foreach ($outcomes as $outcome)
                                    <flux:menu.item icon="pencil-square" wire:click="openOutcome('{{ $outcome->id }}')">{{ $outcome->name }}</flux:menu.item>
                                @endforeach
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                @endif
                @if ($section === 'results' && $resultsPermissions['edit'])
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openIndicator">New indicator</flux:button>
                @elseif ($section === 'reports' && $permissions['edit'])
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openReport">New report</flux:button>
                @endif
            </div>
        </div>
        @if ($section === 'results' && $permissions['results'])
            <div class="ppm-collection-toolbar" aria-label="Result controls"><flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.250ms="search" placeholder="Find an indicator…" aria-label="Search indicators" class="ppm-search" />
                <div class="ppm-actions">
                <x-work.filters id="result-filters" label="Result filters" :count="($periodChoice === 'ALL' ? 0 : 1) + ($status === 'ACTIVE' ? 0 : 1)">
                <x-kit.select wire:model.live="periodChoice" label="Period" aria-label="Result period"><flux:select.option value="ALL">All recorded results</flux:select.option>@foreach ($periods as $p)<flux:select.option value="{{ $p->id }}">{{ $p->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                <x-kit.select wire:model.live="status" label="Status" aria-label="Indicator status"><flux:select.option value="ACTIVE">Active indicators</flux:select.option><flux:select.option value="ALL">All indicators</flux:select.option><flux:select.option value="DISCONTINUED">Discontinued</flux:select.option></x-kit.select>
                </x-work.filters>
                </div>
            </div>
            <div class="ppm-surface overflow-hidden"><div class="overflow-x-auto"><table class="hris-table w-full"><caption class="sr-only">Project indicators</caption><thead><tr><x-kit.sort-header field="name" label="Indicator" /><th>Recorded</th><th>Target</th><x-kit.sort-header field="due" label="Target date" /><th class="w-20"></th></tr></thead><tbody>
                @forelse ($indicators ?? [] as $row)
                    <tr wire:key="indicator-{{ $row->id }}"><td class="min-w-52 max-w-sm"><button type="button" class="block max-w-full truncate text-left font-medium hover:text-primary" wire:click="viewIndicator('{{ $row->id }}')" title="{{ $row->name }}">{{ $row->name }}</button><span class="mt-1 block truncate text-xs text-muted-foreground">{{ $row->outcome_label ?? $row->work_label ?? ($row->kind === 'NUMERIC' ? ($row->aggregation === 'SUM' ? 'Added accomplishments' : 'Latest recorded value') : 'Qualitative') }}</span></td>
                        <td class="num whitespace-nowrap">{{ $row->kind === 'NUMERIC' ? ($row->actual_scaled === null ? 'No actual' : ResultValue::format($row->actual_scaled).' '.$row->unit) : ($row->actual_state === null ? 'No actual' : str_replace('_', ' ', ucfirst(strtolower($row->actual_state)))) }}
                            @if ($row->evidence_count > 0)<span class="mt-1 block text-xs text-warning">{{ $row->evidence_count }} missing evidence</span>
                            @endif
                        </td>
                        <td class="num whitespace-nowrap">{{ $row->kind === 'NUMERIC' ? ($row->target_scaled === null ? 'Unplanned' : ResultValue::format($row->target_scaled).' '.$row->unit) : ($row->target_text ?? 'Unplanned') }}
                            @if ($row->kind === 'NUMERIC' && ResultValue::met($row->actual_scaled, $row->target_scaled, $row->direction) !== null)<span class="mt-1 block text-xs {{ ResultValue::met($row->actual_scaled, $row->target_scaled, $row->direction) ? 'text-success' : 'text-muted-foreground' }}">{{ ResultValue::met($row->actual_scaled, $row->target_scaled, $row->direction) ? 'Target met' : 'Target not met' }}</span>
                            @endif
                        </td>
                        <td class="num whitespace-nowrap">{{ $row->ends_on ?? '—' }}</td>
                        <td>@if ($resultsPermissions['edit'] && $row->status === 'ACTIVE')<flux:button size="sm" variant="ghost" icon="plus" wire:click="openResult('{{ $row->id }}')" aria-label="Record accomplishment for {{ $row->name }}" />
                            @endif
                        </td></tr>
                @empty<tr><x-kit.empty-state :colspan="5" :title="$search !== '' || $periodChoice !== 'ALL' || $status !== 'ACTIVE' ? 'No results match these filters.' : 'No results tracked yet.'" :hint="$search !== '' || $periodChoice !== 'ALL' || $status !== 'ACTIVE' ? 'Try another search or clear the filters.' : ($resultsPermissions['edit'] ? 'Add an indicator and target, then record accomplishments.' : 'Results will appear when your team records them.')" /></tr>
                @endforelse
            </tbody></table></div>@if ($indicators !== null)<x-kit.pagination :paginator="$indicators" noun="indicators" />
                @endif
            </div>
            @if ($search !== '' || $periodChoice !== 'ALL' || $status !== 'ACTIVE')<flux:button size="sm" variant="ghost" wire:click="clearFilters">Clear filters</flux:button>
            @endif
            @if ($outcomes->isNotEmpty())
                <details class="text-xs text-muted-foreground">
                    <summary class="flex w-fit cursor-pointer items-center gap-2">Objectives & deliverables<span class="ppm-count">{{ $outcomes->count() }}</span></summary>
                    <ul class="mt-3 space-y-3">
                        @foreach ($outcomes as $outcome)
                            <li class="flex min-w-0 items-center gap-2"><flux:icon :name="$outcome->kind === 'OBJECTIVE' ? 'flag' : 'cube'" class="size-3.5 shrink-0" /><span class="min-w-0 flex-1 truncate" title="{{ $outcome->description }}">{{ $outcome->name }}{{ $outcome->status !== 'ACTIVE' ? ' · Discontinued' : '' }}</span>@if ($resultsPermissions['edit'])<flux:button size="sm" variant="ghost" square icon="pencil-square" wire:click="openOutcome('{{ $outcome->id }}')" aria-label="Edit {{ $outcome->name }}" />@endif</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @elseif ($section === 'reports')
            @include('livewire.projects.partials.reporting-reports')
        @elseif ($section === 'settings' && $permissions['admin'])
            @include('livewire.projects.partials.reporting-settings')
        @endif
        @if ($indicatorOpen && $indicator !== null)
            <flux:modal wire:model.self="indicatorOpen" class="w-full max-w-3xl"><div class="space-y-5"><div class="flex flex-wrap items-start justify-between gap-3 pr-8"><div class="min-w-0"><flux:heading size="lg" level="2">{{ $indicator->name }}</flux:heading><p class="mt-1 text-xs text-muted-foreground">Plan version {{ $indicator->plan_version }} · {{ $indicator->unit ?: 'Qualitative' }}</p></div>@if ($resultsPermissions['edit'])<flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openIndicator('{{ $indicator->id }}')">Edit plan</flux:button>
                @endif
            </div>
                @if ($indicator->description)<p class="whitespace-pre-line text-sm">{{ $indicator->description }}</p>
                @endif
                <livewire:projects.record-fields :project-id="$projectId" entity="INDICATOR" :record-id="$indicator->id" :key="'indicator-fields-'.$indicator->id" />
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="text-sm font-medium">Accomplishments</h3><div class="flex items-center gap-3"><flux:checkbox wire:model.live="showVoided" label="Include history" />@if ($resultsPermissions['edit'] && $indicator->status === 'ACTIVE')<flux:button size="sm" variant="primary" icon="plus" wire:click="openResult('{{ $indicator->id }}')">Record result</flux:button>
                    @endif
                </div></div>
                <div class="flex flex-wrap items-center gap-2"><flux:input size="sm" wire:model.live.debounce.250ms="entrySearch" icon="magnifying-glass" placeholder="Find an accomplishment…" aria-label="Search accomplishments" class="min-w-40 flex-1" /><flux:button size="sm" variant="ghost" :icon="$entryDirection === 'desc' ? 'arrow-down' : 'arrow-up'" wire:click="$set('entryDirection', '{{ $entryDirection === 'desc' ? 'asc' : 'desc' }}')" aria-label="Reverse accomplishment dates">Date</flux:button></div>
                <div class="divide-y divide-border">
                    @forelse ($entries ?? [] as $e)<div class="flex flex-wrap items-start gap-3 py-3" wire:key="result-entry-{{ $e->id }}"><button type="button" class="min-w-0 flex-1 text-left" wire:click="viewEntry('{{ $e->id }}')"><span class="num text-sm font-medium">{{ $e->occurred_on }} · {{ $e->value_scaled === null ? ($e->value_text ?? str_replace('_', ' ', $e->state ?? '')) : ResultValue::format($e->value_scaled).' '.$indicator->unit }}</span><span class="mt-1 block truncate text-xs text-muted-foreground">{{ $e->voided_at === null ? $e->narrative : 'Voided · '.$e->void_reason }}</span></button>
                        @if ($resultsPermissions['edit'] && $e->voided_at === null)<flux:dropdown><flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Accomplishment actions" /><flux:menu><flux:menu.item wire:click="openResult('{{ $indicator->id }}', '{{ $e->id }}')">Correct</flux:menu.item><flux:menu.item variant="danger" wire:click="openVoid('{{ $e->id }}')">Void</flux:menu.item></flux:menu></flux:dropdown>
                        @endif
                    </div>@empty<p class="py-6 text-sm text-muted-foreground">No accomplishments recorded.</p>
                    @endforelse
                </div>
                @if ($entries !== null)<x-work.compact-pagination :paginator="$entries" noun="accomplishments" />
                @endif
                @if ($entry !== null)
                    <section class="space-y-4 rounded-lg border border-border p-4"><h3 class="num text-sm font-semibold">{{ $entry->occurred_on }}</h3>@foreach (['narrative' => 'Accomplishment', 'issues' => 'Issues', 'corrective_action' => 'Corrective action', 'reference' => 'Reference'] as $key => $label)@if ($entry->$key)<div><h4 class="text-xs text-muted-foreground">{{ $label }}</h4><p class="mt-1 whitespace-pre-line break-words text-sm">{{ $entry->$key }}</p></div>
                        @endif
                        @endforeach
                        <livewire:projects.record-evidence-panel :project-id="$projectId" entity="RESULT_ENTRY" :record-id="$entry->id" :key="'result-proof-'.$entry->id" />
                        <livewire:projects.record-fields :project-id="$projectId" entity="RESULT_ENTRY" :record-id="$entry->id" :key="'result-fields-'.$entry->id" />
                    </section>
                @endif
                @if (! empty($indicator->history))<details class="text-sm"><summary class="w-fit font-medium">Plan history</summary><ul class="mt-3 space-y-2 text-xs text-muted-foreground">@foreach ($indicator->history as $h)<li>{{ $h['at'] }} · {{ $h['reason'] }}</li>
                    @endforeach
                </ul></details>
                @endif
            </div></flux:modal>
        @endif
        @if ($permissions['edit'])<livewire:projects.workbook-import :project-id="$projectId" :show-trigger="false" :key="'reporting-import-'.$projectId" />
        @endif
        @include('livewire.projects.partials.reporting-dialog')
    @endif
</div>
