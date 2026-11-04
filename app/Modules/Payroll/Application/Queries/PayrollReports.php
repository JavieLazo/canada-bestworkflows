<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Queries;

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Contracts\Views\PayslipDeductionView;
use App\Modules\Payroll\Contracts\Views\PayslipIncentiveView;
use App\Modules\Workforce\Contracts\DirectoryEmployee;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use App\Support\EmployeeIdentity;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Reports describe posted pay; they never reprice it from today's employee or statutory settings. */
final class PayrollReports
{
    private const array REGISTER_AMOUNTS = [
        'basic_pay' => 'Basic pay subtotal', 'allowances' => 'Allowances', 'overtime_pay' => 'Stored overtime pay',
        'night_differential' => 'Night differential', 'leave_pay' => 'Leave pay', 'other_earnings' => 'Other earnings',
        'settlement' => 'Attendance settlement',
        'bonus' => 'Taxable incentives', 'exempt_benefits' => 'Tax-exempt benefits (tax treatment)', 'thirteenth_month' => '13th month pay',
        'gross_pay' => 'Gross pay', 'sss' => 'SSS employee', 'philhealth' => 'PhilHealth employee',
        'pagibig' => 'Pag-IBIG employee', 'withholding_tax' => 'Withholding tax',
        'late_deduction' => 'Late deduction', 'undertime_deduction' => 'Undertime deduction', 'other_deductions' => 'Other deductions',
        'custom_deductions' => 'Named deductions subtotal',
        'late_undertime_deduction' => 'Legacy late / undertime deduction', 'absence_deduction' => 'Legacy absence deduction',
        'unpaid_leave_deduction' => 'Legacy unpaid leave deduction', 'total_deductions' => 'Total deductions', 'net_pay' => 'Net pay',
    ];

    private const array CONTRIBUTIONS = [
        'sss' => 'SSS employee', 'sss_er' => 'SSS employer',
        'philhealth' => 'PhilHealth employee', 'philhealth_er' => 'PhilHealth employer',
        'pagibig' => 'Pag-IBIG employee', 'pagibig_er' => 'Pag-IBIG employer',
        'withholding_tax' => 'Withholding tax',
    ];

    public function generate(Access $actor, ReportCriteria $criteria): ReportDocument
    {
        $this->authorize($actor);
        if ($criteria->type === 'loan-summary' && $criteria->deductionCodes === []) {
            throw ValidationException::withMessages(['deductionCodes' => 'Select the deduction types to include in the loan summary.']);
        }

        $query = $this->posted($actor)
            ->whereBetween('period_end', [$criteria->from, $criteria->to])
            ->when($criteria->employeeIds !== [], static fn (Builder $rows) => $rows->whereIn('employee_id', $criteria->employeeIds));
        // Read one overflow row to refuse the WHOLE report before aggregating;
        // no count/read race can quietly turn a capped subset into a total.
        $payslips = $query->with('run:id,code,name,kind,period_start,period_end')
            ->orderBy('period_end')->orderBy('run_id')->orderBy('employee_id')->orderBy('id')
            ->limit(ReportDocument::MAX_ROWS + 1)->get();
        ReportDocument::guardSize($payslips->count());

        $ids = array_values($payslips->map(static fn (Payslip $row): string => $row->employee_id)->unique()->all());
        $employees = app(OperationalDirectory::class)->list($actor, array_values(array_unique([...$ids, ...$criteria->employeeIds])))->keyBy('id');
        $notes = [
            'Includes posted payslips whose period end falls from '.$criteria->from.' through '.$criteria->to.'. Draft payrolls are excluded.',
            'Amounts are stored payroll results in PHP. Employee names and Employee IDs are current; posted deduction and incentive names retain their issued labels.',
        ];
        if ($criteria->employeeIds !== []) {
            $selected = [];
            foreach ($criteria->employeeIds as $employeeId) {
                $employee = $employees->get($employeeId);
                if ($employee !== null) {
                    $selected[] = EmployeeIdentity::option($employee, PayrollData::employeeName($employee));
                }
            }
            if ($selected !== []) {
                $notes[] = 'Employee selection: '.implode('; ', $selected).'.';
            }
            if (count($selected) < count($criteria->employeeIds)) {
                $notes[] = 'Some selected employees are outside your permitted records or are unavailable.';
            }
        }

        return match ($criteria->type) {
            'payroll-register' => $this->register($payslips, $employees, $notes),
            'payslips' => $this->payslips($actor, $payslips, $employees, $notes),
            'government-contributions' => $this->contributions($payslips, $employees, $notes),
            'payroll-summary' => $this->summary($payslips, $notes),
            'loan-summary' => $this->loans($actor, $criteria, $payslips, $employees, $notes),
            default => throw ValidationException::withMessages(['type' => 'Choose a payroll report.']),
        };
    }

