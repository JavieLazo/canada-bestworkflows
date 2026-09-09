{{--
    One employee's payslip out of a finalized payroll — the port of
    payroll/[id]/payslip/[employeeId]/individual-payslip.tsx.

    Same meta rows, same one-row earnings table, same three columns (Earnings
    breakdown / Deductions / Summary with the signature line), same
    "not acknowledged" strike-through on overtime. Print keeps only the
    `payslip-print` branch — the stylesheet the app already carries.
--}}
@php
    use App\Livewire\Payroll\Support\PayrollData;

    $meta = static function (string $label, string $value, bool $mono = false): string {
        $class = $mono ? 'num min-w-0 break-words' : 'min-w-0 break-words';

        return '<div class="flex gap-2"><dt class="font-semibold">'.e($label).':</dt><dd class="'.$class.'">'.e($value).'</dd></div>';
    };
@endphp

<div class="space-y-4">
    @if (! $run || ! $payslip || ! $employee)
        <div class="hris-card space-y-3 p-8 text-center">
            <p class="font-semibold">No payslip here.</p>
            <p class="text-sm text-muted-foreground">Payslips exist once a payroll is finalized.</p>
            <div>
                <flux:button variant="outline" :href="route('payroll.show', ['id' => $runId])" wire:navigate>
                    Back to the payroll
                </flux:button>
            </div>
        </div>
    @else
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            class="-ml-2 text-muted-foreground print:hidden"
            :href="route('payroll.show', ['id' => $run->id])"
            wire:navigate
        >
            {{ $run->name }}
        </flux:button>

        <div class="payslip-print hris-card space-y-6 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <h1 class="text-xl font-bold tracking-tight">
                    Individual Employee Payroll / Payslip
                </h1>
                <flux:button variant="outline" icon="printer" class="print:hidden" onclick="window.print()">
                    Print
                </flux:button>
            </div>

            <div class="grid gap-x-8 gap-y-2 border-b border-border pb-5 sm:grid-cols-2">
                <dl class="space-y-1 text-sm">
                    {!! $meta('Employee ID', $employee->employee_code, true) !!}
                    {!! $meta('Employee Name', mb_strtoupper(PayrollData::employeeName($employee))) !!}
                    {!! $meta('Position', $employee->position ?? '—') !!}
                </dl>
                <dl class="space-y-1 text-sm">
                    {!! $meta('Payroll Name', $run->name) !!}
                    {!! $meta('Payslip Date', PayrollData::fmtDate($payslip->period_start).' to '.PayrollData::fmtDate($payslip->period_end), true) !!}
                    {!! $meta('Work Days', PayrollData::dayCount($payslip->work_days), true) !!}
                    {!! $meta('Holidays Worked', PayrollData::dayCount($payslip->holiday_days), true) !!}
                    {!! $meta('Rest Days Worked', PayrollData::dayCount($payslip->rest_days), true) !!}
                    {!! $meta('Absences', PayrollData::dayCount($payslip->absence_days), true) !!}
                    {!! $meta('Unpaid Leave', PayrollData::dayCount($payslip->unpaid_leave_days), true) !!}
                    {!! $meta('Daily Rate', PayrollData::peso($payslip->daily_rate), true) !!}
                </dl>
            </div>

            <div class="hris-table-wrap">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th>Emp no.</th>
                            <th class="text-right">Basic salary</th>
                            <th class="text-right">Allowance</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">OT pay</th>
                            <th class="text-right">NSD</th>
                            <th class="text-right">Leave</th>
                            <th class="text-right">Others</th>
                            <th class="text-right">Gross income</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="num">{{ $employee->employee_code }}</td>
                            <td class="num text-right">{{ PayrollData::peso($payslip->basic_pay) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($payslip->allowances) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($payslip->total_base_pay) }}</td>
                            <td class="{{ $payslip->overtime_acknowledged ? 'num text-right' : 'num text-right text-muted-foreground line-through' }}">
                                {{ PayrollData::peso($payslip->overtime_pay) }}
                            </td>
                            <td class="num text-right">{{ PayrollData::peso($payslip->night_differential) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($payslip->leave_pay) }}</td>
                            <td class="num text-right">{{ PayrollData::peso($payslip->other_earnings) }}</td>
                            <td class="num text-right font-bold">{{ PayrollData::peso($payslip->gross_pay) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="space-y-2">
                    <h2 class="text-sm font-bold">Earnings breakdown</h2>
                    @php
                        $earnings = [
                            ['label' => 'Daily rate', 'value' => PayrollData::peso($payslip->daily_rate)],
                            ['label' => 'Work days', 'value' => PayrollData::dayCount($payslip->work_days)],
                            ['label' => 'Holidays worked', 'value' => PayrollData::dayCount($payslip->holiday_days)],
                            ['label' => 'Rest days worked', 'value' => PayrollData::dayCount($payslip->rest_days)],
                            ['label' => 'Work days pay', 'value' => PayrollData::peso($payslip->work_days_pay)],
                            ['label' => 'Holiday pay', 'value' => PayrollData::peso($payslip->holiday_pay)],
                            ['label' => 'Rest day pay', 'value' => PayrollData::peso($payslip->rest_day_pay)],
                            ['label' => 'Basic salary', 'value' => PayrollData::peso($payslip->basic_pay), 'strong' => true],
                            ['label' => 'Allowance', 'value' => PayrollData::peso($payslip->allowances)],
                            ['label' => 'Total', 'value' => PayrollData::peso($payslip->total_base_pay), 'strong' => true],
                            [
                                'label' => $payslip->overtime_acknowledged ? 'Overtime pay' : 'Overtime pay (not acknowledged)',
                                'value' => PayrollData::peso($payslip->overtime_pay),
                            ],
                            ['label' => 'NSD', 'value' => PayrollData::peso($payslip->night_differential)],
                            ['label' => 'Leave', 'value' => PayrollData::peso($payslip->leave_pay)],
                            ['label' => 'Others', 'value' => PayrollData::peso($payslip->other_earnings)],
                        ];
                    @endphp
                    <dl class="text-sm">
                        @foreach ($earnings as $line)
                            <div class="flex items-center justify-between gap-4 border-b border-border py-2 {{ ($line['strong'] ?? false) ? 'font-bold' : '' }}">
                                <dt class="text-muted-foreground">{{ $line['label'] }}</dt>
                                <dd class="num">{{ $line['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-bold">Deductions</h2>
                    @php
                        $deductions = [
                            ['label' => 'SSS', 'value' => $payslip->sss, 'remark' => ''],
                            ['label' => 'PhilHealth', 'value' => $payslip->philhealth, 'remark' => ''],
                            ['label' => 'Pag-IBIG', 'value' => $payslip->pagibig, 'remark' => ''],
                            ['label' => 'Withholding tax', 'value' => $payslip->withholding_tax, 'remark' => ''],
                            ['label' => 'Others', 'value' => $payslip->other_deductions, 'remark' => ''],
                            ['label' => 'Undertimes', 'value' => $payslip->undertime_deduction, 'remark' => round((float) $payslip->undertime_minutes).' min'],
                            ['label' => 'Late', 'value' => $payslip->late_deduction, 'remark' => round((float) $payslip->late_minutes).' min'],
                        ];
                    @endphp
                    <div class="hris-table-wrap">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th class="text-right">Amount</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($deductions as $d)
                                    <tr>
                                        <td>{{ $d['label'] }}</td>
                                        <td class="num text-right">{{ PayrollData::peso($d['value']) }}</td>
                                        <td class="num text-xs text-muted-foreground">{{ $d['remark'] !== '' ? $d['remark'] : '—' }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td class="font-bold">Total deductions</td>
                                    <td class="num text-right font-bold">{{ PayrollData::peso($payslip->total_deductions) }}</td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-bold">Summary</h2>
                    <dl class="text-sm">
                        <div class="flex items-center justify-between gap-4 border-b border-border py-2">
                            <dt class="text-muted-foreground">Gross income</dt>
                            <dd class="num">{{ PayrollData::peso($payslip->gross_pay) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-border py-2">
                            <dt class="text-muted-foreground">Total deductions</dt>
                            <dd class="num text-danger">{{ PayrollData::peso($payslip->total_deductions) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 py-3">
                            <dt class="font-bold">Net pay</dt>
                            <dd class="num text-lg font-bold text-success">{{ PayrollData::peso($payslip->net_pay) }}</dd>
                        </div>
                    </dl>

                    <div class="pt-10 text-center">
                        <div class="mx-auto w-56 border-t border-foreground pt-2 text-xs">
                            <p class="font-medium uppercase">{{ PayrollData::employeeName($employee) }}</p>
                            <p class="text-muted-foreground">Signature</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    @endif
</div>
