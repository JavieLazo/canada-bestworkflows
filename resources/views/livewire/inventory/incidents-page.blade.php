<div class="space-y-6">
    <div><h1 class="text-2xl font-bold tracking-tight">Condition and Loss</h1><p class="text-muted-foreground">Record damage, missing items and unserviceable inspections. Review does not release employee accountability.</p></div>
    @include('livewire.inventory.partials.tabs', ['activeInventoryTab' => 'incidents'])
    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input wire:model.live.debounce.250ms="search" placeholder="Search reference or details…" class="max-w-sm" />
            <x-kit.filter-select label="Report Status" wire:model.live="status">
                <flux:select.option value="ALL">All reports</flux:select.option>
                <flux:select.option value="OPEN">Open</flux:select.option>
                <flux:select.option value="RESOLVED">Reviewed</flux:select.option>
            </x-kit.filter-select>
            <div class="ml-auto flex flex-wrap items-center gap-2"><x-kit.export-button :href="route('inventory.incidents.export', array_filter(['search' => $search, 'status' => $status]))" /><flux:button variant="primary" wire:click="openAdd">Record Report</flux:button></div>
        </header>
        <div class="hris-table-wrap hris-table-scroll"><table class="hris-table"><thead><tr><th>Reference / Date</th><th>Item</th><th>Report</th><th>Custodian at Report</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse ($rows as $row)
                <tr wire:key="incident-{{ $row->id }}"><td><div class="font-medium">{{ $row->reference }}</div><div class="text-xs text-muted-foreground">{{ $row->reported_on->format('d M Y') }}</div></td><td><a class="text-primary underline" href="{{ route('inventory.items.show', $row->item_id) }}" wire:navigate>{{ $row->item_snapshot['code'] }} · {{ $row->item_snapshot['name'] }}</a></td><td>{{ str_replace('_', ' ', $row->kind) }}</td><td>{{ $row->employee_snapshot['name'] ?? 'In stock' }}</td><td><span class="{{ $row->status === 'OPEN' ? 'pill-caution' : 'pill-success' }}">{{ $row->status === 'OPEN' ? 'Open' : 'Reviewed' }}</span></td><td><flux:button size="sm" wire:click="openReview('{{ $row->id }}')">{{ $row->status === 'OPEN' ? 'Review' : 'View' }}</flux:button></td></tr>
            @empty<tr wire:key="empty"><x-kit.empty-state :colspan="6" title="No condition or loss reports." /></tr>@endforelse
        </tbody></table></div><x-kit.pagination :paginator="$rows" noun="reports" />
    </section>
    <flux:modal wire:model="dialogOpen" class="w-full max-w-xl">
        <form wire:submit="save" class="space-y-4"><flux:heading size="lg">Record Condition or Loss</flux:heading>
            <p class="text-sm text-muted-foreground">The report changes condition only. Any employee and MR assignment stays in place.</p>
            @include('livewire.inventory.partials.errors')
            @if ($formError)<p class="text-sm text-danger">{{ $formError }}</p>@endif
            @if ($contextItemId === null)
                <flux:input wire:model.live.debounce.250ms="itemSearch" label="Find Item" placeholder="Search code, description or serial…" />
                <x-kit.select wire:model.live="itemId" label="Item"><flux:select.option value="">Choose item</flux:select.option>@foreach ($items as $item)<flux:select.option value="{{ $item->id }}">{{ $item->code }} · {{ $item->name }}</flux:select.option>@endforeach</x-kit.select>
            @endif
            @if ($selectedItem)
                <section class="rounded-lg border border-border bg-muted/30 p-4 space-y-3" aria-label="Item being reported">
                    <div><p class="text-xs text-muted-foreground num">{{ $selectedItem->code }}</p><h3 class="font-semibold break-words">{{ $selectedItem->name }}</h3></div>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs text-muted-foreground">Current Holder</dt><dd class="mt-1 break-words">{{ $selectedItem->employee_name ?: 'No employee currently holds this item' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Current MR</dt><dd class="mt-1">@if ($selectedItem->current_receipt_id)<a class="num break-all text-foreground hover:underline" href="{{ route('inventory.receipts.show', ['id' => $selectedItem->current_receipt_id]) }}" wire:navigate>{{ $selectedItem->current_receipt_mr_number }}</a>@else — @endif</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Location</dt><dd class="mt-1 break-words">{{ $selectedItem->location_name ?: 'Not set' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Current Condition</dt><dd class="mt-1">{{ ucfirst(strtolower($selectedItem->condition)) }}</dd></div>
                    </dl>
                </section>
            @endif
            <x-kit.select wire:model="kind" label="Report Type"><flux:select.option value="DAMAGE">Damage</flux:select.option><flux:select.option value="LOSS">Loss / missing</flux:select.option><flux:select.option value="INSPECTION">Inspection — unserviceable</flux:select.option></x-kit.select>
            <flux:field><flux:label>Report Date</flux:label><x-kit.date-field wire:model="reportedOn" aria-label="Report date" /></flux:field>
            <flux:input wire:model="reference" label="Report Reference" maxlength="160" />
            <flux:textarea wire:model="details" label="Details and Evidence References" rows="4" maxlength="5000" />
            <div class="flex justify-end gap-2"><flux:button wire:click="$set('dialogOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Record Report</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="reviewOpen" class="w-full max-w-xl">
        @if ($review)
            <form wire:submit="resolve" class="space-y-4"><flux:heading size="lg">{{ $review->reference }}</flux:heading>
                <p class="text-sm whitespace-pre-wrap">{{ $review->details }}</p><p class="text-xs text-muted-foreground">Recorded by {{ $review->actor_name }}. Custody and MR history remain in place; this review does not determine financial liability.</p>
                @include('livewire.inventory.partials.errors')
                @if ($formError)<p class="text-sm text-danger">{{ $formError }}</p>@endif
                @if ($review->status === 'OPEN')
                    <p class="text-sm text-muted-foreground">Another authorized Inventory manager must review this report. The reporter and affected employee cannot review it.</p>
                    <x-kit.select wire:model="condition" label="Verified Condition"><flux:select.option value="SERVICEABLE">Serviceable</flux:select.option><flux:select.option value="DAMAGED">Damaged</flux:select.option><flux:select.option value="UNSERVICEABLE">Unserviceable</flux:select.option><flux:select.option value="MISSING">Missing</flux:select.option></x-kit.select>
                    <flux:input wire:model="resolutionReference" label="Review / Authorization Reference" maxlength="160" /><flux:textarea wire:model="resolution" label="Review Findings and Reason" rows="4" maxlength="5000" />
                    <div class="flex justify-end"><flux:button type="submit" variant="primary">Complete Review</flux:button></div>
                @else
                    <dl class="space-y-2 text-sm"><div><dt class="font-medium">Reviewed By</dt><dd>{{ $review->reviewer_name }}</dd></div><div><dt class="font-medium">Reference</dt><dd>{{ $review->resolution_reference }}</dd></div><div><dt class="font-medium">Findings</dt><dd class="whitespace-pre-wrap">{{ $review->resolution }}</dd></div></dl>
                @endif
            </form>
        @endif
    </flux:modal>
</div>
