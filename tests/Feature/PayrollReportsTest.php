<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\PayslipIncentive;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Queries\PayrollReports;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Financial reports preserve posted amounts, source grants and employee scope through every aggregate and detail. */

/** @param list<string> $modules */
function payrollReportActor(array $modules = [ModuleCode::REPORTS, ModuleCode::PAYROLL], bool $all = true, ?EmployeeProfile $employee = null): Access
{
    $role = Role::create(['name' => 'Payroll report '.Str::random(8), 'all_data' => $all, 'is_system' => false]);
    foreach ($modules as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

function payrollReportEmployee(string $code): EmployeeProfile
{
    return EmployeeProfile::create([
        'employee_code' => 'PR-'.$code, 'employee_id_no' => 'EMP-'.$code, 'first_name' => 'Report', 'last_name' => $code,
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'monthly_rate' => 22000,
    ]);
}

function payrollReportRun(string $code, string $status = PayrollRunStatus::FINALIZED, string $kind = PayrollRunKind::REGULAR): PayrollRun
{
    return PayrollRun::create([
        'code' => $code, 'name' => $code.' payroll', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'status' => $status, 'kind' => $kind,
    ]);
}

/** @param array<string, mixed> $values */
function payrollReportPayslip(EmployeeProfile $employee, ?PayrollRun $run = null, array $values = []): Payslip
{
    return Payslip::create(array_replace([
        'employee_id' => $employee->id, 'run_id' => $run?->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
        'basic_pay' => 1200, 'gross_pay' => 1234.56, 'total_deductions' => 234.56, 'net_pay' => 1000,
        'sss' => 50, 'sss_er' => 100, 'philhealth' => 25, 'philhealth_er' => 25, 'pagibig' => 10, 'pagibig_er' => 10,
        'withholding_tax' => 15,
    ], $values));
}

/** @param array<string, mixed> $values */
function payrollReportCriteria(string $type = 'payroll-register', array $values = []): ReportCriteria
{
    return ReportCriteria::from(array_replace(['type' => $type, 'from' => '2026-09-01', 'to' => '2026-09-30'], $values));
}

/** @return list<array<string, string>> */
function payrollReportRows(ReportDocument $report): array
{
    return array_map(static fn (array $row): array => array_combine($report->columns, $row), $report->rows);
}

it('requires both Reports and Payroll when called directly', function (array $modules) {
    $actor = payrollReportActor(array_values($modules));
    expect(fn () => app(PayrollReports::class)->generate($actor, payrollReportCriteria()))->toThrow(HttpException::class)
        ->and(fn () => app(PayrollReports::class)->loanTypes($actor))->toThrow(HttpException::class);
})->with([
    'no grants' => [[]],
    'reports without payroll' => [[ModuleCode::REPORTS]],
    'payroll without reports' => [[ModuleCode::PAYROLL]],
    'unrelated organization-wide access' => [[ModuleCode::REPORTS, ModuleCode::EDTR]],
]);

it('keeps every payroll report within the linked employee scope and refuses foreign filters', function (string $type) {
    $own = payrollReportEmployee('Own');
    $other = payrollReportEmployee('Other');
    foreach ([$own, $other] as $employee) {
        $payslip = payrollReportPayslip($employee);
        PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0, 'deduction_code' => 'loan', 'name' => 'Loan', 'amount' => 125]);
    }
    $actor = payrollReportActor(all: false, employee: $own);
    $query = app(PayrollReports::class);
    $report = $query->generate($actor, payrollReportCriteria($type, ['deductionCodes' => ['loan']]));
    expect($report->rows)->toHaveCount(1);
    if ($type === 'payroll-summary') {
        expect(payrollReportRows($report)[0]['Gross pay'])->toBe('1234.56')
            ->and(payrollReportRows($report)[0]['Employees'])->toBe('1');
    } else {
        expect(payrollReportRows($report)[0]['Employee ID'])->toBe('EMP-Own');
    }
    $selected = $query->generate($actor, payrollReportCriteria($type, ['employeeId' => $own->id, 'deductionCodes' => ['loan']]));
    expect(implode(' ', $selected->notes))->toContain('Employee selection: EMP-Own — Report Own.');
    $mixed = $query->generate($actor, payrollReportCriteria($type, ['employeeIds' => [$own->id, $other->id], 'deductionCodes' => ['loan']]));
    expect($mixed->rows)->toBe($selected->rows)
        ->and($mixed->summary)->toBe($selected->summary)
        ->and($mixed->totals)->toBe($selected->totals)
        ->and($mixed->sections)->toBe($selected->sections)
        ->and(implode(' ', $mixed->notes))->toContain('Employee selection: EMP-Own — Report Own.')
        ->not->toContain('EMP-Other', 'Report Other');
    $foreign = $query->generate($actor, payrollReportCriteria($type, ['employeeId' => $other->id, 'deductionCodes' => ['loan']]));
    expect($foreign->rows)->toBe([])->and($foreign->sections)->toBe([])
        ->and(implode(' ', $foreign->notes))->not->toContain('EMP-Other', 'Report Other');
})->with(['payroll-register', 'payslips', 'government-contributions', 'payroll-summary', 'loan-summary']);

