{{-- Overtime is a separate decision: opening or saving this dialog never
     submits punch corrections. The same scoped day view supplies the identity,
     evidence, ceiling and approval permission. Keep bg-inherit from the modal
     through its body so the sticky footer stays opaque. --}}
@use('App\Livewire\Edtr\Support\Hris')
@use('App\Domain\Attendance\Shift', 'DomainShift')
@use('App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy')
@use('App\Support\HrisFormat')
@use('App\Domain\Attendance\CalendarDays')

<flux:modal
    wire:model.self="overtimeOpen"
    class="w-full max-w-lg scroll-pb-32"
    x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'overtime-heading')"
>
    @if ($overtimeDate !== null && $overtimeReview?->overtime !== null)
        @php($ot = $overtimeReview->overtime)
        @php($onDate = $overtimeReview->shift)
        <div
            wire:key="overtime-body-{{ $overtimeDate }}"
            class="flex flex-col gap-4 bg-inherit"
            data-overtime-decision
            data-ot-status="{{ $ot->status }}"
        >
            <div class="space-y-2 border-b border-border pb-4">
                <flux:heading size="lg" level="2" id="overtime-heading" class="pe-8">Overtime approval</flux:heading>
                <p class="font-medium">{{ $overtimeReview->employee_name }}</p>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-muted-foreground">{{ HrisFormat::weekday(CalendarDays::dayOfWeek($overtimeDate)) }}, {{ Hris::fmtDate($overtimeDate) }}</span>
                    <span @class([
                        'pill',
                        'pill-success' => $ot->status === OvertimeApprovalPolicy::APPROVED,
                        'pill-danger' => $ot->status === OvertimeApprovalPolicy::REJECTED,
                        'pill-muted' => $ot->status === OvertimeApprovalPolicy::PENDING,
                        'pill-caution' => in_array($ot->status, [OvertimeApprovalPolicy::PARTIAL, OvertimeApprovalPolicy::OUTDATED], true),
                    ])>{{ $ot->status === OvertimeApprovalPolicy::PENDING ? 'Not approved' : ucfirst(strtolower($ot->status)) }}</span>
                </div>
            </div>

            <dl class="grid grid-cols-[6rem_minmax(0,1fr)] items-baseline gap-x-3 gap-y-2 text-sm">
                <dt class="text-muted-foreground">Computed</dt>
                <dd class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span class="num font-semibold">{{ Hris::fmtDuration($ot->computed_minutes) }}</span>
                    {{-- The evidence: a wrong punch-out reads as overtime. --}}
                    @if ($overtimeReview->day?->timeOut !== null)
                        <span class="text-muted-foreground">Final Time Out <span class="num text-foreground">{{ Hris::fmtTime($overtimeReview->day->timeOut) }}</span>{{ Hris::isNextDay($overtimeReview->day->timeOut, $overtimeDate) ? ' next day' : '' }}</span>
                        @if ($onDate->mode !== DomainShift::MODE_FLEXIBLE)
                            <span class="text-muted-foreground">· shift ends <span class="num text-foreground">{{ Hris::fmtHm($onDate->end_time) }}</span></span>
                        @endif
                    @endif
                    <button
                        type="button"
                        class="inline-flex min-h-6 items-center text-sm font-medium text-primary hover:underline"
                        wire:click="openCorrection('{{ $overtimeDate }}')"
                    >Correct the punches</button>
                </dd>

                @if ($ot->ceiling < $ot->computed_minutes)
                    <dt class="text-muted-foreground">Approvable</dt>
                    <dd>Up to <span class="num">{{ Hris::fmtDuration($ot->ceiling) }}</span> <span class="text-muted-foreground">— the daily overtime cap set in Pay Basis &amp; Rates</span></dd>
                @endif

                <dt class="text-muted-foreground">Decision</dt>
                <dd class="min-w-0">
                    @if ($ot->decision === null)
                        <span class="text-muted-foreground">Not approved — excluded from payroll</span>
                    @else
                        <span class="font-medium">{{ $ot->decision === OvertimeApprovalPolicy::APPROVED ? 'Approved '.Hris::fmtDuration((float) $ot->approved_minutes) : 'Rejected' }}</span>
                        <span class="block text-xs text-muted-foreground">{{ $ot->reviewer_name ?? 'Unknown reviewer' }} · {{ $ot->decided_at }}</span>
                    @endif
                </dd>

                {{-- A decider edits the stored note in the Note box below. --}}
                @if (! $overtimeReview->can_decide_overtime && $ot->note !== null && $ot->note !== '')
                    <dt class="text-muted-foreground">Note</dt>
                    <dd class="min-w-0 break-words">{{ $ot->note }}</dd>
                @endif
            </dl>

            @if ($ot->status === OvertimeApprovalPolicy::OUTDATED)
                <div role="status" class="flex gap-2 rounded-md border border-warning/20 bg-warning-soft px-3 py-2 text-sm text-foreground">
                    <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-4 shrink-0 text-warning" />
                    <p>The day's punches changed after this decision. Decide it again so the pay matches the record.</p>
                </div>
            @endif

            @if ($overtimeReview->can_decide_overtime)
                <div class="grid items-start gap-4">
                    <div class="flex flex-col gap-1.5">
                        <flux:input
                            id="overtime-minutes"
                            type="number"
                            min="1"
                            max="{{ $ot->ceiling }}"
                            step="1"
                            inputmode="numeric"
                            label="Minutes to approve"
                            aria-describedby="overtime-ceiling"
                            wire:model="overtimeForm.minutes"
                        />
                        <p id="overtime-ceiling" class="text-xs text-muted-foreground">
                            Up to <span class="num">{{ $ot->ceiling }}</span> min{{ $ot->ceiling >= 60 ? ' ('.Hris::fmtDuration($ot->ceiling).')' : '' }}
                        </p>
                    </div>
                    <flux:input label="Note (optional)" maxlength="500" wire:model="overtimeForm.note" />
                </div>
            @endif

            {{-- Decision controls follow the freshly resolved EDTR permission. --}}
            <div class="sticky -bottom-6 z-10 -mx-6 -mb-6 mt-2 flex flex-col gap-2 border-t border-border bg-inherit px-6 py-3">
                @if ($overtimeError)
                    <p role="alert" class="text-sm text-danger">{{ $overtimeError }}</p>
                @endif
                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if ($overtimeReview->can_decide_overtime)
                        <flux:button type="button" variant="outline" wire:click="$set('overtimeOpen', false)">Cancel</flux:button>
                        <flux:button
                            type="button"
                            variant="outline"
                            wire:click="rejectOvertime"
                            wire:loading.attr="disabled"
                            wire:target="approveOvertime,rejectOvertime"
                        >Reject<span class="max-sm:sr-only"> overtime</span></flux:button>
                        <flux:button
                            type="button"
                            variant="primary"
                            wire:click="approveOvertime"
                            wire:loading.attr="disabled"
                            wire:target="approveOvertime,rejectOvertime"
                        >Approve<span class="max-sm:sr-only"> overtime</span></flux:button>
                    @else
                        <flux:button type="button" variant="outline" wire:click="$set('overtimeOpen', false)">Close</flux:button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</flux:modal>