    /** Posted codes are deliberate selections, never guessed from a mutable catalog name.
     * @return array<string, string>
     */
    public function loanTypes(Access $actor): array
    {
        $this->authorize($actor);
        $rows = PayslipDeduction::query()
            ->whereIn('payslip_id', $this->posted($actor)->select('id'))
            ->select(['deduction_code', 'name'])->distinct()->orderBy('deduction_code')->orderBy('name')->get();
        $labels = [];
        foreach ($rows as $row) {
            $labels[$row->deduction_code][] = $row->name;
        }

        return array_map(static fn (array $names): string => implode(' / ', $names), $labels);
    }

    private function authorize(Access $actor): void
    {
        abort_unless($actor->hasModule(ModuleCode::REPORTS) && $actor->hasModule(ModuleCode::PAYROLL), 403);
    }

    /** @return Builder<Payslip> */
    private function posted(Access $actor): Builder
    {
        return Payslip::query()->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->where(static fn (Builder $rows) => $rows->whereNull('run_id')
                ->orWhereHas('run', static fn (Builder $runs) => $runs->where('status', PayrollRunStatus::FINALIZED)));
    }

    /** @param Collection<int, Payslip> $payslips
     * @param  Collection<string, DirectoryEmployee>  $employees
     * @param  list<string>  $notes
     */
    private function register(Collection $payslips, Collection $employees, array $notes): ReportDocument
    {
        $amounts = self::REGISTER_AMOUNTS;
        foreach (['late_undertime_deduction', 'absence_deduction', 'unpaid_leave_deduction'] as $key) {
            // Legacy columns remain readable when they carried a charge;
            // ordinary current registers need not print three zero columns.
            if (! $payslips->contains(static fn (Payslip $row): bool => (float) $row->getAttribute($key) !== 0.0)) {
                unset($amounts[$key]);
            }
        }
        $columns = ['Payroll', 'Payroll kind', 'Employee ID', 'Employee', 'Period from', 'Period to'];
        foreach ($amounts as $key => $label) {
            $columns[] = $label;
            if ($key === 'overtime_pay') {
                $columns[] = 'Overtime included in gross';
            }
        }
        $rows = [];
        foreach ($payslips as $payslip) {
            $row = $this->identity($payslip, $employees);
            foreach (array_keys($amounts) as $key) {
                $row[] = self::money($payslip->getAttribute($key));
                if ($key === 'overtime_pay') {
                    $row[] = $payslip->overtime_acknowledged ? 'Yes' : 'No';
                }
            }
            $rows[] = $row;
        }

        $totals = ['Totals', '', '', '', '', ''];
        $numericColumns = [];
        foreach (array_keys($amounts) as $key) {
            $numericColumns[] = count($totals);
            $totals[] = self::money($payslips->sum($key));
            if ($key === 'overtime_pay') {
                $totals[] = '';
            }
        }

        return new ReportDocument('Payroll Register', $columns, $rows, [
            ...$notes, 'Earnings and tax-treatment columns include component/subtotal figures; use the stored Gross pay, Total deductions and Net pay totals.',
            'Stored overtime pay forms part of gross only when Overtime included in gross is Yes. Legacy deduction columns appear only when a selected payslip contains a charge.',
        ], $this->registerSections($payslips, $employees, $amounts), $this->paySummary($payslips), $totals, $numericColumns);
    }