it('includes only selected employee postings in every payroll report and its download criteria', function (string $type) {
    $one = payrollReportEmployee('SelectedOne');
    $two = payrollReportEmployee('SelectedTwo');
    $three = payrollReportEmployee('ExcludedThree');
    $run = payrollReportRun('SELECTED');
    foreach ([$one, $two, $three] as $employee) {
        $payslip = payrollReportPayslip($employee, $run);
        PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0, 'deduction_code' => 'loan', 'name' => 'Posted loan', 'amount' => 125]);
    }
    $criteria = payrollReportCriteria($type, ['employeeIds' => [$one->id, $two->id], 'deductionCodes' => ['loan']]);
    $actor = payrollReportActor();
    $query = app(PayrollReports::class);
    $report = $query->generate($actor, $criteria);
    $download = $query->generate($actor, ReportCriteria::from($criteria->query()));
    $rows = payrollReportRows($report);

    expect($download->rows)->toBe($report->rows)
        ->and($download->sections)->toBe($report->sections)
        ->and(implode(' ', $report->notes))->toContain('EMP-SelectedOne — Report SelectedOne', 'EMP-SelectedTwo — Report SelectedTwo')
        ->not->toContain('ExcludedThree');
    if ($type === 'payroll-summary') {
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['Payslips'])->toBe('2')->and($rows[0]['Employees'])->toBe('2')
            ->and($rows[0]['Gross pay'])->toBe('2469.12')
            ->and($rows[0]['Total deductions'])->toBe('469.12')->and($rows[0]['Net pay'])->toBe('2000.00')
            ->and($rows[0]['Employer contributions'])->toBe('270.00');
    } else {
        expect($rows)->toHaveCount(2)
            ->and(array_column($rows, 'Employee ID'))->toEqualCanonicalizing(['EMP-SelectedOne', 'EMP-SelectedTwo']);
    }
    if ($type === 'government-contributions') {
        expect(array_column($rows, 'SSS employee'))->toBe(['50.00', '50.00']);
    }
    if ($type === 'loan-summary') {
        expect(array_column($rows, 'Amount deducted'))->toBe(['125.00', '125.00']);
    }
    if ($type === 'payslips') {
        expect($report->sections)->toHaveCount(2);
    }
})->with(['payroll-register', 'payslips', 'government-contributions', 'payroll-summary', 'loan-summary']);

it('returns no financial rows or deduction choices to an unlinked scoped account', function () {
    $payslip = payrollReportPayslip(payrollReportEmployee('Private'));
    PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0, 'deduction_code' => 'private', 'name' => 'Private loan', 'amount' => 200]);
    $actor = payrollReportActor(all: false);
    $query = app(PayrollReports::class);
    expect($query->generate($actor, payrollReportCriteria())->rows)->toBe([])
        ->and($query->loanTypes($actor))->toBe([]);
});

it('selects inclusive period ends and preserves legacy and 13th month postings while excluding drafts', function () {
    $employee = payrollReportEmployee('Periods');
    payrollReportPayslip($employee, values: ['period_start' => '2026-08-20', 'period_end' => '2026-09-01']);
    payrollReportPayslip($employee, payrollReportRun('REG'), ['period_end' => '2026-09-30']);
    payrollReportPayslip($employee, payrollReportRun('YEAR', kind: PayrollRunKind::THIRTEENTH_MONTH), ['period_end' => '2026-09-30', 'thirteenth_month' => 1200]);
    payrollReportPayslip($employee, payrollReportRun('DRAFT', PayrollRunStatus::DRAFT));
    payrollReportPayslip($employee, values: ['period_start' => '2026-08-01', 'period_end' => '2026-08-31']);
    payrollReportPayslip($employee, values: ['period_start' => '2026-09-01', 'period_end' => '2026-10-01']);
    $report = app(PayrollReports::class)->generate(payrollReportActor(), payrollReportCriteria());
    $rows = payrollReportRows($report);
    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'Payroll'))->toContain('Legacy payroll', 'REG · REG payroll', 'YEAR · YEAR payroll')
        ->and(array_column($rows, 'Payroll kind'))->toContain('Basic Payroll', '13th Month Payroll');
});

