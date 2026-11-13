{{-- The head of every printed report sheet, word for word the payslip's: the company's logo and
     name, then the report's name and the period it covers. Takes `sheetTitle` and `sheetPeriod`. --}}
<header class="reports-sheet-top">
    <x-brand-logo variant="wordmark" class="reports-sheet-logo" />
    <div class="reports-sheet-org">
        <p>{{ config('copems.company_name') }}</p>
    </div>
    <div class="reports-sheet-doc">
        <h2>{{ $sheetTitle }}</h2>
        <p>{{ $sheetPeriod }}</p>
    </div>
</header>