    /** @param Collection<int, Payslip> $payslips
     * @param  Collection<string, DirectoryEmployee>  $employees
     * @param  array<string, string>  $amounts
     * @return list<array{title: string, kind: 'table', columns: list<string>, rows: list<list<string>>, totals: list<string>, numericColumns: list<int>, notes: list<string>}>
     */
    private function registerSections(Collection $payslips, Collection $employees, array $amounts): array
    {
        if ($payslips->isEmpty()) {
            return [];
        }
        $sections = [];
        foreach ([
            'Payroll overview' => ['gross_pay', 'total_deductions', 'net_pay'],
            'Earnings detail' => ['basic_pay', 'allowances', 'overtime_pay', 'night_differential', 'leave_pay', 'other_earnings', 'bonus', 'thirteenth_month', 'exempt_benefits'],
            'Deductions detail' => ['sss', 'philhealth', 'pagibig', 'withholding_tax', 'late_deduction', 'undertime_deduction', 'other_deductions', 'custom_deductions', 'late_undertime_deduction', 'absence_deduction', 'unpaid_leave_deduction', 'total_deductions'],
        ] as $title => $fields) {
            $fields = array_values(array_filter($fields, static fn (string $key): bool => isset($amounts[$key])));
            $columns = ['Employee ID', 'Employee', 'Payroll / period'];
            $totals = ['Totals', '', ''];
            $numericColumns = [];
            foreach ($fields as $key) {
                $columns[] = $amounts[$key];
                $numericColumns[] = count($totals);
                $totals[] = self::money($payslips->sum($key));
                if ($key === 'overtime_pay') {
                    $columns[] = 'Included in gross';
                    $totals[] = '';
                }
            }
            $rows = [];
            foreach ($payslips as $payslip) {
                $employee = $employees->get($payslip->employee_id);
                $row = [EmployeeIdentity::number($employee), PayrollData::employeeName($employee), $this->runName($payslip).' / '.$this->kind($payslip).' / '.$payslip->period_start->format('Y-m-d').' – '.$payslip->period_end->format('Y-m-d')];
                foreach ($fields as $key) {
                    $row[] = self::money($payslip->getAttribute($key));
                    if ($key === 'overtime_pay') {
                        $row[] = $payslip->overtime_acknowledged ? 'Yes' : 'No';
                    }
                }
                $rows[] = $row;
            }
            $sections[] = [
                'title' => $title, 'kind' => 'table', 'columns' => $columns, 'rows' => $rows,
                'totals' => $totals, 'numericColumns' => $numericColumns,
                'notes' => $title === 'Earnings detail' ? ['Component and tax-treatment columns can overlap. Gross pay in the overview is the stored payroll total.'] : [],
            ];
        }

        return $sections;
    }

    /** @param Collection<int, Payslip> $payslips
     * @param  Collection<string, DirectoryEmployee>  $employees
     * @param  list<string>  $notes
     */
    private function contributions(Collection $payslips, Collection $employees, array $notes): ReportDocument
    {
        $rows = [];
        foreach ($payslips->groupBy('employee_id') as $employeeId => $group) {
            $employee = $employees->get($employeeId);
            $totals = [];
            foreach (array_keys(self::CONTRIBUTIONS) as $key) {
                $totals[] = self::money($group->sum($key));
            }
            $rows[] = [EmployeeIdentity::number($employee), PayrollData::employeeName($employee), ...$totals];
        }

        $totals = ['Totals', ''];
        foreach (array_keys(self::CONTRIBUTIONS) as $key) {
            $totals[] = self::money($payslips->sum($key));
        }

        return new ReportDocument('Government Contribution Reports', [
            'Employee ID', 'Employee', ...array_values(self::CONTRIBUTIONS),
        ], $rows, [...$notes, 'Employee and employer shares are separate. This is an internal contribution summary, not a government filing form.'], [], [
            ['label' => 'Employees', 'value' => (string) count($rows)],
            ['label' => 'Employee contributions', 'value' => self::currency($payslips->sum('sss') + $payslips->sum('philhealth') + $payslips->sum('pagibig'))],
            ['label' => 'Employer contributions', 'value' => self::currency($payslips->sum('sss_er') + $payslips->sum('philhealth_er') + $payslips->sum('pagibig_er'))],
            ['label' => 'Withholding tax', 'value' => self::currency($payslips->sum('withholding_tax'))],
        ], $totals, range(2, 8));
    }

