{{--
    A pay statement: one posted pay on one sheet, shared by Payroll, My Portal
    and Reports. Earnings with their quantity and rate, the statutory
    deductions and the other deductions, each beside its total for the year
    so far, then the net pay. Every figure is posted (PayStatements); nothing
    is worked out here.

    `statement` is PayStatements' array; `employee`, `employeeNo` and
    `position` come from the caller's own scoped read. The box of what the
    employer pays is drawn only when the statement carries it, which the
    employee's own copy never does.
--}}
@props(['statement', 'employee', 'employeeNo' => '—', 'position' => '—'])
@php
    use App\Livewire\Records\Support\PayScheduleText;
    use App\Support\Currency;

    $bare = static fn (float $amount): string => number_format($amount, 2);
    $quantity = static fn (array $row): string => $row['quantity'] === null ? '' : rtrim(rtrim(number_format($row['quantity'], 2), '0'), '.').' '.$row['unit'];
    $payOf = $statement['pay_number'] === null ? (string) $statement['year'] : 'Pay '.$statement['pay_number'].' of '.$statement['pays_in_year'].' · '.$statement['year'];
@endphp

<article {{ $attributes->class('hris-payslip hris-always-light hris-statement') }} data-pay-statement>
    <header class="hris-payslip-top">
        <x-brand-logo variant="wordmark" class="hris-payslip-logo" />
        <div class="hris-payslip-org">
            <p class="hris-payslip-company">{{ config('copems.company_name') }}</p>
        </div>
        <div class="hris-payslip-document">
            <h2>Pay Statement</h2>
            <p data-pay-of>{{ $payOf }}@if ($statement['payroll_code'] !== '') · Payroll ID {{ $statement['payroll_code'] }}@endif</p>
        </div>
    </header>

    <dl class="hris-payslip-emp hris-statement-emp" aria-label="Employee and pay period">
        <div><dt>Employee ID</dt><dd class="num">{{ $employeeNo }}</dd></div>
        <div><dt>Employee</dt><dd>{{ $employee }}<span class="hris-statement-sub">{{ $position }}</span></dd></div>
        <div><dt>Pay Period</dt><dd class="num">{{ PayScheduleText::range($statement['period_start'], $statement['period_end']) }}</dd></div>
        <div><dt>Pay Date</dt><dd class="num">{{ PayScheduleText::longDay($statement['pay_date']) }}</dd></div>
    </dl>

    <table class="hris-table hris-payslip-month hris-statement-table" data-statement-earnings>
        <caption class="sr-only">Earnings in Canadian dollars</caption>
        <thead>
            <tr>
                <th scope="col">Earnings</th>
                <th scope="col" class="num">Hours or Days</th>
                <th scope="col" class="num">Rate</th>
                <th scope="col" class="num">This Pay</th>
                <th scope="col" class="num">Year to Date</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statement['earnings'] as $row)
                <tr>
                    <th scope="row">{{ $row['label'] }}@if ($row['note'] !== '') <span class="hris-statement-note">({{ $row['note'] }})</span>@endif</th>
                    <td class="num">{{ $quantity($row) }}</td>
                    <td class="num">{{ $row['rate'] === null ? '' : $bare($row['rate']) }}</td>
                    <td class="num">{{ $bare($row['amount']) }}</td>
                    <td class="num">{{ $bare($row['ytd']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th scope="row" colspan="3">Gross Pay</th>
                <td class="num" data-statement-gross>{{ $bare($statement['gross']['amount']) }}</td>
                <td class="num">{{ $bare($statement['gross']['ytd']) }}</td>
            </tr>
        </tfoot>
    </table>

    @foreach ([
        'statutory' => ['Statutory Deductions', 'Total Statutory Deductions', $statement['statutory'], $statement['statutory_total']],
        'other' => ['Other Deductions', 'Total Other Deductions', $statement['other'], $statement['other_total']],
    ] as $kind => [$title, $totalLabel, $rows, $total])
        @if ($rows !== [])
            <table class="hris-table hris-payslip-month hris-statement-table" data-statement-{{ $kind }}>
                <caption class="sr-only">{{ $title }} in Canadian dollars</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ $title }}</th>
                        <th scope="col" class="num">This Pay</th>
                        <th scope="col" class="num">Year to Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <th scope="row">{{ $row['label'] }}@if ($row['quantity'] !== null) <span class="hris-statement-note">({{ $quantity($row) }})</span>@endif @if ($row['note'] !== '') <span class="hris-statement-note">({{ $row['note'] }})</span>@endif</th>
                            <td class="num">{{ $bare($row['amount']) }}</td>
                            <td class="num">{{ $bare($row['ytd']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">{{ $totalLabel }}</th>
                        <td class="num">{{ $bare($total['amount']) }}</td>
                        <td class="num">{{ $bare($total['ytd']) }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    @endforeach

    <div class="hris-payslip-net hris-statement-net" aria-label="Net pay in Canadian dollars">
        <div>
            <span>Gross pay {{ Currency::format($statement['gross']['amount']) }} less deductions {{ Currency::format($statement['deductions']) }}@if ($statement['refund'] > 0) plus a tax refund of {{ Currency::format($statement['refund']) }}@endif</span>
        </div>
        <div class="hris-payslip-pay"><span>Net Pay</span><span class="num" data-statement-net>{{ Currency::format($statement['net']) }}</span></div>
    </div>

    {{-- Who signs the pay statement, as the signatories stand when it is printed (Payroll Settings › Signatories). --}}
    @include('livewire.payroll.partials.signatories', ['signatories' => app(\App\Modules\Payroll\Application\Queries\SignatoryRecords::class)->all(app(\App\Support\Access::class))])

    <p class="hris-payslip-foot">This pay statement contains confidential payroll information intended only for the named employee.</p>
</article>

@if ($statement['employer'] !== null)
    <aside class="hris-statement-employer print:hidden" data-employer-box>
        <p class="hris-statement-employer-title">Shown to Payroll Only</p>
        <p>
            Employer pays for this employee, this pay: CPP <span class="num">{{ Currency::format($statement['employer']['pension']) }}</span>
            · EI <span class="num">{{ Currency::format($statement['employer']['insurance']) }}</span>.
            The employee's own copy in My Portal does not show this box.
        </p>
        {{ $slot }}
    </aside>
@endif
