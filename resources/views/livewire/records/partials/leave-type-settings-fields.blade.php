{{--
    A leave type's settings that apply as soon as they are saved, as four
    cards in the order a leave is lived with: how it is FILED
    (`leave-type-settings/filing`), how its balance is KEPT (`balance`),
    what is DEDUCTED from it (`deductions`) and how it is CONVERTED to cash
    (`cash-out`). The payment basis on the filing card decides the rest:
    only a type paid from earned leave credits holds credits, so only it has
    the other three.

    Bound to the page's `typeForm`. `$part` picks what is drawn:
     - left out: the Filing and credit settings dialog — two balanced
       columns from lg, Leave filing over Credit management on the left,
       Credit deductions over Cash conversion on the right (a type with no
       credits has the filing card alone);
     - 'filing' / 'credits': the Add leave type dialog, which asks the
       filing card first and the three credit cards after the leave rule —
       Credit management over Credit deductions beside Cash conversion — so
       the payment basis is chosen before anything it decides.
    Below lg every arrangement stacks in the same order: filing, balance,
    deductions, cash conversion. `$orderClash` is the page's warning for a
    priority another type with credit deductions already holds.
--}}
@php
    use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;

    $part = $part ?? 'all';
    $filing = (string) ($typeForm['filing'] ?? LeaveTypeCredits::FILING_CREDITS);
    $hasAllowance = in_array($filing, [LeaveTypeCredits::FILING_YEARLY, LeaveTypeCredits::FILING_OCCASION], true);
    $charged = (bool) ($typeForm['charge_late'] ?? false) || (bool) ($typeForm['charge_absence'] ?? false);
    $credits = $filing === LeaveTypeCredits::FILING_CREDITS;
@endphp
@if ($part === 'all' && $credits)
    <div class="grid gap-4 lg:grid-cols-2 lg:items-start" data-settings-columns>
        <div class="space-y-4">
            @include('livewire.records.partials.leave-type-settings.filing')
            @include('livewire.records.partials.leave-type-settings.balance')
        </div>
        <div class="space-y-4">
            @include('livewire.records.partials.leave-type-settings.deductions')
            @include('livewire.records.partials.leave-type-settings.cash-out')
        </div>
    </div>
@elseif ($part !== 'credits')
    @include('livewire.records.partials.leave-type-settings.filing')
@endif
@if ($part === 'credits' && $credits)
    <div class="grid gap-4 lg:grid-cols-2 lg:items-start" data-settings-columns>
        <div class="space-y-4">
            @include('livewire.records.partials.leave-type-settings.balance')
            @include('livewire.records.partials.leave-type-settings.deductions')
        </div>
        @include('livewire.records.partials.leave-type-settings.cash-out')
    </div>
@endif
