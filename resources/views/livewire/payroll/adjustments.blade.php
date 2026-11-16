{{--
    Adjustments — the ledger of differences paid or recovered on a later
    payroll: the list by status, the Add/Correct dialog, the review dialog
    (approve with the payroll it goes on, or reject with a note) and the
    discard confirmation. An all-data PAYROLL holder manages; a linked
    holder reads their own lines.
--}}
@php
    use App\Livewire\Records\Support\Hris;
    use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
    use App\Support\ManilaClock;

    $pill = static fn (string $status): string => match ($status) {
        AdjustmentRule::PENDING => 'pill pill-caution',
        AdjustmentRule::APPROVED => 'pill pill-primary',
        AdjustmentRule::POSTED => 'pill pill-success-strong',
        default => 'pill pill-muted',
    };
    $statusText = static fn (string $status): string => match ($status) {
        AdjustmentRule::PENDING => 'Awaiting approval',
        AdjustmentRule::APPROVED => 'Approved',
        AdjustmentRule::POSTED => 'Paid',
        default => 'Rejected',
    };
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Adjustments</h1>
        <p class="text-sm text-muted-foreground">
            Differences paid or recovered on a later payroll: a correction of salary, a refund, a tax shortfall, an overpayment to recover.
            A line is prepared and then approved by a payroll officer, and the next payroll with the employee on it pays it.
        </p>
    </div>

    <section class="hris-card overflow-hidden">
        <header class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-4">
            @unless ($ready)
                <p class="w-full text-xs text-muted-foreground">Adjustments can be prepared once this installation's upgrade finishes.</p>
            @endunless
            <flux:input
                aria-label="Search adjustments"
                placeholder="Search employee, category or reason"
                class="w-full min-w-[12rem] max-w-xs flex-1"
                wire:model.live.debounce.250ms="search"
            />
            <x-kit.filter-select label="Status" wire:model.live="status" class="w-[14rem]">
                @foreach ($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </x-kit.filter-select>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <x-kit.export-button :href="route('payroll.adjustments.export', array_filter(['q' => $search, 'status' => $status]))" />
                @if ($canManage && $ready)
                    <flux:button variant="primary" icon="plus" wire:click="openAdd">Add Adjustment</flux:button>
                @endif
            </div>
        </header>

        <div class="hris-table-wrap hris-table-scroll">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Category</th>
                        <th class="text-right">Amount</th>
                        <th>Reason</th>
                        <th>Payroll</th>
                        <th>Status</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($rows->isEmpty())
                        <tr wire:key="empty">
                            <x-kit.empty-state :colspan="7" title="No adjustment here." hint="Prepare one when a payslip was short or over; once approved, the next payroll pays it." />
                        </tr>
                    @endif
                    @foreach ($rows as $row)
                        <tr wire:key="adjustment-{{ $row->id }}">
                            <td>
                                <p class="font-medium">{{ $row->employee_name }}</p>
                                <p class="num text-xs text-muted-foreground">{{ $row->employee_code }}</p>
                            </td>
                            <td>
                                {{ $row->category_name }}
                                <span class="block text-xs text-muted-foreground">
                                    {{ $row->kind === AdjustmentRule::KIND_DEDUCTION ? 'Deduction' : ($row->tax === AdjustmentRule::TAX_TAXABLE ? 'Earning, taxable' : 'Earning, not compensation') }}
                                </span>
                            </td>
                            <td class="num whitespace-nowrap text-right">{{ $row->kind === AdjustmentRule::KIND_DEDUCTION ? '−' : '' }}{{ Hris::peso($row->amount) }}</td>
                            <td>
                                {{ $row->reason }}
                                @if ($row->reference)
                                    <span class="block text-xs text-muted-foreground">Ref. {{ $row->reference }}</span>
                                @endif
                                <span class="block text-xs text-muted-foreground">Prepared by {{ $row->prepared_by_name ?? 'an unnamed account' }} · <span class="num">{{ $row->created_at->setTimezone(ManilaClock::TZ)->format('M d, Y') }}</span></span>
                            </td>
                            <td class="whitespace-nowrap text-sm">
                                @if ($row->status === AdjustmentRule::POSTED)
                                    Paid on {{ $row->payslip_run_code ?? 'a payroll' }}
                                @elseif ($row->target_run_code !== null)
                                    {{ $row->target_run_code }}
                                @else
                                    The next payroll
                                @endif
                            </td>
                            <td>
                                <span class="{{ $pill($row->status) }}">{{ $statusText($row->status) }}</span>
                                @if ($row->status !== AdjustmentRule::PENDING)
                                    <span class="block text-xs text-muted-foreground">{{ $row->status === AdjustmentRule::REJECTED ? 'Rejected' : 'Approved' }} by {{ $row->approved_by_name ?? 'an unnamed account' }}@if ($row->review_note): {{ $row->review_note }}@endif</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($canManage && $row->status === AdjustmentRule::PENDING)
                                    <x-kit.row-actions :label="$row->employee_name.' adjustment'">
                                        <flux:menu.item icon="check" wire:click="openReview('{{ $row->id }}')">Review</flux:menu.item>
                                        <flux:menu.item icon="pencil-square" wire:click="startEdit('{{ $row->id }}')">Correct</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmDiscard('{{ $row->id }}')">Discard</flux:menu.item>
                                    </x-kit.row-actions>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <flux:modal
        wire:model.self="dialogOpen"
        class="w-full max-w-lg"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'adjustment-heading')"
    >
        <form class="grid gap-4" wire:submit="save">
            <flux:heading size="lg" id="adjustment-heading">{{ $editingId === null ? 'Add Adjustment' : 'Correct Adjustment' }}</flux:heading>
            <div class="space-y-2">
                <flux:label id="adjustment-employee-label">Employee</flux:label>
                @if ($editingId === null)
                    <livewire:kit.employee-picker name="adjustment-employee" :value="$formEmployeeId" labelled-by="adjustment-employee-label" :key="'adjustment-employee-'.$formEmployeeId" />
                @else
                    <p class="text-sm">The employee stays as prepared.</p>
                @endif
            </div>
            <x-kit.select label="Category" wire:model="formCategoryId">
                <flux:select.option value="">Choose one</flux:select.option>
                @foreach ($categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }} · {{ $category->kind === AdjustmentRule::KIND_DEDUCTION ? 'deduction' : ($category->tax === AdjustmentRule::TAX_TAXABLE ? 'taxable earning' : 'earning, not compensation') }}</flux:select.option>
                @endforeach
            </x-kit.select>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <flux:label for="adjustment-amount">Amount</flux:label>
                    <x-kit.money-input id="adjustment-amount" wire:model="formAmount" aria-label="Amount" />
                </div>
                <div class="space-y-2">
                    <flux:label for="adjustment-reference">Reference (optional)</flux:label>
                    <flux:input id="adjustment-reference" wire:model="formReference" maxlength="120" placeholder="A memo or voucher number" />
                </div>
            </div>
            <div class="space-y-2">
                <flux:label for="adjustment-reason">Reason</flux:label>
                <flux:input id="adjustment-reason" wire:model="formReason" maxlength="160" placeholder="What was short or over, and why" />
            </div>
            <x-kit.select label="Payroll" wire:model="formTargetRunId">
                <flux:select.option value="">The next payroll with the employee on it</flux:select.option>
                @foreach ($openRuns as $run)
                    <flux:select.option value="{{ $run['id'] }}">{{ $run['code'] }} · {{ $run['name'] }} ({{ $run['period'] }})</flux:select.option>
                @endforeach
            </x-kit.select>
            @if ($formError !== null)
                <p role="alert" class="text-sm text-danger">{{ $formError }}</p>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingId === null ? 'Prepare' : 'Save Correction' }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        wire:model.self="reviewOpen"
        class="w-full max-w-lg"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'adjustment-review-heading')"
    >
        <div class="grid gap-4">
            <flux:heading size="lg" id="adjustment-review-heading">Review Adjustment</flux:heading>
            @if ($reviewing !== null)
                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Employee</dt><dd>{{ $reviewing->employee_name }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Category</dt><dd>{{ $reviewing->category_name }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Amount</dt><dd class="num">{{ $reviewing->kind === AdjustmentRule::KIND_DEDUCTION ? '−' : '' }}{{ Hris::peso($reviewing->amount) }}</dd></div>
                    <div><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Prepared By</dt><dd>{{ $reviewing->prepared_by_name ?? 'an unnamed account' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Reason</dt><dd>{{ $reviewing->reason }}@if ($reviewing->reference) · Ref. {{ $reviewing->reference }}@endif</dd></div>
                </dl>
            @endif
            <x-kit.select label="Pay It On" wire:model="reviewTargetRunId">
                <flux:select.option value="">The next payroll with the employee on it</flux:select.option>
                @foreach ($openRuns as $run)
                    <flux:select.option value="{{ $run['id'] }}">{{ $run['code'] }} · {{ $run['name'] }} ({{ $run['period'] }})</flux:select.option>
                @endforeach
            </x-kit.select>
            <div class="space-y-2">
                <flux:label for="adjustment-note">Note (required to reject)</flux:label>
                <flux:input id="adjustment-note" wire:model="reviewNote" maxlength="160" placeholder="Why it is approved or rejected" />
            </div>
            @if ($reviewError !== null)
                <p role="alert" class="text-sm text-danger">{{ $reviewError }}</p>
            @endif
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" icon="x-mark" wire:click="reject">Reject</flux:button>
                <flux:button variant="primary" icon="check" wire:click="approve">Approve</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal
        wire:model.self="discardOpen"
        class="w-full max-w-md"
        x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'adjustment-discard-heading')"
    >
        <div class="space-y-4">
            <flux:heading size="lg" id="adjustment-discard-heading">Discard this adjustment?</flux:heading>
            <flux:text>{{ $discarding === null ? 'The pending line' : $discarding->employee_name.'\'s '.$discarding->category_name.' of '.Hris::peso($discarding->amount) }} is removed before review. A reviewed line stays on record.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="outline">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="discard">Discard</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
