{{-- RunDetail's not-found card (payroll/[id]/run-detail.tsx:14-24). The 13th
     month workspace passes its own list's route and label. --}}
<div class="hris-card space-y-3 p-8 text-center">
    <p class="font-semibold">That payroll no longer exists.</p>
    <div>
        <flux:button variant="outline" :href="route($backRoute ?? 'payroll.index')" wire:navigate>
            {{ $backLabel ?? 'Back to Employee Payroll' }}
        </flux:button>
    </div>
</div>
