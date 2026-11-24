{{-- The issued payslip is the pay statement My Portal and Reports draw too. --}}
<div class="space-y-4">
    @if (! $run || ! $payslip || ! $employee || $statement === null)
        <div class="hris-card space-y-3 p-8 text-center">
            <p class="font-semibold">No payslip here.</p>
            <p class="text-sm text-muted-foreground">Payslips exist once a payroll is finalized.</p>
            <div>
                <flux:button variant="outline" :href="route('payroll.show', ['id' => $runId])" wire:navigate>
                    Back to the Payroll
                </flux:button>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <flux:button variant="ghost" size="sm" icon="arrow-left" class="-ml-2 text-muted-foreground"
                :href="route('payroll.show', ['id' => $run->id])" wire:navigate>
                {{ $run->name }}
            </flux:button>
            <flux:button variant="outline" icon="printer" onclick="window.print()">Print</flux:button>
        </div>
        <div class="payslip-print">
            <x-payroll.statement
                :statement="$statement"
                :employee="\App\Modules\Payroll\Application\PayrollData::employeeName($employee)"
                :employee-no="\App\Support\EmployeeIdentity::number($employee)"
                :position="$employee->position ?? '—'"
            />
        </div>
    @endif
</div>
