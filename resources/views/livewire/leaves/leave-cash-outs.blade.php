{{--
    Cash-outs — a tab of Leave Management with no counterpart in the source.
    One row per request to cash leave credits out that the actor may see,
    pending first; behind all_data + LEAVES the row menu approves or
    rejects through the review dialog. See App\Livewire\Leaves\LeaveCashOuts.
--}}
@php
    use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
    use App\Support\ManilaClock;

    $columns = $canManage ? 7 : 6;
    $pill = static fn (string $status): string => match ($status) {
        'PENDING' => 'pill pill-caution', 'APPROVED' => 'pill pill-primary', 'PAID' => 'pill pill-success-strong', default => 'pill pill-muted',
    };
@endphp
<div class="space-y-4" data-leave-cash-outs>
    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <flux:input aria-label="Search employees" placeholder="Search ID, name or position" class="w-full min-w-[12rem] max-w-xs flex-1" wire:model.live.debounce.250ms="search" />
            <x-kit.filter-select wire:model.live="status" label="Status" class="w-[11rem]" :options="['PENDING' => 'Pending', 'APPROVED' => 'Approved, unpaid', 'PAID' => 'Paid', 'REJECTED' => 'Rejected', 'WITHDRAWN' => 'Withdrawn', 'ALL' => 'All requests']" />
            <p class="text-xs text-muted-foreground">Requests to turn leave credits into pay. An approved request is paid on the employee's next regular payroll.</p>
        </header>

        @unless ($ready)
            <p class="px-5 py-6 text-sm text-muted-foreground">Cash-outs become available once this installation's upgrade finishes.</p>
        @else
            <div class="hris-table-wrap hris-table-scroll">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Leave type</th>
                            <th class="text-right">Days</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <th>Decided</th>
                            @if ($canManage)
                                <th class="w-10"><span class="sr-only">Actions</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="cash-out-{{ $row->id }}" data-cash-out="{{ $row->status }}">
                                <td class="font-medium">{{ $row->employee_name !== '' ? $row->employee_name : '—' }}</td>
                                <td>{{ $row->type_name }}</td>
                                <td class="num text-right">{{ LeaveQuantity::format($row->days) }}</td>
                                <td class="text-sm">
                                    <span class="num">{{ $row->requested_at->setTimezone(ManilaClock::TZ)->format('M d, Y') }}</span>
                                    @if ($row->note !== null)
                                        <p class="text-xs text-muted-foreground">{{ $row->note }}</p>
                                    @endif
                                </td>
                                <td><span class="{{ $pill($row->status) }}">{{ ucfirst(strtolower($row->status)) }}</span></td>
                                <td class="text-sm">
                                    @if ($row->reviewed_at !== null)
                                        {{ $row->reviewed_by_name ?? '—' }} · <span class="num">{{ $row->reviewed_at->setTimezone(ManilaClock::TZ)->format('M d, Y') }}</span>
                                        @if ($row->review_note !== null)
                                            <p class="text-xs text-muted-foreground">{{ $row->review_note }}</p>
                                        @endif
                                    @elseif ($row->amount !== null)
                                        <span class="num">₱{{ number_format($row->amount, 2) }}</span>
                                    @else
                                        <span class="text-muted-foreground">—</span>
                                    @endif
                                    @if ($row->status === 'PAID' && $row->amount !== null && $row->reviewed_at !== null)
                                        <p class="num text-xs text-muted-foreground">Paid ₱{{ number_format($row->amount, 2) }}</p>
                                    @endif
                                </td>
                                @if ($canManage)
                                    <td class="text-right">
                                        @if ($row->status === 'PENDING' && $row->employee_id !== $ownEmployeeId)
                                            <x-kit.row-actions :label="$row->employee_name">
                                                <flux:menu.item icon="check" wire:click="openReview('{{ $row->id }}', true)">Approve…</flux:menu.item>
                                                <flux:menu.item icon="x-mark" wire:click="openReview('{{ $row->id }}', false)">Reject…</flux:menu.item>
                                            </x-kit.row-actions>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr wire:key="empty"><x-kit.empty-state :colspan="$columns" title="No cash-out requests here." /></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-kit.pagination :paginator="$rows" noun="requests" />
        @endunless
    </section>

    @if ($canManage)
        <flux:modal wire:model.self="reviewOpen" class="w-full max-w-md" x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'cash-out-review-heading')">
            <div class="space-y-4" data-cash-out-review>
                <flux:heading size="lg" id="cash-out-review-heading">{{ $reviewApprove ? 'Approve cash-out' : 'Reject cash-out' }}</flux:heading>
                @if ($reviewing !== null)
                    <flux:text class="text-sm">{{ $reviewing->employee_name }} · {{ $reviewing->type_name }} · <span class="num">{{ LeaveQuantity::format($reviewing->days) }}</span> {{ $reviewing->days === 1.0 ? 'day' : 'days' }}@if ($reviewing->note !== null) · {{ $reviewing->note }}@endif</flux:text>
                    @if ($reviewApprove)
                        <flux:text class="text-sm text-muted-foreground">The credits stay on the ledger until the payroll that pays this posts; the days are checked again now against what is left.</flux:text>
                    @endif
                @endif
                <flux:textarea label="Note (optional)" wire:model="reviewNote" rows="2" maxlength="500" />
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('reviewOpen', false)">Cancel</flux:button>
                    <flux:button :variant="$reviewApprove ? 'primary' : 'danger'" icon="check" wire:click="confirmReview">{{ $reviewApprove ? 'Approve' : 'Reject' }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
