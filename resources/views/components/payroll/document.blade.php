{{-- PPMC's payslip sheet, shared by every surface: an employee's posted cut-offs for a month, or a 13th month slip alone. Extra issued evidence stays outside its print area.
     `details` is false where that evidence must not be offered at all: My Portal shows an employee the payslip only. --}}
@props(['sheet', 'sequence' => null, 'details' => true])

<article {{ $attributes->class('hris-payslip hris-always-light') }} @if ($sheet['yearly']) data-thirteenth-month @endif>
    <header class="hris-payslip-top">
        <x-brand-logo variant="wordmark" class="hris-payslip-logo" />
        <div class="hris-payslip-org">
            <p class="hris-payslip-company">{{ config('copems.company_name') }}</p>
        </div>
        <div class="hris-payslip-document">
            <h2>Payslip</h2>
            <p>{{ $sheet['title'] }}</p>
        </div>
    </header>

    <dl class="hris-payslip-emp" aria-label="Employee">
        <div><dt>Employee No.</dt><dd class="num">{{ $sheet['employeeId'] }}</dd></div>
        <div><dt>Employee Name</dt><dd>{{ $sheet['employee'] }}</dd></div>
        <div><dt>Position</dt><dd>{{ $sheet['position'] }}</dd></div>
        <div><dt>Office / Department</dt><dd>{{ $sheet['office'] }}</dd></div>
    </dl>

    @foreach ($sheet['slips'] as $slip)
        <section class="hris-payslip-slip" data-payslip-slip aria-label="Payroll period {{ $slip['period'] }}">
            <div class="hris-payslip-band">
                <h3>Payroll period: {{ $slip['period'] }}</h3>
                <span>
                    {{ $slip['half'] }}
                    @if ($slip['half'] !== '' && $slip['payrollCode'] !== '') · @endif
                    @if ($slip['payrollCode'] !== '')Payroll ID {{ $slip['payrollCode'] }}@endif
                </span>
            </div>

            @if ($slip['basis'] !== [])
                <dl class="hris-payslip-basis" aria-label="Pay basis and leave">
                    @foreach ($slip['basis'] as $field)
                        <div><dt>{{ $field['label'] }}</dt><dd class="num">{{ $field['value'] }}</dd></div>
                    @endforeach
                </dl>
            @endif

            <div class="hris-payslip-cols">
                <div class="hris-payslip-col">
                    <x-payroll.slip-items title="Earnings" :groups="$slip['earningGroups']" total-label="Total Earnings" :total="$slip['gross']" />
                    <div class="hris-payslip-net" aria-label="Net pay in Philippine pesos">
                        <div><span>Total Earnings</span><span class="num">{{ $slip['gross'] }}</span></div>
                        <div><span>Less: Total Deductions</span><span class="num">({{ $slip['deductionTotal'] }})</span></div>
                        @if ($slip['refund'] !== '')
                            <div data-tax-refund><span>Add: Tax Refund</span><span class="num">{{ $slip['refund'] }}</span></div>
                        @endif
                        <div class="hris-payslip-pay"><span>Net pay</span><span class="num">₱ {{ $slip['net'] }}</span></div>
                    </div>
                </div>
                <div class="hris-payslip-col">
                    <x-payroll.slip-items title="Deductions" :groups="$slip['deductionGroups']" total-label="Total Deductions" :total="$slip['deductionTotal']" />
                </div>
            </div>
        </section>
    @endforeach

    @if ($sheet['summary'] !== null)
        <div class="hris-payslip-after hris-table-wrap">
            <table class="hris-table hris-payslip-month" data-month-summary>
                <caption class="sr-only">Monthly summary in Philippine pesos</caption>
                <thead><tr><th scope="col">Monthly Summary</th><th scope="col" class="num">Earnings</th><th scope="col" class="num">Deductions</th><th scope="col" class="num">Net Pay</th></tr></thead>
                <tbody>
                    @foreach ($sheet['summary']['rows'] as $row)
                        <tr><th scope="row">{{ $row['period'] }}</th><td class="num">{{ $row['gross'] }}</td><td class="num">{{ $row['deductions'] }}</td><td class="num">{{ $row['net'] }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot><tr><th scope="row">Total for the Month</th><td class="num">{{ $sheet['summary']['total']['gross'] }}</td><td class="num">{{ $sheet['summary']['total']['deductions'] }}</td><td class="num">{{ $sheet['summary']['total']['net'] }}</td></tr></tfoot>
            </table>
        </div>
    @endif

    {{-- Who signs the payslip, as the signatories stand when it is printed (Payroll Settings › Signatories). --}}
    @include('livewire.payroll.partials.signatories', ['signatories' => app(\App\Modules\Payroll\Application\Queries\SignatoryRecords::class)->all(app(\App\Support\Access::class))])

    <p class="hris-payslip-foot">This payslip contains confidential payroll information intended only for the named employee.</p>
</article>

@if ($details)
    <details class="hris-payslip-details print:hidden">
        <summary>Additional payroll details</summary>
        @foreach ($sheet['slips'] as $slip)
            @if (count($sheet['slips']) > 1)<p class="hris-payslip-details-period">{{ $slip['period'] }}</p>@endif
            @if ($slip['supplemental'] !== [])
                <div class="hris-payslip-supplemental">
                    @foreach ($slip['supplemental'] as $group)
                        <div @if ($group['employer'] ?? false) data-employer-shares @endif>
                            <x-payroll.lines :title="$group['title']" :lines="$group['rows']" :currency="false" />
                            @foreach ($group['notes'] ?? [] as $note)<p class="hris-payslip-quantity">{{ $note }}</p>@endforeach
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="hris-payslip-details-notes">
                @foreach ($slip['notes'] as $note)<p>{{ $note }}</p>@endforeach
            </div>
        @endforeach
        <div class="hris-payslip-details-notes">
            @if ($sequence !== null)<p>Payslip {{ $sequence }}</p>@endif
            {{ $slot }}
        </div>
    </details>
@endif