    /** @param Collection<int, Payslip> $payslips
     * @param  list<string>  $notes
     */
    private function summary(Collection $payslips, array $notes): ReportDocument
    {
        $rows = [];
        $groups = $payslips->groupBy(static fn (Payslip $row): string => $row->run_id ?? 'legacy:'.$row->period_start->format('Y-m-d').':'.$row->period_end->format('Y-m-d'));
        foreach ($groups as $group) {
            $first = $group->first();
            if ($first === null) {
                continue;
            }
            $rows[] = [
                $this->runName($first), $this->kind($first),
                ($first->run->period_start ?? $first->period_start)->format('Y-m-d'),
                ($first->run->period_end ?? $first->period_end)->format('Y-m-d'),
                (string) $group->count(), (string) $group->pluck('employee_id')->unique()->count(),
                self::money($group->sum('gross_pay')), self::money($group->sum('total_deductions')), self::money($group->sum('net_pay')),
                self::money($group->sum('sss_er') + $group->sum('philhealth_er') + $group->sum('pagibig_er')),
            ];
        }

        return new ReportDocument('Payroll Summary', [
            'Payroll', 'Payroll kind', 'Payroll period from', 'Payroll period to', 'Payslips', 'Employees',
            'Gross pay', 'Total deductions', 'Net pay', 'Employer contributions',
        ], $rows, [...$notes, 'Each payroll total includes only payslips matching the selected dates and employees. Legacy payslips are grouped by period.', 'The total employee count is distinct across the whole report; an employee appearing in several payrolls is counted once.'], [], $this->paySummary($payslips), [
            'Totals', '', '', '', (string) $payslips->count(), (string) $payslips->pluck('employee_id')->unique()->count(),
            self::money($payslips->sum('gross_pay')), self::money($payslips->sum('total_deductions')), self::money($payslips->sum('net_pay')), $this->employerTotal($payslips),
        ], range(4, 9));
    }

