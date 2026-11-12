{{--
    Credit management (balance settings), for a type paid from earned
    leave credits: the credit unit, the year-end treatment and the maximum
    negative balance — one limit for credit deductions and for leave
    approved with pay alike. Drawn by leave-type-settings-fields.
--}}
@php
    use App\Modules\Timekeeping\Domain\Credits\LeaveTypeCredits;
@endphp
<div class="rounded-lg border border-border p-4" data-settings-balance>
    <fieldset class="space-y-3">
        <legend class="text-sm font-semibold">Credit management <span class="ml-1 text-xs font-normal text-muted-foreground">Balance settings</span></legend>
        <p class="-mt-2 text-xs text-muted-foreground">How credits are counted, carried over and allowed to go negative.</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <x-kit.select label="Credit unit" wire:model="typeForm.unit">
                @foreach (LeaveTypeCredits::UNIT_LABELS as $unit => $label)<flux:select.option value="{{ $unit }}">{{ $label }}</flux:select.option>@endforeach
            </x-kit.select>
            <x-kit.select label="Year-end credit treatment" wire:model="typeForm.year_end">
                @foreach (LeaveTypeCredits::YEAR_END_LABELS as $value => $label)<flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>@endforeach
            </x-kit.select>
        </div>
        <p class="-mt-1 text-xs text-muted-foreground">Expiring drops any balance above zero at year end; a negative balance always carries forward.</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <flux:input label="Maximum negative balance" type="number" min="0" step="0.0001" wire:model="typeForm.below_zero_limit" />
        </div>
        <p class="-mt-1 text-xs text-muted-foreground">How far credits may go below zero, for deductions and for leave approved with pay; later credits repay it. 0 keeps the balance at zero or above.</p>
    </fieldset>
</div>
