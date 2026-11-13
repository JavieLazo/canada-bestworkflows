{{-- Reports uses the same payslip sheet, one per employee and month; its complete issued evidence is available in screen-only details. --}}
<div class="reports-statement">
    <x-payroll.document :sheet="$sheet" :sequence="$statementNumber.' / '.$statementCount">
        <p>Generated {{ $generatedAt }} (Manila)</p>
    </x-payroll.document>
</div>