it('aggregates stored contribution shares separately without recalculating changed pay settings', function () {
    $employee = payrollReportEmployee('Shares');
    payrollReportPayslip($employee);
    payrollReportPayslip($employee, values: ['period_start' => '2026-09-16', 'period_end' => '2026-09-30', 'sss' => 70, 'sss_er' => 150]);
    $employee->update(['monthly_rate' => 100000, 'fixed_sss_ee' => 999, 'allow_sss' => false]);
    $report = app(PayrollReports::class)->generate(payrollReportActor(), payrollReportCriteria('government-contributions'));
    expect(payrollReportRows($report))->toBe([[
        'Employee ID' => 'EMP-Shares', 'Employee' => 'Report Shares',
        'SSS employee' => '120.00', 'SSS employer' => '250.00',
        'PhilHealth employee' => '50.00', 'PhilHealth employer' => '50.00',
        'Pag-IBIG employee' => '20.00', 'Pag-IBIG employer' => '20.00', 'Withholding tax' => '30.00',
    ]])->and($report->totals)->toBe(['Totals', '', '120.00', '250.00', '50.00', '50.00', '20.00', '20.00', '30.00'])
        ->and(array_column($report->summary, 'value', 'label'))->toBe([
            'Employees' => '1', 'Employee contributions' => 'PHP 190.00', 'Employer contributions' => 'PHP 320.00', 'Withholding tax' => 'PHP 30.00',
        ]);
});

it('summarizes each payroll with only selected employee postings', function () {
    $one = payrollReportEmployee('SummaryOne');
    $two = payrollReportEmployee('SummaryTwo');
    $run = payrollReportRun('SUMMARY');
    payrollReportPayslip($one, $run);
    payrollReportPayslip($two, $run, ['gross_pay' => 2000, 'total_deductions' => 200, 'net_pay' => 1800]);
    $actor = payrollReportActor();
    $query = app(PayrollReports::class);
    $all = payrollReportRows($query->generate($actor, payrollReportCriteria('payroll-summary')))[0];
    expect($all['Payslips'])->toBe('2')->and($all['Employees'])->toBe('2')
        ->and($all['Gross pay'])->toBe('3234.56')->and($all['Total deductions'])->toBe('434.56')
        ->and($all['Net pay'])->toBe('2800.00')->and($all['Employer contributions'])->toBe('270.00');
    $filtered = payrollReportRows($query->generate($actor, payrollReportCriteria('payroll-summary', ['employeeId' => $one->id])))[0];
    expect($filtered['Payslips'])->toBe('1')->and($filtered['Gross pay'])->toBe('1234.56');
});

