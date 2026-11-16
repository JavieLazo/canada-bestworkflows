@php
    use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
    use App\Modules\WorkManagement\Domain\Projects\ResultValue;
@endphp
<div class="space-y-5" aria-label="Funding and contributions">
    @if (! $permissions['view'] || $settings === null)<p class="text-sm text-muted-foreground">Project finance is unavailable.</p>
    @else
        <div class="ppm-surface flex flex-wrap items-center justify-between gap-4 p-5"><div><h2 class="text-xs text-muted-foreground">Recorded cash position · {{ $settings->currency }}</h2><p class="num mt-1 text-2xl font-semibold">{{ $cashPosition === null ? 'No opening record' : FinanceAmount::format($cashPosition) }}</p>@if ($settings->opening_on)<p class="mt-1 text-xs text-muted-foreground">From the opening record on {{ $settings->opening_on }}</p>
            @endif
        </div>@if ($permissions['admin'])<flux:button size="sm" variant="outline" wire:click="openOpening">{{ $settings->opening_revision === 0 ? 'Add opening record' : 'Edit opening record' }}</flux:button>
            @endif
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3"><x-work.tabs aria-label="Funding records"><x-work.tab :active="$section === 'funding'" wire:click="$set('section', 'funding')">Funding</x-work.tab><x-work.tab :active="$section === 'contributions'" wire:click="$set('section', 'contributions')">Contributions</x-work.tab></x-work.tabs>@if ($permissions['edit'])<flux:button size="sm" variant="primary" icon="plus" wire:click="{{ $section === 'funding' ? 'openFunding' : 'openContribution' }}">{{ $section === 'funding' ? 'New funding' : 'New contribution' }}</flux:button>
            @endif
        </div>
        <div class="ppm-collection-toolbar" aria-label="Funding controls"><flux:input size="sm" wire:model.live.debounce.250ms="search" icon="magnifying-glass" placeholder="Find a funding record…" aria-label="Search funding records" class="ppm-search" /><x-work.filters id="funding-filters" label="Funding filters" :count="$status === 'ALL' ? 0 : 1"><x-kit.select wire:model.live="status" label="Status" aria-label="Funding status"><flux:select.option value="ALL">All records</flux:select.option><flux:select.option value="ACTIVE">Active</flux:select.option><flux:select.option value="DISCONTINUED">Discontinued</flux:select.option></x-kit.select></x-work.filters></div>
        <div class="ppm-surface overflow-hidden"><div class="overflow-x-auto"><table class="hris-table w-full"><caption class="sr-only">{{ $section === 'funding' ? 'Funding commitments' : 'Contributions' }}</caption><thead><tr><x-kit.sort-header field="name">{{ $section === 'funding' ? 'Funding' : 'Contribution' }}</x-kit.sort-header><x-kit.sort-header field="committed">Committed ({{ $settings->currency }})</x-kit.sort-header><x-kit.sort-header field="received">Received ({{ $settings->currency }})</x-kit.sort-header><x-kit.sort-header field="date" label="Expected" /></tr></thead><tbody>
            @forelse (($section === 'funding' ? $fundings : $contributions) ?? [] as $row)<tr wire:key="source-row-{{ $row->id }}"><td class="min-w-52 max-w-sm"><button type="button" class="block max-w-full truncate text-left font-medium hover:text-primary" wire:click="show('{{ $section === 'funding' ? 'funding' : 'contribution' }}', '{{ $row->id }}')">{{ $row->name }}</button><span class="mt-1 block text-xs text-muted-foreground">{{ $section === 'funding' ? $row->provider : $row->contributor }}{{ $section === 'contributions' ? ' · '.($row->kind === 'CASH' ? 'Cash' : 'In-kind') : '' }}{{ $row->status !== 'ACTIVE' ? ' · Discontinued' : '' }}</span></td><td class="num whitespace-nowrap">{{ $row->committed_minor === null ? 'Unplanned' : FinanceAmount::format($row->committed_minor) }}@if ($section === 'contributions' && $row->quantity_scaled !== null)<span class="mt-1 block text-xs text-muted-foreground">{{ ResultValue::format($row->quantity_scaled) }} {{ $row->unit }}</span>
                @endif
            </td><td class="num whitespace-nowrap">{{ $row->received_minor === null ? 'No receipts' : FinanceAmount::format($row->received_minor) }}@if ($section === 'contributions' && $row->received_quantity !== null)<span class="mt-1 block text-xs text-muted-foreground">{{ ResultValue::format($row->received_quantity) }} {{ $row->unit }}</span>
                @endif
            </td><td class="num whitespace-nowrap">{{ $row->expected_on ?? '—' }}</td></tr>@empty<tr><td colspan="4" class="py-10 text-center text-muted-foreground">{{ $section === 'funding' ? 'No funding commitments recorded.' : 'No contributions recorded.' }}</td></tr>
            @endforelse
        </tbody></table></div>@if (($section === 'funding' ? $fundings : $contributions) !== null)<x-kit.pagination :paginator="$section === 'funding' ? $fundings : $contributions" :noun="$section" />
            @endif
        </div>
        @if ($detail !== null)
            <section class="ppm-surface space-y-5 p-5" aria-label="Funding record details"><div class="flex flex-wrap items-start justify-between gap-3"><h2 class="min-w-0 flex-1 break-words text-lg font-semibold">{{ $detail->name }}</h2><div class="flex flex-wrap items-center gap-2">@if ($permissions['edit'])<flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="{{ $detailKind === 'funding' ? 'openFunding' : 'openContribution' }}('{{ $detail->id }}')">Edit</flux:button>@if ($detailKind === 'funding' || $detail->kind === 'CASH')<flux:button size="sm" variant="primary" icon="plus" wire:click="$dispatch('record-source-receipt', { kind: '{{ $detailKind === 'funding' ? 'FUNDING' : 'CONTRIBUTION' }}', sourceId: '{{ $detail->id }}' })">Cash received</flux:button>@else<flux:button size="sm" variant="primary" icon="plus" wire:click="openDelivery('{{ $detail->id }}')">Record delivery</flux:button>
                    @endif
                    @endif
                <flux:button size="sm" variant="ghost" square icon="x-mark" wire:click="$set('detailId', null)" aria-label="Close funding details" /></div></div>
                @if ($detail->notes)<p class="whitespace-pre-line break-words text-sm">{{ $detail->notes }}</p>
                @endif
                @if ($detailKind === 'contribution' && $detail->valuation_basis)<div><h3 class="text-xs text-muted-foreground">Valuation basis</h3><p class="mt-1 whitespace-pre-line break-words text-sm">{{ $detail->valuation_basis }}</p></div>
                @endif
                <livewire:projects.record-fields :project-id="$projectId" :entity="$detailKind === 'funding' ? 'FUNDING' : 'CONTRIBUTION'" :record-id="$detail->id" :key="'source-fields-'.$detail->id" />
                @if ($detailKind === 'funding' || $detail->kind === 'IN_KIND')
                    <div class="flex flex-wrap items-center gap-2"><flux:input size="sm" wire:model.live.debounce.250ms="detailSearch" icon="magnifying-glass" placeholder="Find a record…" aria-label="Search funding detail records" class="min-w-40 flex-1" /><flux:button size="sm" variant="ghost" :icon="$detailDirection === 'desc' ? 'arrow-down' : 'arrow-up'" wire:click="$set('detailDirection', '{{ $detailDirection === 'desc' ? 'asc' : 'desc' }}')" aria-label="Reverse funding detail dates">Date</flux:button></div>
                @endif
                @if ($detailKind === 'funding')
                    <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-medium">Installments</h3>@if ($permissions['edit'])<flux:button size="sm" variant="outline" icon="plus" wire:click="openInstallment('{{ $detail->id }}')">Add installment</flux:button>
                        @endif
                    </div>
                    <ul class="divide-y divide-border">@forelse ($installments ?? [] as $i)<li class="flex flex-wrap items-center gap-3 py-3" wire:key="funding-installment-{{ $i->id }}"><div class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{{ $i->name }}</span><span class="num mt-1 block text-xs text-muted-foreground">{{ $i->planned_on ?? 'Unscheduled' }} · {{ $settings->currency }} {{ FinanceAmount::format($i->planned_minor) }} planned · {{ FinanceAmount::format($i->received_minor ?? 0) }} received{{ $i->status !== 'ACTIVE' ? ' · Discontinued' : '' }}</span>@if ($i->conditions)<p class="mt-1 whitespace-pre-line text-xs text-muted-foreground">{{ $i->conditions }}</p>
                        @endif
                    </div>@if ($permissions['edit'])<flux:button size="sm" variant="ghost" square icon="pencil-square" wire:click="openInstallment('{{ $detail->id }}', '{{ $i->id }}')" aria-label="Edit {{ $i->name }}" /><flux:button size="sm" variant="outline" wire:click="$dispatch('record-source-receipt', { kind: 'FUNDING', sourceId: '{{ $detail->id }}', installment: '{{ $i->id }}' })">Record receipt</flux:button>
                        @endif
                    </li>@empty<li class="py-4 text-sm text-muted-foreground">No installments planned.</li>
                        @endforelse
                    </ul>@if ($installments !== null)<x-work.compact-pagination :paginator="$installments" noun="installments" />
                    @endif
                @elseif ($detail->kind === 'IN_KIND')
                    <h3 class="text-sm font-medium">Deliveries</h3><ul class="divide-y divide-border">@forelse ($deliveries ?? [] as $d)<li class="flex items-start gap-3 py-3" wire:key="contribution-delivery-{{ $d->id }}"><button type="button" class="min-w-0 flex-1 text-left text-sm" wire:click="showDelivery('{{ $d->id }}')"><span class="num">{{ $d->occurred_on }} · {{ $d->quantity_scaled === null ? 'Quantity not recorded' : ResultValue::format($d->quantity_scaled).' '.$detail->unit }}{{ $d->voided_at !== null ? ' · Voided' : '' }}</span><span class="mt-1 block truncate text-xs text-muted-foreground">{{ $d->value_minor === null ? 'Not valued' : $settings->currency.' '.FinanceAmount::format($d->value_minor) }}{{ $d->reference ? ' · '.$d->reference : '' }}</span></button>@if ($permissions['edit'] && $d->voided_at === null)<flux:dropdown><flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Delivery actions" /><flux:menu><flux:menu.item wire:click="openDelivery('{{ $detail->id }}', '{{ $d->id }}')">Correct</flux:menu.item><flux:menu.item variant="danger" wire:click="openVoidDelivery('{{ $d->id }}')">Void</flux:menu.item></flux:menu></flux:dropdown>
                        @endif
                    </li>@empty<li class="py-4 text-sm text-muted-foreground">No deliveries recorded.</li>
                        @endforelse
                    </ul>@if ($deliveries !== null)<x-work.compact-pagination :paginator="$deliveries" noun="deliveries" />
                    @endif
                    @if ($delivery !== null)<div class="space-y-4 rounded-lg border border-border p-4"><p class="whitespace-pre-line text-sm">{{ $delivery->valuation_basis }}</p><p class="whitespace-pre-line text-sm">{{ $delivery->notes }}</p><livewire:projects.record-evidence-panel :project-id="$projectId" entity="CONTRIBUTION_DELIVERY" :record-id="$delivery->id" :key="'delivery-proof-'.$delivery->id" /><livewire:projects.record-fields :project-id="$projectId" entity="CONTRIBUTION_DELIVERY" :record-id="$delivery->id" :key="'delivery-fields-'.$delivery->id" /></div>
                    @endif
                @endif
                @if (! empty($detail->history))<details class="text-sm"><summary class="w-fit">Revision history</summary><ul class="mt-3 space-y-2 text-xs text-muted-foreground">@foreach ($detail->history as $h)<li>{{ $h['at'] }} · {{ $h['reason'] }}</li>
                    @endforeach
                </ul></details>
                @endif
            </section>
        @endif
        @if ($periods->isNotEmpty())
            <section class="ppm-surface space-y-4 p-5"><h2 class="text-sm font-semibold">Period plan and paid expenses</h2><div class="grid gap-3 sm:grid-cols-2"><x-kit.select wire:model.live="periodChoice" label="Period" aria-label="Financial variance period"><flux:select.option value="">Choose a period</flux:select.option>@foreach ($periods as $p)<flux:select.option value="{{ $p->id }}">{{ $p->name }}</flux:select.option>
                @endforeach
            </x-kit.select><x-kit.select wire:model.live="baselineChoice" label="Approved baseline" aria-label="Variance baseline"><flux:select.option value="">{{ $budget === null ? 'No approved baseline' : 'Latest approved baseline' }}</flux:select.option>@foreach ($budgets as $b)<flux:select.option value="{{ $b->id }}">Version {{ $b->version }}</flux:select.option>
                @endforeach
            </x-kit.select></div>
                @if ($variance !== null)<dl class="grid gap-4 sm:grid-cols-3">@foreach (['Period plan' => $variance['planned_minor'], 'Paid through '.$variance['actual_through'] => $variance['paid_minor'], 'Paid minus plan' => $variance['variance_minor']] as $label => $value)<div><dt class="text-xs text-muted-foreground">{{ $label }}</dt><dd class="num mt-1 text-xl font-semibold">{{ $value === null ? 'Not allocated' : FinanceAmount::format($value) }}</dd></div>
                    @endforeach
                </dl>@if ($variance['unallocated_costs'] > 0)<p class="text-xs text-warning">{{ $variance['unallocated_costs'] }} baseline cost items have no reporting period.</p>
                    @endif
                @endif
            </section>
        @endif
        @include('livewire.projects.partials.funding-dialog')
    @endif
</div>
