{{--
    Cash conversion, for a type paid from earned leave credits: whether
    employees may convert its credits, within its limits
    (LeaveCashOutLimits), and whether its balance is paid out on separation
    (`$cashOutLimitsReady`: once those columns exist). Drawn by
    leave-type-settings-fields.
--}}
<div class="rounded-lg border border-border p-4" data-settings-cash-out>
    <fieldset class="space-y-3">
        <legend class="text-sm font-semibold">Cash conversion <span class="ml-1 text-xs font-normal text-muted-foreground">Converting credits into pay</span></legend>
        <p class="-mt-2 text-xs text-muted-foreground">Converting credits into pay while employed, and paying out the rest at separation.</p>
        {{-- Live, so the limits appear with the tick. --}}
        <flux:checkbox wire:model.live="typeForm.can_cash_out" label="Allow cash conversion" description="Employees may request it, up to their available balance and within the limits below." />
        @if (($cashOutLimitsReady ?? false) && (bool) ($typeForm['can_cash_out'] ?? false))
            <div class="grid gap-3 sm:grid-cols-2" data-cash-out-limits>
                <flux:input label="Credits needed before converting (days)" type="number" min="0" step="0.0001" wire:model="typeForm.cash_out_min_balance" placeholder="No limit" />
                <flux:input label="Fewest days per request" type="number" min="0" step="0.5" wire:model="typeForm.cash_out_min_days" placeholder="No limit" />
                <flux:input label="Days that must remain" type="number" min="0" step="0.0001" wire:model="typeForm.cash_out_keep_days" placeholder="No limit" />
                <flux:input label="Most days a year" type="number" min="0" step="0.5" wire:model="typeForm.cash_out_max_year" placeholder="No limit" />
                <flux:input label="Requests a year" type="number" min="1" max="999" step="1" wire:model="typeForm.cash_out_per_year" placeholder="No limit" />
                <flux:input label="Reason needed from (% of the balance)" type="number" min="1" max="100" step="1" wire:model="typeForm.cash_out_reason_share" placeholder="Never" />
            </div>
            <p class="-mt-1 text-xs text-muted-foreground">Blank means no limit. Limits read the available balance (credits less days already requested). A request at or above the reason share must say why, and may go past the most days a year.</p>
        @endif
        @if ($cashOutLimitsReady ?? false)
            <flux:checkbox wire:model="typeForm.paid_on_separation" label="Pay out on separation" description="When an employee leaves, HR can file terminal leave for the remaining balance, priced at the highest salary on record." data-paid-on-separation />
        @endif
    </fieldset>
</div>
