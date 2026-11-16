{{--
    Leave filing (request settings): the payment basis with its set days,
    how a request's days are counted, the details of leave a request
    asks (`$detailsReady`: once the column exists), and who may use the
    type (`$eligibilityReady`, with `$statusOptions` and the consequence
    `$eligibilityReach`). The payment basis
    decides which other settings exist, so it comes first. The choices stand
    side by side as the card's own width allows (a container query), so the
    card reads the same as a column of the settings dialog and across the
    Add leave type dialog. Drawn by leave-type-settings-fields, which sets
    `$filing` and `$hasAllowance`.
--}}
@php
    use App\Modules\Timekeeping\Domain\Absence\LeaveApplication;
    use App\Modules\Timekeeping\Domain\Credits\LeaveEligibility;
    use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
@endphp
<div class="@container rounded-lg border border-border p-4" data-settings-filing>
    <fieldset class="space-y-3">
        <legend class="text-sm font-semibold">Leave Filing <span class="ml-1 text-xs font-normal text-muted-foreground">Request Settings</span></legend>
        <p class="-mt-2 text-xs text-muted-foreground">How requests are paid, counted and described. The payment basis decides which settings follow.</p>
        <div class="grid gap-4 @lg:grid-cols-2 @4xl:grid-cols-3">
            <div class="space-y-2">
                <x-kit.select label="Payment Basis" wire:model.live="typeForm.filing" data-leave-filing>
                    @foreach (LeaveTypeCredits::FILING_LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
                </x-kit.select>
                @if ($hasAllowance)
                    <flux:input label="{{ $filing === LeaveTypeCredits::FILING_YEARLY ? 'Annual Allowance (days)' : 'Allowance per Request (days)' }}" type="number" min="0.5" step="0.0001" wire:model="typeForm.allowance_days" data-leave-allowance />
                @endif
                <p class="text-xs text-muted-foreground">
                    @switch ($filing)
                        @case (LeaveTypeCredits::FILING_YEARLY)
                            Paid up to this many days per calendar year; extra days are unpaid. Unused days do not carry forward.
                            @break
                        @case (LeaveTypeCredits::FILING_OCCASION)
                            Paid up to this many days per request; extra days are unpaid.
                            @break
                        @case (LeaveTypeCredits::FILING_UNPAID)
                            Always approved as unpaid leave; uses no credits.
                            @break
                        @default
                            Paid from the available balance. Days it does not cover are unpaid, unless a negative balance is allowed. A short balance never blocks a request.
                    @endswitch
                </p>
            </div>
            <div class="space-y-2">
                <x-kit.select label="Count a request's days as" wire:model="typeForm.day_count">
                    @foreach (LeaveTypeCredits::DAY_COUNT_LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
                </x-kit.select>
                <p class="text-xs text-muted-foreground">Working days skip rest days and holidays; calendar days count every day.</p>
            </div>
            @if ($detailsReady ?? false)
                <div class="space-y-2">
                    <x-kit.select label="Details of Leave Asked" wire:model="typeForm.leave_details" data-leave-details-setting>
                        @foreach (LeaveApplication::SECTION_LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
                    </x-kit.select>
                    <p class="text-xs text-muted-foreground">The leave form's extra questions: where a vacation is spent, the illness (sick leave, special leave for women) or the study purpose. None asks nothing.</p>
                </div>
            @endif
        </div>
        @if ($eligibilityReady ?? false)
            {{--
                Who may use it: every employment status or the ticked ones,
                and anyone or one sex. The line under it is the choice's
                consequence today: how many active employees it reaches and
                whom it leaves out (`$eligibilityReach`, counts only).
            --}}
            <div class="space-y-3 border-t border-border pt-3" data-leave-eligibility-setting>
                <div class="grid gap-4 @lg:grid-cols-2 @4xl:grid-cols-3">
                    <x-kit.select label="Who May Use It" wire:model.live="typeForm.status_mode" data-leave-status-mode>
                        <flux:select.option value="{{ LeaveEligibility::STATUS_ALL }}">Every employment status</flux:select.option>
                        <flux:select.option value="{{ LeaveEligibility::STATUS_SOME }}">Only the chosen statuses</flux:select.option>
                    </x-kit.select>
                    <x-kit.select label="Only For" wire:model.live="typeForm.sex" data-leave-sex>
                        <flux:select.option value="">Anyone</flux:select.option>
                        @foreach (LeaveEligibility::SEX_LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
                    </x-kit.select>
                </div>
                @if (($typeForm['status_mode'] ?? LeaveEligibility::STATUS_ALL) === LeaveEligibility::STATUS_SOME)
                    <div class="grid gap-2 @lg:grid-cols-2 @4xl:grid-cols-3" data-leave-statuses>
                        @forelse ($statusOptions ?? [] as $status)
                            <flux:checkbox wire:model.live="typeForm.statuses.{{ $status['id'] }}" :label="$status['name']" wire:key="leave-status-{{ $status['id'] }}" />
                        @empty
                            <p class="text-xs text-muted-foreground">The Employment list is empty.</p>
                        @endforelse
                    </div>
                    @if (array_filter((array) ($typeForm['statuses'] ?? [])) === [])
                        <p class="text-xs text-muted-foreground" data-leave-statuses-none>Tick at least one employment status.</p>
                    @endif
                @endif
                @if (($eligibilityReach ?? null) !== null)
                    @php
                        $left = [];
                        foreach ($eligibilityReach['excluded'] as $label => $count) {
                            $left[] = $count.' '.$label;
                        }
                        if ($eligibilityReach['no_status'] > 0) {
                            $left[] = $eligibilityReach['no_status'].' with no employment status on record';
                        }
                        if ($eligibilityReach['no_sex'] > 0) {
                            $left[] = $eligibilityReach['no_sex'].' with no sex on record';
                        }
                    @endphp
                    <p class="text-xs text-muted-foreground" data-leave-eligibility-reach>
                        {{ $eligibilityReach['eligible'] }} active {{ $eligibilityReach['eligible'] === 1 ? 'employee' : 'employees' }} may use it{{ $left === [] ? '.' : '; left out: '.implode(', ', $left).'.' }}
                        Those left out cannot file it, earn it, have attendance deducted from it or convert it to cash; credits they already hold stay on their balance. A waiting period counts from the day an employee becomes eligible.
                    </p>
                @endif
            </div>
        @endif
    </fieldset>
</div>
