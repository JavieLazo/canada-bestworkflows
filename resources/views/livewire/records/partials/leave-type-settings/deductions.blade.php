{{--
    Credit deductions (payroll settings), for a type paid from earned leave
    credits: what attendance deducts from its credits and, once something
    does, its deduction priority (the `charge_order` the payroll charges
    in). `$orderClash` is the page's warning for a priority another leave
    type with credit deductions already holds (SaveLeaveTypeCredits refuses
    the save itself). Drawn by leave-type-settings-fields, which sets
    `$charged`.
--}}
<div class="rounded-lg border border-border p-4" data-settings-charging>
    <fieldset class="space-y-3">
        <legend class="text-sm font-semibold">Credit Deductions <span class="ml-1 text-xs font-normal text-muted-foreground">Payroll Settings</span></legend>
        <p class="-mt-2 text-xs text-muted-foreground">Deduct attendance from these credits before salary; whatever the credits do not cover comes off salary.</p>
        {{-- Live, so the priority appears with the first tick and its warning follows what is ticked and typed. --}}
        <flux:checkbox wire:model.live="typeForm.charge_late" label="Deduct Late and Undertime" description="Minutes are converted to days by the conversion table." />
        <flux:checkbox wire:model.live="typeForm.charge_absence" label="Deduct Absences" />
        {{-- Only a type with credit deductions has a priority; the draft keeps its number while nothing is ticked. --}}
        @if ($charged)
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input label="Deduction Priority" type="number" min="0" max="999" step="1" wire:model.live.debounce.300ms="typeForm.charge_order" />
            </div>
            <p class="-mt-1 text-xs text-muted-foreground">The lowest number is deducted first; each leave type with deductions needs its own number.</p>
            @if ($orderClash !== null)
                <p class="text-sm text-danger" role="alert" data-charge-order-warning>{{ $orderClash }}</p>
            @endif
        @endif
    </fieldset>
</div>