    /** @param Collection<int, Payslip> $payslips
     * @param  Collection<string, DirectoryEmployee>  $employees
     * @param  list<string>  $notes
     */
    private function loans(Access $actor, ReportCriteria $criteria, Collection $payslips, Collection $employees, array $notes): ReportDocument
    {
        $ids = array_values($payslips->map(static fn (Payslip $row): string => $row->id)->all());
        $lines = app(PayslipRecords::class)->customDeductions($actor, $ids);
        $groups = [];
        foreach ($payslips as $payslip) {
            foreach ($lines[$payslip->id] ?? [] as $line) {
                if (! in_array($line->deduction_code, $criteria->deductionCodes, true)) {
                    continue;
                }
                $key = json_encode([$payslip->employee_id, $line->deduction_code, $line->name], JSON_THROW_ON_ERROR);
                $groups[$key] ??= ['employee_id' => $payslip->employee_id, 'code' => $line->deduction_code, 'name' => $line->name, 'amount' => 0.0, 'payslips' => []];
                $groups[$key]['amount'] += $line->amount;
                $groups[$key]['payslips'][$payslip->id] = true;
            }
        }
        $rows = [];
        foreach ($groups as $group) {
            $employee = $employees->get($group['employee_id']);
            $rows[] = [EmployeeIdentity::number($employee), PayrollData::employeeName($employee), $group['code'], $group['name'], (string) count($group['payslips']), self::money($group['amount'])];
        }
        $matchedPayslips = [];
        $matchedEmployees = [];
        $deducted = 0.0;
        foreach ($groups as $group) {
            $matchedEmployees[$group['employee_id']] = true;
            $matchedPayslips += $group['payslips'];
            $deducted += $group['amount'];
        }

        return new ReportDocument('Loan Summary', ['Employee ID', 'Employee', 'Deduction code', 'Posted deduction name', 'Payslips', 'Amount deducted'], $rows, [
            ...$notes, 'Includes only the selected deduction codes: '.implode(', ', $criteria->deductionCodes).'.',
            'These are payroll repayments only; this report infers no remaining balance. A loan set up as a recurring line with a total shows its paid and remaining balance under Records › Payroll Settings › Employee Pay Settings.',
            'The total payslip count is distinct across selected deductions; a payslip with several selected loan deductions is counted once.',
        ], [], [
            ['label' => 'Employees with deductions', 'value' => (string) count($matchedEmployees)],
            ['label' => 'Payslips with deductions', 'value' => (string) count($matchedPayslips)],
            ['label' => 'Selected deduction types', 'value' => (string) count($criteria->deductionCodes)],
            ['label' => 'Amount deducted', 'value' => self::currency($deducted)],
        ], ['Totals', '', '', '', (string) count($matchedPayslips), self::money($deducted)], [4, 5]);
    }

    /** @param Collection<int, Payslip> $payslips
     * @param  Collection<string, DirectoryEmployee>  $employees
     * @param  list<string>  $notes
     */
    private function payslips(Access $actor, Collection $payslips, Collection $employees, array $notes): ReportDocument
    {
        $records = app(PayslipRecords::class);
        $ids = array_values($payslips->map(static fn (Payslip $row): string => $row->id)->all());
        $incentives = $records->incentives($actor, $ids);
        $deductions = $records->customDeductions($actor, $ids);
        $rows = [];
        $sections = [];
        foreach ($payslips as $payslip) {
            $rows[] = [...$this->identity($payslip, $employees), ...$this->amounts($payslip, ['gross_pay', 'total_deductions', 'net_pay'])];
            $lines = $this->payslipLines($payslip, $employees->get($payslip->employee_id), $incentives[$payslip->id] ?? [], $deductions[$payslip->id] ?? []);
            $sections[] = [
                'title' => PayrollData::employeeName($employees->get($payslip->employee_id)).' · '.$payslip->period_start->format('Y-m-d').' to '.$payslip->period_end->format('Y-m-d'),
                'kind' => 'payslip',
                'columns' => ['Item', 'Value'],
                'rows' => $lines,
                'identity' => array_map(static fn (array $line): array => ['label' => $line[0], 'value' => $line[1]], array_slice($lines, 0, 7)),
                'highlights' => [
                    ['label' => 'Gross pay', 'value' => self::currency($payslip->gross_pay)],
                    ['label' => 'Total deductions', 'value' => self::currency($payslip->total_deductions)],
                    ['label' => 'Net pay', 'value' => self::currency($payslip->net_pay)],
                ],
                'groups' => $this->payslipGroups(array_slice($lines, 7)),
                'notes' => ['All amounts are PHP. Earnings include labeled components and subtotals; the stored Gross pay, Total deductions and Net pay are authoritative.'],
            ];
        }

        return new ReportDocument('Payslips', ['Payroll', 'Payroll kind', 'Employee ID', 'Employee', 'Period from', 'Period to', 'Gross pay', 'Total deductions', 'Net pay'], $rows, $notes, $sections, $this->paySummary($payslips), [
            'Totals', '', '', '', '', '', self::money($payslips->sum('gross_pay')), self::money($payslips->sum('total_deductions')), self::money($payslips->sum('net_pay')),
        ], [6, 7, 8]);
    }

