@php
    use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
@endphp
@if ($cashFrom !== '' || $cashTo !== '' || $cashCategory !== '')
    <div class="flex flex-wrap items-center gap-3 rounded-lg bg-muted px-4 py-3 text-xs"><span class="num">{{ $cashFrom !== '' ? $cashFrom.' – '.$cashTo : '' }}{{ $cashCategory !== '' ? $categories->firstWhere('id', $cashCategory)?->name : '' }}</span><flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearCashFilters">Clear selection</flux:button></div>
@endif
<div class="ppm-collection-toolbar" aria-label="Cash ledger controls">
    <flux:input size="sm" wire:model.live.debounce.250ms="search" icon="magnifying-glass" placeholder="Find a cash entry…" aria-label="Search cash entries" class="ppm-search" />
    <div class="ppm-actions">
    <x-work.filters id="cash-filters" label="Cash ledger filters" :count="($kind === 'ALL' ? 0 : 1) + ($showVoided ? 1 : 0) + ($cashFrom === '' && $cashTo === '' ? 0 : 1) + ($cashCategory === '' ? 0 : 1)">
        <x-kit.select wire:model.live="kind" label="Type" aria-label="Cash entry type"><flux:select.option value="ALL">All entries</flux:select.option><flux:select.option value="RECEIPT">Cash received</flux:select.option><flux:select.option value="EXPENSE">Paid expenses</flux:select.option></x-kit.select>
        <x-kit.select wire:model.live="cashCategory" label="Category" aria-label="Cash category"><flux:select.option value="">All categories</flux:select.option>@foreach ($categories as $category)<flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
        @endforeach</x-kit.select>
        <div class="grid grid-cols-2 gap-3"><div class="space-y-2"><flux:label>From</flux:label><x-kit.date-field wire:model.live="cashFrom" aria-label="Cash from date" /></div><div class="space-y-2"><flux:label>Through</flux:label><x-kit.date-field wire:model.live="cashTo" aria-label="Cash through date" /></div></div>
        <flux:checkbox wire:model.live="showVoided" label="Include voided" />
    </x-work.filters>
    </div>
</div>
<div class="ppm-surface overflow-x-auto"><table class="hris-table ppm-table w-full">
    <thead><tr><x-kit.sort-header field="date" label="Date" /><x-kit.sort-header field="name" label="Entry" /><th>Category</th><x-kit.sort-header field="reference" label="Reference" /><x-kit.sort-header field="amount" align="right">Amount ({{ $settings->currency }})</x-kit.sort-header>@if ($permissions['edit'])<th><span class="sr-only">Actions</span></th>
    @endif</tr></thead>
    <tbody>
        @forelse ($entries as $entry)
            <tr wire:key="cash-entry-{{ $entry->id }}" class="{{ $entry->voided_at !== null ? 'text-muted-foreground' : '' }}"><td class="num whitespace-nowrap">{{ $entry->occurred_on->format('M j, Y') }}</td><td><button type="button" class="block max-w-72 truncate text-left font-medium hover:text-primary focus-visible:outline-2 focus-visible:outline-primary" wire:click="openEntryDetails('{{ $entry->id }}')" title="{{ $entry->description }}">{{ $entry->description }}</button><div class="mt-1 flex flex-wrap items-center gap-2"><span class="text-xs text-muted-foreground">{{ $entry->kind === 'RECEIPT' ? 'Cash received' : 'Paid expense' }}</span>@if ($entry->voided_at !== null)<span class="pill pill-muted">Voided</span>
                @elseif ($entry->replaces_id !== null)<span class="pill pill-muted">Correction</span>
                @endif</div></td><td>{{ $entry->category_label }}</td><td class="max-w-48 truncate" title="{{ $entry->reference }}">{{ $entry->reference ?? '—' }}</td><td class="num text-right font-medium {{ $entry->kind === 'RECEIPT' && $entry->voided_at === null ? 'text-success' : '' }}">{{ FinanceAmount::format($entry->amount_minor) }}</td>
                @if ($permissions['edit'])<td>@if ($entry->voided_at === null)<flux:dropdown position="bottom" align="end"><flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" aria-label="Actions for cash entry {{ $entry->description }}" /><flux:menu><flux:menu.item icon="pencil-square" wire:click="openEntry('{{ $entry->id }}')">Correct</flux:menu.item><flux:menu.item variant="danger" icon="x-mark" wire:click="openVoid('{{ $entry->id }}')">Void</flux:menu.item></flux:menu></flux:dropdown>
                @endif</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="6" class="py-10 text-center text-muted-foreground">{{ $search === '' && $kind === 'ALL' ? 'No cash entries recorded yet.' : 'No cash entries match these filters.' }}</td></tr>
        @endforelse
    </tbody>
</table></div>
<x-kit.pagination :paginator="$entries" noun="cash entries" class="!border-0 !px-0" />