it('makes every register deduction visible and distinguishes stored overtime from paid overtime', function () {
    $employee = payrollReportEmployee('Register');
    payrollReportPayslip($employee, values: [
        'basic_pay' => 1000, 'gross_pay' => 1000, 'total_deductions' => 340, 'net_pay' => 660,
        'overtime_pay' => 500, 'overtime_acknowledged' => false,
        'late_deduction' => 20, 'undertime_deduction' => 30, 'other_deductions' => 40, 'custom_deductions' => 50,
        'late_undertime_deduction' => 35, 'absence_deduction' => 45, 'unpaid_leave_deduction' => 20,
    ]);
    $actor = payrollReportActor();
    $query = app(PayrollReports::class);
    $report = $query->generate($actor, payrollReportCriteria());
    $row = payrollReportRows($report)[0];
    expect($row['Late deduction'])->toBe('20.00')->and($row['Undertime deduction'])->toBe('30.00')
        ->and($row['Other deductions'])->toBe('40.00')->and($row['Named deductions subtotal'])->toBe('50.00')
        ->and($row['Legacy late / undertime deduction'])->toBe('35.00')
        ->and($row['Legacy absence deduction'])->toBe('45.00')->and($row['Legacy unpaid leave deduction'])->toBe('20.00')
        ->and($row['Total deductions'])->toBe('340.00')->and($row['Net pay'])->toBe('660.00')
        ->and($row['Stored overtime pay'])->toBe('500.00')->and($row['Overtime included in gross'])->toBe('No')
        ->and($row['Gross pay'])->toBe('1000.00');

    $current = payrollReportEmployee('CurrentRegister');
    payrollReportPayslip($current, values: ['overtime_pay' => 50, 'overtime_acknowledged' => true]);
    $currentReport = $query->generate($actor, payrollReportCriteria(values: ['employeeId' => $current->id]));
    expect($currentReport->columns)->not->toContain('Legacy late / undertime deduction', 'Legacy absence deduction', 'Legacy unpaid leave deduction')
        ->and(payrollReportRows($currentReport)[0]['Overtime included in gross'])->toBe('Yes');

    expect(array_column($report->sections, 'title'))->toBe(['Payroll overview', 'Earnings detail', 'Deductions detail']);
    foreach ($report->sections as $section) {
        expect($section['totals'] ?? [])->toHaveCount(count($section['columns']))
            ->and($section['rows'][0])->toHaveCount(count($section['columns']));
    }
    $overview = array_combine($report->sections[0]['columns'], $report->sections[0]['totals'] ?? []);
    expect($overview['Gross pay'])->toBe('1000.00')->and($overview['Total deductions'])->toBe('340.00')->and($overview['Net pay'])->toBe('660.00');
});

it('reports only explicitly selected posted loan lines and keeps their issued names after catalog edits', function () {
    $employee = payrollReportEmployee('Loans');
    $catalog = PayrollDeduction::create(['code' => 'loan', 'name' => 'Original loan']);
    $payslip = payrollReportPayslip($employee);
    PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0, 'deduction_code' => 'loan', 'name' => 'Original loan', 'amount' => 125.25]);
    PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 1, 'deduction_code' => 'uniform', 'name' => 'Uniform', 'amount' => 500]);
    $second = payrollReportPayslip($employee, values: ['period_start' => '2026-09-16', 'period_end' => '2026-09-30']);
    PayslipDeduction::create(['payslip_id' => $second->id, 'position' => 0, 'deduction_code' => 'loan', 'name' => 'Original loan', 'amount' => 150.75]);
    $catalog->update(['name' => 'Renamed catalog']);
    $actor = payrollReportActor();
    $query = app(PayrollReports::class);
    expect($query->loanTypes($actor))->toBe(['loan' => 'Original loan', 'uniform' => 'Uniform']);
    $report = $query->generate($actor, payrollReportCriteria('loan-summary', ['deductionCodes' => ['loan']]));
    $rows = payrollReportRows($report);
    expect($rows)->toHaveCount(1)->and($rows[0]['Posted deduction name'])->toBe('Original loan')
        ->and($rows[0]['Amount deducted'])->toBe('276.00')->and($rows[0]['Payslips'])->toBe('2')
        ->and(implode(' ', $report->notes))->toContain('no loan principal or outstanding-balance ledger');
    expect(fn () => $query->generate($actor, payrollReportCriteria('loan-summary')))->toThrow(ValidationException::class);
});

