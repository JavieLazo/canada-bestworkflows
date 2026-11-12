{{--
    Signatories — who signs the payroll documents: one row per signature slot
    (Prepared by, Checked by, Certified correct by) with the name and
    designation the payslip and the payroll register print, beside the
    blocks as the documents print them. Whoever manages the Payroll Settings
    edits all three in one form; everyone else reads them. Nothing here
    approves a payroll.
--}}
<div class="space-y-6">
    @include('livewire.payroll.partials.settings-header', [
        'activeView' => 'signatories',
        'title' => 'Signatories',
        'description' => 'Who signs the payslip and the payroll register.',
    ])

    @if (! $ready || ! $canManage)
        <x-payroll.notice tone="note" class="rounded-lg border px-4 py-3">
            @unless ($ready)
                <p>Signatories can be set once this installation's upgrade finishes. Until then, documents print blank signature lines.</p>
            @endunless
            @unless ($canManage)
                <p>Your role can view these signatories. Changing them requires Payroll access to all data.</p>
            @endunless
        </x-payroll.notice>
    @endif

    {{-- The form beside what it prints: from xl up the two cards share a row, the form the wider one. --}}
    <div class="grid gap-6 xl:grid-cols-5 xl:items-start">
        <section class="hris-card min-w-0 overflow-hidden xl:col-span-3">
            <x-payroll.settings.head
                title="Signature lines"
                description="Documents print the current signatories. A blank slot prints an empty line to sign by hand."
            />
            @if ($canManage && $ready)
                <form class="space-y-5 p-5" wire:submit="save">
                    @foreach ($signatories as $signatory)
                        <fieldset class="grid gap-4 sm:grid-cols-[10rem_1fr_1fr]" wire:key="signatory-{{ $signatory->slot }}">
                            <legend class="sr-only">{{ $signatory->label }}</legend>
                            <p class="pt-2 text-sm font-semibold">{{ $signatory->label }}</p>
                            <flux:input wire:model="form.{{ $signatory->slot }}.name" label="Name" :placeholder="'Leave blank for an empty line'" maxlength="200" />
                            <flux:input wire:model="form.{{ $signatory->slot }}.designation" label="Designation" :placeholder="'e.g. Finance Analyst'" maxlength="200" />
                        </fieldset>
                    @endforeach
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="check">Save signatories</flux:button>
                    </div>
                </form>
            @else
                <dl class="grid gap-4 p-5 sm:grid-cols-3">
                    @foreach ($signatories as $signatory)
                        <div class="min-w-0" wire:key="signatory-{{ $signatory->slot }}">
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $signatory->label }}</dt>
                            <dd class="mt-1 text-sm font-semibold">{{ $signatory->name !== '' ? $signatory->name : '—' }}</dd>
                            <dd class="text-sm text-muted-foreground">{{ $signatory->designation !== '' ? $signatory->designation : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </section>

        {{-- The blocks as the documents print them. --}}
        <section class="hris-card min-w-0 overflow-hidden xl:col-span-2">
            <x-payroll.settings.head
                title="Preview"
                description="How the signature lines appear at the foot of a payslip and of the payroll register."
            />
            <div class="px-5 pb-5">
                @include('livewire.payroll.partials.signatories', ['signatories' => $signatories])
            </div>
        </section>
    </div>
</div>