    /** @param list<list<string>> $lines
     * @return list<array{title: string, rows: list<list<string>>}>
     */
    private function payslipGroups(array $lines): array
    {
        $groups = ['Earnings' => [], 'Deductions' => [], 'Time and rate details' => [], 'Tax treatment' => [], 'Employer contributions' => []];
        foreach ($lines as $line) {
            $label = $line[0];
            if (in_array($label, ['Gross pay', 'Total deductions', 'Net pay'], true)) {
                // These issued totals have their own statement highlights.
                continue;
            }
            $group = match (true) {
                // Issued catalog names are free text; classify by the stable
                // line kind before inspecting any label they can contain.
                str_starts_with($label, 'Incentive detail:') => 'Earnings',
                str_starts_with($label, 'Deduction detail:') => 'Deductions',
                in_array($label, ['Daily rate', 'Work days', 'Holidays worked', 'Rest days worked', 'Absence days', 'Paid leave days', 'Unpaid leave days', 'Days paid in advance', 'Overtime hours', 'Late minutes', 'Undertime minutes'], true) => 'Time and rate details',
                str_starts_with($label, 'Tax treatment:') || str_starts_with($label, 'Incentive tax treatment:') || in_array($label, ['Stored taxable compensation', 'Overtime included in gross'], true) => 'Tax treatment',
                in_array($label, ['SSS employer', 'PhilHealth employer', 'Pag-IBIG employer'], true) => 'Employer contributions',
                in_array($label, ['SSS employee', 'PhilHealth employee', 'Pag-IBIG employee', 'Withholding tax', 'Other deductions', 'Named deductions subtotal', 'Late deduction', 'Undertime deduction', 'Legacy late / undertime deduction', 'Legacy absence deduction', 'Legacy unpaid leave deduction'], true) => 'Deductions',
                default => 'Earnings',
            };
            $groups[$group][] = $line;
        }
        $sections = [];
        foreach ($groups as $title => $rows) {
            $sections[] = ['title' => $title, 'rows' => $rows];
        }

        return $sections;
    }

    /** @param list<PayslipIncentiveView> $incentives
     * @param  list<PayslipDeductionView>  $deductions
     * @return list<list<string>>
     */
    private function payslipLines(Payslip $payslip, ?DirectoryEmployee $employee, array $incentives, array $deductions): array
    {
        $rows = [
            ['Payroll', $this->runName($payslip)], ['Payroll kind', $this->kind($payslip)],
            ['Employee ID', EmployeeIdentity::number($employee)], ['Employee', PayrollData::employeeName($employee)],
            ['Position', $employee->position ?? '—'], ['Period from', $payslip->period_start->format('Y-m-d')], ['Period to', $payslip->period_end->format('Y-m-d')],
        ];
        foreach ([
            'daily_rate' => 'Daily rate', 'work_days' => 'Work days', 'holiday_days' => 'Holidays worked', 'rest_days' => 'Rest days worked',
            'absence_days' => 'Absence days', 'paid_leave_days' => 'Paid leave days', 'unpaid_leave_days' => 'Unpaid leave days',
            'presumed_days' => 'Days paid in advance',
            'work_days_pay' => 'Work days pay', 'holiday_pay' => 'Holiday pay', 'rest_day_pay' => 'Rest day pay',
            'basic_pay' => 'Basic pay subtotal', 'allowances' => 'Allowances', 'total_base_pay' => 'Base pay with allowances subtotal',
            'overtime_hours' => 'Overtime hours', 'overtime_pay' => 'Overtime pay', 'night_differential' => 'Night differential',
            'leave_pay' => 'Leave pay', 'other_earnings' => 'Other earnings', 'settlement' => 'Attendance settlement', 'bonus' => 'Taxable incentives',
            'thirteenth_month' => '13th month pay', 'exempt_benefits' => 'Tax treatment: exempt benefits',
            'taxable_benefits_excess' => 'Tax treatment: benefits over exemption ceiling', 'gross_pay' => 'Gross pay',
            'sss' => 'SSS employee', 'philhealth' => 'PhilHealth employee', 'pagibig' => 'Pag-IBIG employee',
            'withholding_tax' => 'Withholding tax', 'other_deductions' => 'Other deductions', 'custom_deductions' => 'Named deductions subtotal',
            'late_minutes' => 'Late minutes', 'late_deduction' => 'Late deduction', 'undertime_minutes' => 'Undertime minutes',
            'undertime_deduction' => 'Undertime deduction', 'late_undertime_deduction' => 'Legacy late / undertime deduction',
            'absence_deduction' => 'Legacy absence deduction', 'unpaid_leave_deduction' => 'Legacy unpaid leave deduction',
            'total_deductions' => 'Total deductions', 'net_pay' => 'Net pay',
            'sss_er' => 'SSS employer', 'philhealth_er' => 'PhilHealth employer', 'pagibig_er' => 'Pag-IBIG employer',
        ] as $key => $label) {
            $rows[] = [$label, self::money($payslip->getAttribute($key))];
        }
        $rows[] = ['Overtime included in gross', $payslip->overtime_acknowledged ? 'Yes' : 'No'];
        $rows[] = ['Stored taxable compensation', $payslip->taxable_compensation === null ? 'Not recorded' : self::money($payslip->taxable_compensation)];
        foreach ($incentives as $line) {
            $rows[] = ['Incentive detail: '.$line->name.' ('.$line->type_code.')', self::money($line->amount)];
            $rows[] = ['Incentive tax treatment: '.$line->name, $line->is_taxable ? 'Taxable' : 'Tax-exempt; over-ceiling portion PHP '.self::money($line->over_ceiling)];
        }
        foreach ($deductions as $line) {
            $rows[] = ['Deduction detail: '.$line->name.' ('.$line->deduction_code.')', self::money($line->amount)];
        }

        return $rows;
    }