it('prints stored complete payslip figures and copied incentive and deduction details', function () {
    $employee = payrollReportEmployee('Payslip');
    $payslip = payrollReportPayslip($employee, values: [
        'overtime_pay' => 45.25, 'overtime_acknowledged' => false, 'other_deductions' => 20,
        'late_minutes' => 5, 'late_deduction' => 10, 'custom_deductions' => 100, 'taxable_compensation' => null,
    ]);
    PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0, 'deduction_code' => 'loan', 'name' => 'Posted loan', 'amount' => 100]);
    PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 1, 'deduction_code' => 'INC_TAX', 'name' => 'Incentive tax adjustment employer', 'amount' => 25]);
    PayslipIncentive::create(['payslip_id' => $payslip->id, 'position' => 0, 'type_code' => 'INC', 'name' => 'Posted bonus', 'is_taxable' => false, 'amount' => 75, 'over_ceiling' => 25]);
    PayslipIncentive::create(['payslip_id' => $payslip->id, 'position' => 1, 'type_code' => 'deduction-refund', 'name' => 'Tax deduction refund', 'is_taxable' => true, 'amount' => 85, 'over_ceiling' => 0]);
    $report = app(PayrollReports::class)->generate(payrollReportActor(), payrollReportCriteria('payslips'));
    expect($report->rows)->toHaveCount(1)->and($report->sections)->toHaveCount(1);
    $details = array_column($report->sections[0]['rows'], 1, 0);
    expect($details['Overtime pay'])->toBe('45.25')->and($details['Overtime included in gross'])->toBe('No')
        ->and($details['Stored taxable compensation'])->toBe('Not recorded')->and($details['Late deduction'])->toBe('10.00')
        ->and($details['Deduction detail: Posted loan (loan)'])->toBe('100.00')
        ->and($details['Incentive detail: Posted bonus (INC)'])->toBe('75.00')
        ->and($details['Incentive tax treatment: Posted bonus'])->toContain('25.00')
        ->and($details['Net pay'])->toBe('1000.00');

    $section = $report->sections[0];
    $statement = [];
    foreach ($section['identity'] ?? [] as $item) {
        $statement[$item['label']] = $item['value'];
    }
    foreach ($section['highlights'] ?? [] as $item) {
        $statement[$item['label']] = str_replace(['PHP ', ','], '', $item['value']);
    }
    foreach ($section['groups'] ?? [] as $group) {
        foreach ($group['rows'] as $line) {
            $statement[$line[0]] = $line[1];
        }
    }
    expect($section['kind'] ?? '')->toBe('payslip')
        ->and(array_column($section['groups'] ?? [], 'title'))->toBe(['Earnings', 'Deductions', 'Time and rate details', 'Tax treatment', 'Employer contributions'])
        ->and(array_column($section['highlights'] ?? [], 'value', 'label')['Net pay'])->toBe('PHP 1,000.00')
        ->and($statement)->toEqual($details);

    $groups = collect($section['groups'] ?? [])->keyBy('title');
    expect(array_column($groups->get('Earnings')['rows'] ?? [], 1, 0)['Incentive detail: Tax deduction refund (deduction-refund)'])->toBe('85.00')
        ->and(array_column($groups->get('Deductions')['rows'] ?? [], 1, 0)['Deduction detail: Incentive tax adjustment employer (INC_TAX)'])->toBe('25.00');
});

it('counts employees and selected loan payslips once across repeated report groups', function () {
    $employee = payrollReportEmployee('Distinct');
    $first = payrollReportPayslip($employee, payrollReportRun('DISTINCT-A'));
    $second = payrollReportPayslip($employee, payrollReportRun('DISTINCT-B'));
    foreach ([$first, $second] as $payslip) {
        PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 0, 'deduction_code' => 'loan-a', 'name' => 'Loan A', 'amount' => 100]);
        PayslipDeduction::create(['payslip_id' => $payslip->id, 'position' => 1, 'deduction_code' => 'loan-b', 'name' => 'Loan B', 'amount' => 25]);
    }
    $actor = payrollReportActor();
    $query = app(PayrollReports::class);
    $payroll = $query->generate($actor, payrollReportCriteria('payroll-summary'));
    $loans = $query->generate($actor, payrollReportCriteria('loan-summary', ['deductionCodes' => ['loan-a', 'loan-b']]));

    expect($payroll->totals)->toBe(['Totals', '', '', '', '2', '1', '2469.12', '469.12', '2000.00', '270.00'])
        ->and($loans->totals)->toBe(['Totals', '', '', '', '2', '250.00'])
        ->and(array_column($loans->summary, 'value', 'label'))->toBe([
            'Employees with deductions' => '1', 'Payslips with deductions' => '2', 'Selected deduction types' => '2', 'Amount deducted' => 'PHP 250.00',
        ]);
});

it('refuses an oversized source before a payroll summary can present a partial total', function () {
    $employee = payrollReportEmployee('Overflow');
    $rows = [];
    for ($i = 0; $i <= ReportDocument::MAX_ROWS; $i++) {
        $rows[] = ['id' => (string) Str::ulid(), 'employee_id' => $employee->id, 'run_id' => null, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'created_at' => '2026-09-16 00:00:00'];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        Payslip::query()->insert($chunk);
    }
    expect(fn () => app(PayrollReports::class)->generate(payrollReportActor(), payrollReportCriteria('payroll-summary')))
        ->toThrow(ValidationException::class, 'exceeds 5,000');
});
