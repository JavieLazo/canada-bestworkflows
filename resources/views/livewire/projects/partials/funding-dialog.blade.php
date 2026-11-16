<flux:modal wire:model.self="editorOpen" class="w-full max-w-xl"><form wire:submit="save" class="space-y-5"><flux:heading size="lg" level="2">{{ match ($editingKind) { 'funding' => $editingId ? 'Edit funding' : 'New funding', 'contribution' => $editingId ? 'Edit contribution' : 'New contribution', 'installment' => 'Funding installment', 'delivery' => $editingId ? 'Correct delivery' : 'Record in-kind delivery', 'void' => 'Void delivery', 'opening' => 'Opening cash record', default => 'Funding' } }}</flux:heading>
    @if (in_array($editingKind, ['funding', 'contribution', 'installment'], true))<flux:input wire:model="form.name" label="Name" required autofocus />
    @endif
    @if (in_array($editingKind, ['funding', 'contribution'], true))<flux:input wire:model="form.party" :label="$editingKind === 'funding' ? 'Provider' : 'Contributor'" :required="$editingKind === 'contribution'" />
    @endif
    @if ($editingKind === 'contribution')<x-kit.select wire:model.live="form.kind" label="Contribution kind" aria-label="Contribution kind"><flux:select.option value="CASH">Cash</flux:select.option><flux:select.option value="IN_KIND">In-kind</flux:select.option></x-kit.select>
    @endif
    @if ($editingKind !== 'void')
        <flux:input wire:model="form.amount" :label="($editingKind === 'delivery' ? 'Recorded value' : ($editingKind === 'opening' ? 'Opening cash' : 'Committed / planned amount')).' ('.$settings->currency.')'" inputmode="decimal" :required="$editingKind === 'installment'" />
        @if (in_array($editingKind, ['funding', 'contribution', 'opening'], true))<p class="text-xs text-muted-foreground">A blank amount stays unknown. Zero is an explicit amount.</p>
        @endif
    @endif
    @if ($editingKind === 'delivery' || ($editingKind === 'contribution' && ($form['kind'] ?? '') === 'IN_KIND'))
        <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="form.quantity" label="Quantity" inputmode="decimal" />@if ($editingKind === 'contribution')<flux:input wire:model="form.unit" label="Unit" placeholder="e.g. volunteer hours" />
            @endif
        </div><flux:textarea wire:model="form.basis" label="Valuation basis · required when valued" rows="3" />
    @endif
    @if ($editingKind !== 'void')<flux:field><flux:label>{{ in_array($editingKind, ['delivery', 'opening'], true) ? 'Actual date' : 'Expected / planned date' }}</flux:label><x-kit.date-field wire:model="form.date" aria-label="Funding record date" /></flux:field>
    @endif
    @if (in_array($editingKind, ['delivery', 'opening'], true))<flux:input wire:model="form.reference" label="Reference" />
    @endif
    @if ($editingKind === 'opening')<p class="text-xs text-muted-foreground">Cash at the start of this date, plus recorded receipts minus paid expenses. This is not a bank reconciliation.</p>
    @endif
    @if ($editingKind !== 'void')<flux:textarea wire:model="form.notes" :label="$editingKind === 'installment' ? 'Conditions' : 'Notes'" rows="3" />
    @endif
    @if (in_array($editingKind, ['funding', 'contribution', 'installment'], true))<x-kit.select wire:model="form.status" label="Status" aria-label="Funding record status"><flux:select.option value="ACTIVE">Active</flux:select.option><flux:select.option value="DISCONTINUED">Discontinued</flux:select.option></x-kit.select>
    @endif
    @if ($editingId !== null || $editingKind === 'void' || ($editingKind === 'opening' && $revision > 0))<flux:textarea wire:model="form.reason" label="Reason for change" rows="2" required />
    @endif
    @if ($errors->any())<ul class="space-y-1 text-sm text-destructive" role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>
        @endforeach
    </ul>
    @endif
    <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('editorOpen', false)">Cancel</flux:button><flux:button type="submit" :variant="$editingKind === 'void' ? 'danger' : 'primary'">{{ $editingKind === 'void' ? 'Void delivery' : 'Save' }}</flux:button></div>
</form></flux:modal>
