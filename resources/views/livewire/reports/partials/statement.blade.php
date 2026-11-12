{{-- Reports uses the same payslip sheet, one per employee and month; its complete issued evidence is available in screen-only details. --}}
<div class="reports-statement">
    <x-payroll.document :sheet="$sheet" :sequence="$statementNumber.' / '.$statementCount">
        <p>See the source and coverage notes at the end of this report.</p>
        <p>Generated {{ $generatedAt }} (Manila)</p>
    </x-payroll.document>
</div>
