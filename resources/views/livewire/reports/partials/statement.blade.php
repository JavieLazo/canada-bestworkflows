{{-- Reports uses the same compact payslip; its complete issued evidence is available in screen-only details. --}}
<div class="reports-statement">
    <x-payroll.document :document="\App\Livewire\Payroll\Support\PayslipDocument::report($statement)" :sequence="$statementNumber.' / '.$statementCount">
        <p>See the source and coverage notes at the end of this report.</p>
        <p>Generated {{ $generatedAt }} (Manila)</p>
    </x-payroll.document>
</div>
