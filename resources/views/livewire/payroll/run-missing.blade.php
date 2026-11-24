{{-- RunDetail's not-found card (payroll/[id]/run-detail.tsx:14-24). --}}
<div class="hris-card space-y-3 p-8 text-center">
    <p class="font-semibold">That payroll no longer exists.</p>
    <div>
        <flux:button variant="outline" :href="route('payroll.index')" wire:navigate>
            Back to Payroll
        </flux:button>
    </div>
</div>
