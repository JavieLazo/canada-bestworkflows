{{-- The issued payslip uses the same branded document as My Portal and Reports. --}}
<div class="space-y-4">
    @if (! $run || ! $payslip || ! $employee)
        <div class="hris-card space-y-3 p-8 text-center">
            <p class="font-semibold">No payslip here.</p>
            <p class="text-sm text-muted-foreground">Payslips exist once a payroll is finalized.</p>
            <div>
                <flux:button variant="outline" :href="route('payroll.show', ['id' => $runId])" wire:navigate>
                    Back to the payroll
                </flux:button>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <flux:button variant="ghost" size="sm" icon="arrow-left" class="-ml-2 text-muted-foreground"
                :href="route($yearly ? 'payroll.thirteenth.show' : 'payroll.show', ['id' => $run->id])" wire:navigate>
                {{ $run->name }}
            </flux:button>
            <flux:button variant="outline" icon="printer" onclick="window.print()">Print</flux:button>
        </div>
        <div class="payslip-print">
            <x-payroll.document :document="\App\Livewire\Payroll\Support\PayslipDocument::issued($payslip, $employee, $yearly, $incentives, $customDeductions, $deductionColumns, $run->name, settlements: $settlements)" />
        </div>
    @endif
</div>
