{{-- Proposals preserve official values until an independent reviewer approves them. --}}
@php
    use App\Livewire\Employees\ChangeRequests;
    use App\Support\ManilaClock;
    $pill = static fn (string $status): string => match ($status) {
        'APPROVED' => 'pill-success',
        'REJECTED' => 'pill-danger',
        'PENDING' => 'pill-caution',
        default => 'pill-muted',
    };
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ $own ? 'My change requests' : 'Record change requests' }}</h1>
            <p class="text-sm text-muted-foreground">{{ $own ? 'Track your proposed changes and the decisions made on them.' : 'Review employee corrections before they become part of the official record.' }}</p>
        </div>
    </div>
    @if (! $own)
        @include('livewire.employees.partials.view-switch', ['activeView' => 'requests'])
    @endif
    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            <x-kit.filter-select label="Filter by status" class="w-[13rem]" wire:model.live="status">
                @foreach (['ALL' => 'All requests', 'PENDING' => 'Pending', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected', 'WITHDRAWN' => 'Withdrawn'] as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>
        </header>
        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead><tr><th>Employee</th><th>Section</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr wire:key="record-request-{{ $row->id }}">
                            <td><span class="block font-medium">{{ $row->employee_name }}</span><span class="block text-xs text-muted-foreground">Submitted by {{ $row->requested_by_name }}</span></td>
                            <td>{{ ChangeRequests::sectionLabel($row) }}<span class="block text-xs text-muted-foreground">{{ ['CREATE' => 'Add', 'UPDATE' => 'Update', 'DELETE' => 'Remove'][$row->operation] ?? $row->operation }}</span></td>
                            <td class="num whitespace-nowrap">{{ $row->created_at->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') }}</td>
                            <td><span class="pill {{ $pill($row->status) }}">{{ ucfirst(strtolower($row->status)) }}</span></td>
                            <td><flux:button variant="ghost" size="sm" wire:click="show('{{ $row->id }}')">View</flux:button></td>
                        </tr>
                    @empty
                        <tr><x-kit.empty-state :colspan="5" title="No change requests here." /></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-kit.pagination :paginator="$rows" noun="requests" />
    </section>

    <flux:modal wire:model.self="detailOpen" class="w-full max-w-3xl">
        @if ($open !== null)
            <div class="space-y-5" x-init="$el.closest('dialog')?.setAttribute('aria-labelledby', 'record-request-heading')">
                <div class="space-y-2">
                    <flux:heading size="lg" id="record-request-heading">{{ ChangeRequests::sectionLabel($open) }} — {{ $open->employee_name }}</flux:heading>
                    <span class="pill {{ $pill($open->status) }}">{{ ucfirst(strtolower($open->status)) }}</span>
                    <p class="text-sm text-muted-foreground">Submitted by {{ $open->requested_by_name }} · {{ $open->created_at->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') }}</p>
                </div>
                @if (trim((string) $open->reason) !== '')
                    <div><h3 class="text-sm font-semibold">Reason for change</h3><p class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $open->reason }}</p></div>
                @endif
                <div class="space-y-3">
                    @foreach ($open->changes as $change)
                        <div class="grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-2" wire:key="request-field-{{ $open->id }}-{{ $loop->index }}">
                            <h3 class="text-sm font-semibold sm:col-span-2">{{ $change['label'] }}</h3>
                            <div><p class="text-xs font-medium text-muted-foreground">When submitted</p><p class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $change['before'] ?? 'Not recorded' }}</p></div>
                            <div><p class="text-xs font-medium text-muted-foreground">Requested</p><p class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $change['after'] ?? 'Not recorded' }}</p></div>
                        </div>
                    @endforeach
                </div>
                @if ($open->has_before_evidence || $open->has_after_evidence)
                    <div class="flex flex-wrap gap-2">
                        @if ($open->has_before_evidence)
                            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('record-changes.evidence', ['request' => $open->id, 'which' => 'before'])">Previous file</flux:button>
                        @endif
                        @if ($open->has_after_evidence)
                            <flux:button size="sm" variant="outline" icon="arrow-down-tray" :href="route('record-changes.evidence', ['request' => $open->id, 'which' => 'after'])">Proposed file</flux:button>
                        @endif
                    </div>
                @endif
                @if ($open->reviewed_at !== null)
                    <div class="rounded-lg bg-muted p-3 text-sm">
                        <p class="font-medium">Reviewed by {{ $open->reviewed_by_name ?? 'Employee Records manager' }} · {{ $open->reviewed_at->setTimezone(ManilaClock::TZ)->format('M d, Y, g:i A') }}</p>
                        @if ($open->review_note)<p class="mt-1 whitespace-pre-wrap break-words">{{ $open->review_note }}</p>@endif
                    </div>
                @endif
                @if (! $own && $open->can_review)
                    <flux:textarea wire:model="reviewNote" label="Review note" description="A reason is required when rejecting a request." maxlength="1000" rows="3" />
                    <p class="text-xs text-muted-foreground">Approval applies this request and records the change in Employment History. If its original values have changed, a fresh request is required.</p>
                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:button variant="outline" wire:click="review(false)">Reject</flux:button>
                        <flux:button variant="primary" wire:click="review(true)">Approve changes</flux:button>
                    </div>
                @elseif ($own && $open->can_withdraw)
                    <div class="flex justify-end"><flux:button variant="outline" wire:click="withdraw">Withdraw request</flux:button></div>
                @endif
                <div class="flex justify-end"><flux:modal.close><flux:button variant="ghost">Close</flux:button></flux:modal.close></div>
            </div>
        @else
            <p class="text-sm text-muted-foreground">This request is unavailable.</p>
        @endif
    </flux:modal>
</div>
