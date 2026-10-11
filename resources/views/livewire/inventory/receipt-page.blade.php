{{-- Frozen recipient details stay beside the original items and signed receipt evidence. --}}
@php use App\Support\HrisFormat; @endphp
<div class="space-y-4" wire:poll.60s="pollForChanges">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground" href="{{ route('inventory.receipts') }}" wire:navigate><flux:icon.arrow-left class="size-4" /> Memorandum receipts</a>
        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="outline" size="sm" icon="arrow-up-tray" href="#signed-mr">Signed MR</flux:button>
            <flux:button variant="outline" size="sm" icon="printer" :href="route('inventory.receipts.print', ['id' => $receipt->id])" target="_blank">Print MR</flux:button>
            @if ($canManage && $receipt->status === 'OPEN')
                <flux:button variant="outline" size="sm" wire:click="openCorrection">Correct receipt</flux:button>
            @endif
        </div>
    </div>
    <header class="flex flex-wrap items-center gap-3">
        <h1 class="min-w-0 break-all text-2xl font-bold tracking-tight">Memorandum Receipt <span class="num">{{ $receipt->mr_number }}</span></h1>
        <span class="pill {{ $receipt->status === 'OPEN' ? 'pill-primary' : 'pill-muted' }}">{{ ucfirst(strtolower($receipt->status)) }}</span>
    </header>
    @include('livewire.inventory.partials.errors')

    <div class="grid items-start gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <section class="hris-card min-w-0 overflow-hidden">
            <div class="p-5">
                <h2 class="text-base font-bold">Issued to</h2>
                <div class="mt-4 flex items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted" aria-hidden="true"><flux:icon.user class="size-4 text-muted-foreground" /></span>
                    <div class="min-w-0">
                        <p class="break-words font-medium">{{ $receipt->employee_name }}</p>
                        <p class="break-words text-xs text-muted-foreground">{{ $receipt->employee_snapshot['position'] ?? '—' }}</p>
                    </div>
                </div>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Department at issue</dt><dd class="mt-1 break-words text-sm">{{ $receipt->employee_snapshot['department'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Unit at issue</dt><dd class="mt-1 break-words text-sm">{{ $receipt->employee_snapshot['unit'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Date issued</dt><dd class="num mt-1 text-sm">{{ HrisFormat::date($receipt->issued_on) }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Issued by</dt><dd class="mt-1 break-words text-sm">{{ $receipt->issuer_name }}</dd><dd class="break-words text-xs text-muted-foreground">{{ $receipt->issuer_snapshot['position'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Recorded by</dt><dd class="mt-1 break-words text-sm">{{ $receipt->recorder_name }}</dd><dd class="text-xs text-muted-foreground">Signed-in account · audit attribution</dd></div>
                    <div class="min-w-0 sm:col-span-2 xl:col-span-1"><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Remarks</dt><dd class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $receipt->remarks ?: '—' }}</dd></div>
                </dl>
            </div>
            <footer class="border-t border-border px-5 py-3 text-xs text-muted-foreground">Reprints retain the office, employee placement, issuer and item details captured at issue.</footer>
        </section>

        <div class="min-w-0 space-y-4">
            @if ($receipt->corrected_from_id || $receipt->replacement_id)
                <section class="hris-card space-y-2 p-5 text-sm" aria-label="Receipt correction history">
                    @if ($receipt->corrected_from_id)
                        <p>This replaces <a class="break-all text-foreground hover:underline" href="{{ route('inventory.receipts.show', ['id' => $receipt->corrected_from_id]) }}" wire:navigate>the original MR</a>. Both records remain available.</p>
                    @endif
                    @if ($receipt->replacement_id)
                        <p>This receipt was corrected. <a class="break-all text-foreground hover:underline" href="{{ route('inventory.receipts.show', ['id' => $receipt->replacement_id]) }}" wire:navigate>Open the replacement MR No. {{ $receipt->corrected_to_mr_number }}</a>.</p>
                    @endif
                </section>
            @endif

            <section class="hris-card overflow-hidden">
                <header class="border-b border-border px-5 py-4"><h2 class="text-base font-bold">Items on this receipt</h2><p class="text-sm text-muted-foreground">Original items stay on the printed MR. Status shows what happened afterward.</p></header>
                <div class="hris-table-wrap"><table class="hris-table">
                    <thead><tr><th>Item code</th><th>Item at issue</th><th class="text-right">Cost</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($receipt->lines as $line)
                            <tr wire:key="line-{{ $line->id }}">
                                <td class="num whitespace-nowrap align-top"><a class="text-primary hover:underline" href="{{ route('inventory.items.show', ['id' => $line->item_id]) }}" wire:navigate>{{ $line->item_snapshot['code'] }}</a></td>
                                <td class="align-top">
                                    <p class="font-medium">{{ $line->item_snapshot['name'] }}</p>
                                    <p class="num text-xs text-muted-foreground">{{ $line->item_snapshot['serial_no'] ?: 'No serial number' }}</p>
                                    <p class="mt-1 text-xs text-muted-foreground">Acquired <span class="num">{{ HrisFormat::date($line->item_snapshot['acquired_on']) }}</span></p>
                                </td>
                                <td class="num whitespace-nowrap text-right align-top">₱{{ number_format((float) $line->item_snapshot['cost'], 2) }}</td>
                                <td class="whitespace-nowrap align-top">
                                    <span class="pill {{ $line->status === 'ISSUED' ? 'pill-primary' : 'pill-muted' }}">{{ ucfirst(strtolower($line->status)) }}</span>
                                    @if ($line->ended_on)<p class="num mt-1 text-xs text-muted-foreground">{{ HrisFormat::date($line->ended_on) }}</p>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3 text-xs text-muted-foreground"><span><span class="num">{{ $receipt->lines->count() }}</span> {{ $receipt->lines->count() === 1 ? 'item' : 'items' }}</span><span>Total cost <span class="num">₱{{ number_format($receipt->total_cost, 2) }}</span></span></footer>
            </section>

            <div id="signed-mr" class="scroll-mt-24">
                <livewire:inventory.signed-receipt :receipt-id="$receipt->id" :key="'signed-'.$receipt->id" />
            </div>
        </div>
    </div>

    <flux:modal wire:model.self="correctionDialog" class="w-full max-w-xl">
        <form wire:submit="correctReceipt" class="space-y-5">
            <div><flux:heading size="lg">Correct memorandum receipt</flux:heading><flux:text>A correction issues a linked replacement and retains the original. All original items must still be held under this receipt.</flux:text></div>
            @include('livewire.inventory.partials.errors')
            <div class="space-y-2"><flux:label id="correction-recipient-label">Received by</flux:label><livewire:kit.employee-picker :value="$employeeId" name="correction-recipient" labelled-by="correction-recipient-label" :key="'correction-recipient-'.$employeeId" /></div>
            <div class="space-y-2"><flux:label id="correction-issuer-label">Issued by</flux:label><livewire:kit.employee-picker :value="$issuerId" name="correction-issuer" labelled-by="correction-issuer-label" :key="'correction-issuer-'.$issuerId" /></div>
            <div class="space-y-2"><flux:label>Correct issue date</flux:label><x-kit.date-field wire:model="issuedOn" aria-label="Correct issue date" /></div>
            <flux:input label="Replacement MR No." wire:model="mrNumber" maxlength="80" required />
            <flux:textarea label="Reason for correction" wire:model="reason" rows="3" required />
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button variant="outline">Cancel</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Issue corrected MR</flux:button></div>
        </form>
    </flux:modal>
</div>