    /** @param Collection<string, DirectoryEmployee> $employees
     * @return list<string>
     */
    private function identity(Payslip $payslip, Collection $employees): array
    {
        $employee = $employees->get($payslip->employee_id);

        return [$this->runName($payslip), $this->kind($payslip), EmployeeIdentity::number($employee), PayrollData::employeeName($employee), $payslip->period_start->format('Y-m-d'), $payslip->period_end->format('Y-m-d')];
    }

    private function runName(Payslip $payslip): string
    {
        return $payslip->run === null ? 'Legacy payroll' : $payslip->run->code.' · '.$payslip->run->name;
    }

    private function kind(Payslip $payslip): string
    {
        return PayrollRunKind::LABELS[$payslip->run->kind ?? PayrollRunKind::REGULAR] ?? 'Payroll';
    }

    /** @param Collection<int, Payslip> $payslips
     * @return list<array{label: string, value: string}>
     */
    private function paySummary(Collection $payslips): array
    {
        return [
            ['label' => 'Payslips', 'value' => (string) $payslips->count()],
            ['label' => 'Employees', 'value' => (string) $payslips->pluck('employee_id')->unique()->count()],
            ['label' => 'Gross pay', 'value' => self::currency($payslips->sum('gross_pay'))],
            ['label' => 'Total deductions', 'value' => self::currency($payslips->sum('total_deductions'))],
            ['label' => 'Net pay', 'value' => self::currency($payslips->sum('net_pay'))],
        ];
    }

    /** @param Collection<int, Payslip> $payslips */
    private function employerTotal(Collection $payslips): string
    {
        return self::money($payslips->sum('sss_er') + $payslips->sum('philhealth_er') + $payslips->sum('pagibig_er'));
    }

    /** @param list<string> $keys
     * @return list<string>
     */
    private function amounts(Payslip $payslip, array $keys): array
    {
        return array_map(static fn (string $key): string => self::money($payslip->getAttribute($key)), $keys);
    }

    private static function money(mixed $amount): string
    {
        return number_format(Money::round($amount), 2, '.', '');
    }

    private static function currency(mixed $amount): string
    {
        return 'PHP '.number_format(Money::round($amount), 2, '.', ',');
    }
}
