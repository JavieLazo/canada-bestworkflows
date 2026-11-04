{{--
    Signatories — who signs the payroll documents: one row per signature slot
    (Prepared by, Checked by, Certified correct by) with the name and
    designation the payslip and the payroll register print. Whoever manages
    the Payroll Settings edits all three in one form; everyone else reads
    them. Nothing here approves a payroll.
--}}
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Signatories</h1>
        <p class="mt-1 text-sm text-muted-foreground">Who signs the payslip and the payroll register, printed under each signature line.</p>
    </div>

    @include('livewire.payroll.partials.settings-switch', ['activeView' => 'signatories'])

    <div class="rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground">
        @if (! $ready)
            The signatories can be set once this installation's upgrade finishes; until then every document prints blank signature lines.
        @else
            A document prints the signatories as they stand when it is printed. A slot left blank prints as an empty line to be filled by hand.
        @endif
        @unless ($canManage)
            <p class="mt-2">Your role can view these signatories. Changing them requires Payroll access to all data.</p>
        @endunless
    </div>

    <section class="hris-card p-5">
        @if ($canManage && $ready)
            <form class="space-y-5" wire:submit="save">
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
            <dl class="grid gap-4 sm:grid-cols-3">
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
    <section class="hris-card p-5">
        <h2 class="text-base font-bold">Preview</h2>
        <p class="mt-1 text-sm text-muted-foreground">How the signature lines appear at the foot of a payslip and of the payroll register.</p>
        @include('livewire.payroll.partials.signatories', ['signatories' => $signatories])
    </section>
</div>
