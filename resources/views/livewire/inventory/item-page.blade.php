{{-- Item identity stays beside its current accountability and complete custody history. --}}
@php use App\Support\HrisFormat; @endphp
<div class="space-y-4" wire:poll.60s="pollForChanges">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground" href="{{ route('inventory.items') }}" wire:navigate>
            <flux:icon.arrow-left class="size-4" /> Inventory items
        </a>
        @if ($canManage)
            <div class="flex flex-wrap items-center gap-2">
                <flux:button variant="outline" size="sm" :href="route('inventory.incidents', ['item' => $item->id])" wire:navigate>Report condition or loss</flux:button>
                @if ($item->status === 'IN_STOCK')
                    <flux:button variant="primary" size="sm" icon="user-plus" wire:click="startMovement('issue', '{{ $item->id }}')">Issue item</flux:button>
                @elseif ($item->status === 'ISSUED')
                    <flux:button variant="outline" size="sm" icon="arrows-right-left" wire:click="startMovement('transfer', '{{ $item->id }}')">Transfer</flux:button>
                    <flux:button variant="outline" size="sm" icon="arrow-uturn-left" wire:click="startMovement('return', '{{ $item->id }}')">Return to stock</flux:button>
                @endif
            </div>
        @endif
    </div>

    <header>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight">Item <span class="num">{{ $item->code }}</span></h1>
            <span class="pill {{ $item->status === 'ISSUED' ? 'pill-primary' : 'pill-muted' }}">{{ ucwords(strtolower(str_replace('_', ' ', $item->status))) }}</span>
        </div>
        <p class="mt-1 break-words text-sm text-muted-foreground">{{ $item->name }}</p>
    </header>
    @include('livewire.inventory.partials.errors')

    <div class="grid items-start gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <section class="hris-card min-w-0 overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                <h2 class="text-base font-bold">Item details</h2>
                @if ($canManage)
                    <flux:button variant="ghost" size="sm" icon="pencil" wire:click="startEdit('{{ $item->id }}')">Edit item</flux:button>
                @endif
            </header>
            <dl class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-1">
                @foreach (['Item' => $item->name, 'Serial number' => $item->serial_no, 'Category' => $item->category_name, 'Location' => $item->location_name, 'Condition' => ucfirst(strtolower($item->condition)), 'Acquisition date' => HrisFormat::date($item->acquired_on), 'Acquisition cost' => '₱'.number_format($item->cost, 2)] as $label => $value)
                    <div class="min-w-0">
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
                        <dd class="mt-1 break-words text-sm {{ in_array($label, ['Serial number', 'Acquisition date', 'Acquisition cost'], true) ? 'num' : '' }}">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
                <div class="min-w-0 sm:col-span-2 xl:col-span-1">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Remarks</dt>
                    <dd class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $item->remarks ?: '—' }}</dd>
                </div>
            </dl>
        </section>

        <div class="min-w-0 space-y-4">
            <section class="hris-card overflow-hidden">
                <div class="p-5">
                    <h2 class="text-base font-bold">{{ $item->employee_name ? 'Issued to' : 'Current accountability' }}</h2>
                    @if ($item->employee_name)
                        <div class="mt-4 flex items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted" aria-hidden="true"><flux:icon.user class="size-4 text-muted-foreground" /></span>
                            <div class="min-w-0">
                                <p class="break-words font-medium">{{ $item->employee_name }}</p>
                                <p class="break-words text-xs text-muted-foreground">{{ $item->employee_position ?: '—' }}</p>
                            </div>
                        </div>
                        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Department</dt><dd class="mt-1 break-words text-sm">{{ $item->employee_department ?: '—' }}</dd></div>
                            <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Unit</dt><dd class="mt-1 break-words text-sm">{{ $item->employee_unit ?: '—' }}</dd></div>
                            <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Date issued</dt><dd class="num mt-1 text-sm">{{ HrisFormat::date($item->current_receipt_issued_on) }}</dd></div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Memorandum receipt</dt>
                                <dd class="mt-1 text-sm">
                                    @if ($item->current_receipt_id)
                                        <a class="num break-all text-foreground hover:underline" href="{{ route('inventory.receipts.show', ['id' => $item->current_receipt_id]) }}" wire:navigate>MR No. {{ $item->current_receipt_mr_number }}</a>
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    @else
                        <p class="mt-4 text-sm text-muted-foreground">No employee currently holds this item.</p>
                    @endif
                </div>
                <footer class="border-t border-border px-5 py-3 text-xs text-muted-foreground">Condition changes and physical-count findings do not erase accountability or receipt history.</footer>
            </section>

            <section class="hris-card overflow-hidden">
                <header class="border-b border-border px-5 py-4"><h2 class="text-base font-bold">Movement history</h2></header>
                <div class="hris-table-wrap"><table class="hris-table">
                    <thead><tr><th>Date</th><th>Movement</th><th>From</th><th>To</th><th>MR No.</th><th>Remarks</th></tr></thead>
                    <tbody>
                        @forelse ($movements as $movementRow)
                            <tr wire:key="movement-{{ $movementRow->id }}">
                                <td class="num whitespace-nowrap">{{ HrisFormat::date($movementRow->occurred_on) }}</td>
                                <td>{{ ucwords(strtolower(str_replace('_', ' ', $movementRow->event_type))) }}</td>
                                <td>{{ $movementRow->from_employee_name ?: 'Stock' }}</td>
                                <td>{{ $movementRow->to_employee_name ?: 'Stock' }}</td>
                                <td class="max-w-64 break-all">
                                    @if ($movementRow->receipt_id)
                                        <a class="num break-all text-foreground hover:underline" href="{{ route('inventory.receipts.show', ['id' => $movementRow->receipt_id]) }}" wire:navigate>MR No. {{ $movementRow->receipt_mr_number }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="whitespace-pre-wrap">{{ $movementRow->remarks ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr wire:key="empty-history"><x-kit.empty-state :colspan="6" title="No custody movements yet." hint="The item is available for its first issue." /></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </section>
        </div>
    </div>
    @if ($canManage)@include('livewire.inventory.partials.item-dialogs')@endif
</div>
