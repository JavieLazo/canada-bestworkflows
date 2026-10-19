{{-- The supplied compact payslip is shared by every surface; extra issued evidence remains outside its print area.
     `details` is false where that evidence must not be offered at all: My Portal shows an employee the payslip only. --}}
@props(['document', 'sequence' => null, 'details' => true])

<article {{ $attributes->class('hris-payslip') }} @if ($document['yearly']) data-thirteenth-month @endif>
    <header class="hris-payslip-header">
        <div class="hris-payslip-brand">
            <x-brand-logo class="hris-payslip-logo" />
            <div>
                <p class="hris-payslip-company">{{ config('copems.company_name') }}</p>
                <p class="hris-payslip-subtitle">Employee pay statement</p>
            </div>
        </div>
        <div class="hris-payslip-document">
            <h2>Payslip</h2>
            @if ($document['payrollCode'] !== '')<p class="hris-payslip-record-id">Payroll ID <span>{{ $document['payrollCode'] }}</span></p>@endif
        </div>
    </header>

    <section class="hris-payslip-identity" aria-label="Employee and pay period">
        <div>
            <span class="hris-payslip-label">Employee</span>
            <h3 class="hris-payslip-employee">{{ $document['employee'] }}</h3>
            <p class="hris-payslip-employee-id">Employee ID <span class="num">{{ $document['employeeId'] }}</span></p>
        </div>
        <div class="hris-payslip-period">
            <span class="hris-payslip-label">Pay period</span>
            <p class="hris-payslip-period-value num">{{ $document['periodFrom'] }} <span class="hris-payslip-period-end">to {{ $document['periodTo'] }}</span></p>
        </div>
    </section>

    @if ($document['basis'] !== [])
        <dl class="hris-payslip-basis" aria-label="Pay basis and leave">
            @foreach ($document['basis'] as $field)
                <div><dt>{{ $field['label'] }}</dt><dd class="num">{{ $field['value'] }}</dd></div>
            @endforeach
        </dl>
    @endif

    <div class="hris-payslip-grid">
        <x-payroll.lines title="Earnings" :lines="$document['earnings']" total-label="Gross income" :total="$document['gross']" />
        <x-payroll.lines title="Deductions" :lines="$document['deductions']" total-label="Total deductions" :total="$document['deductionTotal']" />
    </div>

    <section class="hris-payslip-summary" aria-label="Net pay in Philippine pesos">
        <div><span class="hris-payslip-label">Net pay</span><p>Gross income less total deductions</p></div>
        <p class="hris-payslip-net num"><small>PHP</small><span>{{ $document['net'] }}</span></p>
    </section>

</article>

@if ($details)
<details class="hris-payslip-details print:hidden">
    <summary>Additional payroll details</summary>
    @if ($document['supplemental'] !== [])
        <div class="hris-payslip-supplemental">
            @foreach ($document['supplemental'] as $group)
                <div @if ($group['employer'] ?? false) data-employer-shares @endif>
                    <x-payroll.lines :title="$group['title']" :lines="$group['rows']" :currency="false" />
                    @foreach ($group['notes'] ?? [] as $note)<p class="hris-payslip-quantity">{{ $note }}</p>@endforeach
                </div>
            @endforeach
        </div>
    @endif
    <div class="hris-payslip-details-notes">
        @if ($sequence !== null)<p>Payslip {{ $sequence }}</p>@endif
        @foreach ($document['notes'] as $note)<p>{{ $note }}</p>@endforeach
        {{ $slot }}
    </div>
</details>
@endif
