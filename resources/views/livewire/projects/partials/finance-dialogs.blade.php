@php
    use App\Modules\WorkManagement\Domain\Projects\FinanceAmount;
@endphp
@if ($permissions['edit'])
    <flux:modal wire:model.self="pasteOpen" class="w-full max-w-2xl">
        <form wire:submit="{{ $pasteRows === [] ? 'previewPaste' : 'pasteCosts' }}" class="space-y-5">
            <flux:heading size="lg" level="2">Paste cost items</flux:heading>
            <p class="text-sm text-muted-foreground">Copy two columns from your spreadsheet: cost item and amount, without headings. Up to 100 rows.</p>
            <flux:textarea wire:model="pasteText" label="Spreadsheet rows" rows="6" maxlength="50000" placeholder="Travel&#9;1,250.00&#10;Materials&#9;500.00" autofocus />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-kit.select wire:model="categoryId" label="Category for these items" aria-label="Pasted cost category" searchable>
                    @foreach ($categories as $category)<flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                <x-kit.select wire:model="groupId" label="Activity" aria-label="Pasted cost activity" searchable><flux:select.option value="NONE">No activity</flux:select.option>
                    @foreach ($groups as $group)<flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
            </div>
            @if ($pasteRows !== [])
                <div class="max-h-64 overflow-auto rounded-lg border border-border"><table class="hris-table w-full"><caption class="sr-only">Cost items to add</caption><thead><tr><th>Cost item</th><th class="text-right">Amount ({{ $settings->currency }})</th></tr></thead><tbody>
                    @foreach ($pasteRows as $row)<tr wire:key="paste-row-{{ $row['line'] }}"><td class="max-w-72 break-words">{{ $row['label'] }}</td><td class="num text-right">{{ FinanceAmount::format($row['amount']) }}</td></tr>
                    @endforeach
                </tbody></table></div>
                <p class="num text-right text-sm font-semibold">{{ count($pasteRows) }} items · {{ $settings->currency }} {{ FinanceAmount::format(array_sum(array_column($pasteRows, 'amount'))) }}</p>
            @endif
            <flux:error name="budget" /><flux:error name="amount" />
            <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('pasteOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">{{ $pasteRows === [] ? 'Preview rows' : 'Add '.count($pasteRows).' cost items' }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="lineOpen" class="w-full max-w-xl">
        <form wire:submit="saveLine" class="space-y-5">
            <flux:heading size="lg" level="2">{{ $lineId === null ? 'Add cost item' : 'Edit cost item' }}</flux:heading>
            <flux:input wire:model="label" label="Cost item" maxlength="200" required autofocus />
            <div class="flex items-end gap-2"><x-kit.select wire:model="categoryId" label="Category" aria-label="Cost category" searchable class="min-w-0 flex-1">
                @foreach ($categories as $category)<flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </x-kit.select><flux:button type="button" variant="ghost" square icon="plus" wire:click="openCategory" aria-label="Add a cost category" /></div>
            <x-kit.select wire:model.live="basis" label="Cost basis" aria-label="Cost basis"><flux:select.option value="FLAT">Flat amount</flux:select.option><flux:select.option value="CALCULATED">Quantity × frequency × unit cost</flux:select.option></x-kit.select>
            @if ($basis === 'CALCULATED')
                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model="quantity" label="Quantity" inputmode="decimal" required />
                    <flux:input wire:model="frequency" label="Frequency" type="number" min="1" max="1000" required />
                    <flux:field><flux:label>Unit cost ({{ $settings->currency }})</flux:label><x-kit.money-input wire:model="unitAmount" aria-label="Unit cost" /><flux:error name="unitAmount" /></flux:field>
                </div>
                <flux:error name="amount" />
            @else
                <flux:field><flux:label>Amount ({{ $settings->currency }})</flux:label><x-kit.money-input wire:model="amount" aria-label="Cost amount" /><flux:error name="amount" /></flux:field>
            @endif
            <details class="rounded-lg border border-border px-4 py-3"><summary class="w-fit text-sm font-medium">Activity and notes</summary><div class="space-y-4 pt-4">
                <x-kit.select wire:model="groupId" label="Activity" aria-label="Cost activity" searchable><flux:select.option value="NONE">No activity</flux:select.option>
                    @foreach ($groups as $group)<flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                    @endforeach
                </x-kit.select>
                <flux:textarea wire:model="notes" label="Notes" rows="3" maxlength="4000" />
            </div></details>
            <div class="grid gap-4 sm:grid-cols-2"><x-kit.select wire:model="linePeriod" label="Reporting period" aria-label="Cost reporting period"><flux:select.option value="">Unallocated</flux:select.option>@foreach ($reportingPeriods as $p)<flux:select.option value="{{ $p->id }}">{{ $p->name }}</flux:select.option>
                @endforeach
            </x-kit.select><x-kit.select wire:model="lineSource" label="Funding / contribution" aria-label="Cost funding source" searchable><flux:select.option value="">Unallocated</flux:select.option>@foreach ($fundingChoices as $source)<flux:select.option value="{{ $source['value'] }}">{{ $source['label'] }}</flux:select.option>
                @endforeach
            </x-kit.select></div>
            <flux:error name="periodId" /><flux:error name="sourceId" /><flux:error name="budget" />
            <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('lineOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Save cost item</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="entryOpen" class="w-full max-w-xl">
        <form wire:submit="saveEntry" class="space-y-5">
            <flux:heading size="lg" level="2">{{ $replacesId !== null ? 'Correct cash entry' : ($entryKind === 'RECEIPT' ? 'Record cash received' : 'Record paid expense') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2"><x-kit.select wire:model="entryKind" label="Type" aria-label="Recorded cash type"><flux:select.option value="EXPENSE">Paid expense</flux:select.option><flux:select.option value="RECEIPT">Cash received</flux:select.option></x-kit.select><flux:field><flux:label>Actual date</flux:label><x-kit.date-field wire:model="occurredOn" aria-label="Actual cash date" /><flux:error name="occurredOn" /></flux:field></div>
            <flux:error name="kind" />
            <flux:input wire:model="description" label="Description" maxlength="200" required autofocus />
            <div class="grid gap-4 sm:grid-cols-2"><flux:field><flux:label>Amount ({{ $settings->currency }})</flux:label><x-kit.money-input wire:model="amount" aria-label="Cash amount" /><flux:error name="amount" /></flux:field><x-kit.select wire:model="categoryId" label="Category" aria-label="Cash category" searchable>
                @foreach ($categories as $category)<flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </x-kit.select></div>
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="party" label="Payer / payee" maxlength="200" /><flux:input wire:model="reference" label="Reference" maxlength="200" /></div>
            <x-kit.select wire:model.live="cashSource" label="Funding / contribution allocation" aria-label="Cash source allocation" searchable><flux:select.option value="">Classify later</flux:select.option>@foreach ($fundingChoices as $source)<flux:select.option value="{{ $source['value'] }}">{{ $source['label'] }}</flux:select.option>
                @endforeach
            </x-kit.select>
            @if ($sourceInstallments !== null)<x-kit.select wire:model="cashInstallment" label="Installment" aria-label="Cash installment"><flux:select.option value="">No installment</flux:select.option>@foreach ($sourceInstallments as $i)<flux:select.option value="{{ $i->id }}">{{ $i->name }}</flux:select.option>
                @endforeach
            </x-kit.select>
            @endif
            <flux:error name="sourceId" /><flux:error name="installmentId" />
            <details class="rounded-lg border border-border px-4 py-3"><summary class="w-fit text-sm font-medium">Activity and notes</summary><div class="space-y-4 pt-4"><x-kit.select wire:model="groupId" label="Activity" aria-label="Cash activity" searchable><flux:select.option value="NONE">No activity</flux:select.option>
                @foreach ($groups as $group)<flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                @endforeach
            </x-kit.select><flux:textarea wire:model="notes" label="Notes" rows="3" maxlength="4000" /></div></details>
            @if ($replacesId !== null)<flux:textarea wire:model="correctionReason" label="Reason for correction" rows="2" maxlength="1000" required />
            @endif
            <flux:error name="entry" />
            <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('entryOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">{{ $replacesId !== null ? 'Record correction' : 'Record entry' }}</flux:button></div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="categoryOpen" class="w-full max-w-md"><form wire:submit="saveCategory" class="space-y-5"><flux:heading size="lg" level="2">{{ $editingCategoryId === null ? 'Add category' : 'Rename category' }}</flux:heading><flux:input wire:model="categoryName" label="Category name" maxlength="100" required autofocus /><div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('categoryOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Save category</flux:button></div></form></flux:modal>

    <flux:modal wire:model.self="revisionOpen" class="w-full max-w-lg"><form wire:submit="startBudget" class="space-y-5"><flux:heading size="lg" level="2">Start budget revision</flux:heading><flux:textarea wire:model="revisionReason" label="Reason for revision" rows="3" maxlength="1000" required /><div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('revisionOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Create revision</flux:button></div></form></flux:modal>

    <flux:modal wire:model.self="voidOpen" class="w-full max-w-lg"><form wire:submit="voidEntry" class="space-y-5"><flux:heading size="lg" level="2">Void cash entry</flux:heading><flux:text>The entry stays in history and is excluded from recorded totals.</flux:text><flux:textarea wire:model="voidReason" label="Reason for voiding" rows="3" maxlength="1000" required /><div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('voidOpen', false)">Cancel</flux:button><flux:button type="submit" variant="danger">Void entry</flux:button></div></form></flux:modal>
@endif

@if ($permissions['admin'])
    <flux:modal wire:model.self="currencyOpen" class="w-full max-w-md"><form wire:submit="setupFinance" class="space-y-5"><flux:heading size="lg" level="2">Project finance currency</flux:heading><x-kit.select wire:model="currency" label="Currency" aria-label="Change finance currency">
        @foreach (FinanceAmount::CURRENCIES as $code => $name)<flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
        @endforeach
    </x-kit.select><flux:error name="currency" /><div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('currencyOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Save currency</flux:button></div></form></flux:modal>
    <flux:modal wire:model.self="approvalOpen" class="w-full max-w-lg"><form wire:submit="approveBudget" class="space-y-5"><flux:heading size="lg" level="2">Approve budget version {{ $approvalVersion }}</flux:heading><p class="num text-2xl font-semibold">{{ $settings->currency }} {{ FinanceAmount::format($approvalTotal) }}</p><flux:text>This freezes its cost assumptions. Later changes use a new revision.</flux:text><flux:error name="budget" /><div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('approvalOpen', false)">Cancel</flux:button><flux:button type="submit" variant="primary">Approve this version</flux:button></div></form></flux:modal>

    <flux:modal wire:model.self="accessOpen" class="w-full max-w-lg"><div class="space-y-5"><div class="space-y-2"><flux:heading size="lg" level="2">Financial access</flux:heading><flux:text>Project administrators manage finance. Other team members need separate financial access.</flux:text></div>
        <form wire:submit="saveAccess" class="space-y-4"><x-kit.select wire:model="financeMember" label="Project member or lead" aria-label="Financial access person" placeholder="Choose a person" searchable>
            @foreach ($financeCandidates as $person)<flux:select.option value="{{ $person->id }}">{{ $person->name }}</flux:select.option>
            @endforeach
        </x-kit.select><div class="flex flex-wrap items-end gap-2"><x-kit.select wire:model="financeRole" label="Financial role" aria-label="Financial role" class="min-w-48 flex-1"><flux:select.option value="VIEWER">Financial viewer</flux:select.option><flux:select.option value="EDITOR">Financial editor</flux:select.option></x-kit.select><flux:button type="submit" variant="primary">Save access</flux:button></div></form>
        @if ($financePeople !== null)
            <ul class="divide-y divide-border">
                @forelse ($financePeople as $person)<li class="flex items-center justify-between gap-3 py-3" wire:key="finance-person-{{ $person->user_id }}"><div class="min-w-0"><span class="block truncate text-sm font-medium" title="{{ $person->name }}">{{ $person->name }}</span><span class="text-xs text-muted-foreground">{{ $person->role === 'EDITOR' ? 'Financial editor' : 'Financial viewer' }}</span></div><flux:button size="sm" variant="ghost" class="text-danger!" wire:click="removeAccess({{ $person->user_id }})" aria-label="Remove financial access for {{ $person->name }}">Remove</flux:button></li>
                @empty<li class="py-4 text-sm text-muted-foreground">No additional financial access.</li>
                @endforelse
            </ul>
            <x-kit.pagination :paginator="$financePeople" noun="financial members" class="!border-0 !px-0" />
        @endif
    </div></flux:modal>
@endif

<flux:modal wire:model.self="detailsOpen" class="w-full max-w-lg">
    @if ($entryDetails !== null)
        <div class="space-y-5"><flux:heading size="lg" level="2" class="break-words">{{ $entryDetails->description }}</flux:heading><dl class="grid grid-cols-2 gap-4 text-sm">
            @foreach (['Type' => $entryDetails->kind === 'RECEIPT' ? 'Cash received' : 'Paid expense', 'Actual date' => $entryDetails->occurred_on->format('M j, Y'), 'Amount' => $settings->currency.' '.FinanceAmount::format($entryDetails->amount_minor), 'Category' => $entryDetails->category_label, 'Activity' => $entryDetails->group_label, 'Payer / payee' => $entryDetails->party, 'Reference' => $entryDetails->reference] as $name => $value)
                @if ($value !== null)<div class="min-w-0"><dt class="text-xs text-muted-foreground">{{ $name }}</dt><dd class="mt-1 break-words">{{ $value }}</dd></div>
                @endif
            @endforeach
        </dl>
        @if ($entryDetails->notes !== null)<p class="whitespace-pre-wrap break-words text-sm">{{ $entryDetails->notes }}</p>
        @endif
        @if ($entryDetails->voided_at !== null)<div class="rounded-lg bg-muted p-3 text-sm"><span class="font-medium">Voided</span><p class="mt-1 whitespace-pre-wrap break-words text-muted-foreground">{{ $entryDetails->void_reason }}</p></div>
        @endif
        @if ($entryDetails->replaces_id !== null)<p class="whitespace-pre-wrap break-words text-sm text-muted-foreground">{{ $entryDetails->correction_reason }}</p><flux:button size="sm" variant="outline" wire:click="openEntryDetails('{{ $entryDetails->replaces_id }}')">View original entry</flux:button>
        @endif
        <livewire:projects.cash-allocations :project-id="$projectId" :entry-id="$entryDetails->id" :key="'cash-allocations-'.$entryDetails->id" />
        <livewire:projects.record-evidence-panel :project-id="$projectId" entity="CASH_ENTRY" :record-id="$entryDetails->id" :key="'cash-proof-'.$entryDetails->id" />
        <livewire:projects.record-fields :project-id="$projectId" entity="CASH_ENTRY" :record-id="$entryDetails->id" :key="'cash-fields-'.$entryDetails->id" />
        </div>
    @else
        <flux:heading size="lg" level="2">This entry is no longer available.</flux:heading>
    @endif
</flux:modal>
