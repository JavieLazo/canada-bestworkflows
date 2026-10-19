<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Models\EmployeeProfile;
use App\Models\PayrollDeduction;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\PayslipIncentive;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Support\Access;
use Livewire\Livewire;

/**
 * Every printable payslip uses company branding and issued figures, including
 * historical line names and legacy deductions, without repricing current pay.
 */
function payslipDocumentEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'INTERNAL-PAYSLIP-DOCUMENT', 'employee_id_no' => 'COP-DOCUMENT-001',
        'first_name' => 'Alexandria', 'last_name' => 'Statement', 'position' => 'Operations Coordinator',
        'date_hired' => '2024-01-01', 'employment_status' => 'REGULAR', 'is_active' => true,
        'monthly_rate' => 20_000, 'daily_rate' => 812.34,
    ])->save();

    return $employee;
}

function payslipDocumentActor(EmployeeProfile $employee): void
{
    $role = new Role;
    $role->forceFill(['name' => 'Payslip document reader', 'all_data' => true, 'is_system' => false])->save();
    foreach ([ModuleCode::PAYROLL, ModuleCode::PORTAL, ModuleCode::REPORTS] as $module) {
        (new RoleModuleGrant)->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]));
    app()->forgetInstance(Access::class);
}

function payslipDocumentRun(bool $yearly = false): PayrollRun
{
    $run = new PayrollRun;
    $run->forceFill([
        'code' => 'PR-DOCUMENT-001', 'name' => 'Issued statement fixture',
        'period_start' => $yearly ? '2026-01-01' : '2026-09-01',
        'period_end' => $yearly ? '2026-12-31' : '2026-09-15',
        'status' => PayrollRunStatus::FINALIZED,
        'kind' => $yearly ? PayrollRunKind::THIRTEENTH_MONTH : PayrollRunKind::REGULAR,
    ])->save();

    return $run;
}

/** Render through each screen's real authorized read path, including the Portal own-row selection. */
function payslipDocumentHtml(string $surface, PayrollRun $run, Payslip $payslip): string
{
    return match ($surface) {
        'Payroll' => Livewire::test(IndividualPayslip::class, ['id' => $run->id, 'employeeId' => $payslip->employee_id])->html(),
        'Portal' => Livewire::test(PortalPage::class)->set('tab', 'payslips')->call('viewPayslip', $payslip->id)->html(),
        'Reports' => Livewire::test(ReportsIndex::class)->call('selectReport', 'payslips')
            ->set('from', $payslip->period_start->format('Y-m-d'))->set('to', $payslip->period_end->format('Y-m-d'))
            ->call('generate')->assertHasNoErrors()->html(),
        default => throw new InvalidArgumentException('Unknown payslip surface.'),
    };
}

function payslipDocumentText(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

it('renders branded issued pay and historical lines without using current salary or catalog names', function (string $surface) {
    config(['copems.company_name' => 'Fixture Company']);
    $employee = payslipDocumentEmployee();
    payslipDocumentActor($employee);
    $run = payslipDocumentRun();
    $payslip = new Payslip;
    $payslip->forceFill([
        'run_id' => $run->id, 'employee_id' => $employee->id,
        'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
        'daily_rate' => 812.34, 'work_days' => 1, 'holiday_days' => 0.5, 'rest_days' => 0.5,
        'work_days_pay' => 700.11, 'holiday_pay' => 200, 'rest_day_pay' => 100,
        'basic_pay' => 1000.11, 'allowances' => 80.22, 'total_base_pay' => 1080.33,
        'overtime_hours' => 3, 'overtime_pay' => 777.77, 'overtime_acknowledged' => false,
        'night_differential' => 60.66, 'leave_pay' => 40.44, 'other_earnings' => 50.55,
        'bonus' => 25, 'exempt_benefits' => 75.77, 'taxable_benefits_excess' => 25,
        'gross_pay' => 1332.75,
        'sss' => 10.01, 'philhealth' => 20.02, 'pagibig' => 30.03, 'withholding_tax' => 40.04,
        'other_deductions' => 50.05, 'custom_deductions' => 60.06,
        'late_minutes' => 7, 'late_deduction' => 70.07, 'undertime_minutes' => 8, 'undertime_deduction' => 80.08,
        'late_undertime_deduction' => 90.09, 'absence_deduction' => 100.10, 'unpaid_leave_deduction' => 110.11,
        'total_deductions' => 660.66, 'net_pay' => 672.09,
        'sss_er' => 111.12, 'philhealth_er' => 222.23, 'pagibig_er' => 333.34,
    ])->save();
    (new PayslipDeduction)->forceFill([
        'payslip_id' => $payslip->id, 'position' => 0, 'deduction_code' => 'SNAP-LOAN',
        'name' => 'Issued equipment installment', 'amount' => 60.06,
    ])->save();
    (new PayslipIncentive)->forceFill([
        'payslip_id' => $payslip->id, 'position' => 0, 'type_code' => 'SNAP-BONUS',
        'name' => 'Issued service award', 'is_taxable' => false, 'amount' => 100.77, 'over_ceiling' => 25,
    ])->save();
    (new PayrollDeduction)->forceFill(['code' => 'SNAP-LOAN', 'name' => 'Current renamed repayment'])->save();
    (new PayrollIncentiveType)->forceFill(['code' => 'SNAP-BONUS', 'name' => 'Current renamed bonus', 'is_taxable' => true])->save();
    $employee->forceFill(['monthly_rate' => 987654.32, 'daily_rate' => 5432.19])->save();
    $issued = $payslip->refresh()->getRawOriginal();

    $html = payslipDocumentHtml($surface, $run, $payslip);
    $text = payslipDocumentText($html);
    preg_match('/<article[^>]*class="[^"]*hris-payslip[^"]*"[^>]*>(.*?)<\/article>/s', $html, $document);
    $printed = payslipDocumentText($document[1] ?? '');

    expect($html)->toContain('bestworkflows-horizontal-view.png')
        ->and($text)->toContain('Fixture Company', 'COP-DOCUMENT-001', 'Alexandria Statement', 'Payroll ID PR-DOCUMENT-001')
        ->toContain('812.34', '1,332.75', '660.66', '672.09')
        ->toContain('Issued equipment installment', '60.06', 'Issued service award', '100.77')
        ->toContain('Legacy late / undertime deduction', '90.09', 'Legacy absence deduction', '100.10', 'Legacy unpaid leave deduction', '110.11')
        // One needle per negation: negated together they pass when any ONE is absent.
        ->not->toContain('987,654.32')->not->toContain('5,432.19')
        ->not->toContain('Current renamed repayment')->not->toContain('Current renamed bonus');

    // Charged legacy rows must survive printing, not just screen-only details.
    expect($printed)->toContain('Legacy late / undertime deduction', '90.09', 'Legacy absence deduction', '100.10', 'Legacy unpaid leave deduction', '110.11');

    if ($surface === 'Portal') {
        // My Portal is the employee's copy: the payslip alone. The additional
        // details (employer contributions among them) stay on Payroll and Reports.
        expect($text)->not->toContain('777.77')
            ->not->toContain('Additional payroll details')->not->toContain('Employer contributions')
            ->not->toContain('111.12')->not->toContain('222.23')->not->toContain('333.34')
            ->and($html)->not->toContain('hris-payslip-details')->not->toContain('data-employer-shares');
    } else {
        expect($text)->toContain('777.77', 'Additional payroll details', 'Employer contributions (not deducted)', '111.12', '222.23', '333.34')
            ->and(mb_strtolower($text))->toContain('not approved');
    }
    expect($payslip->refresh()->getRawOriginal())->toEqual($issued);
})->with(['Payroll' => ['Payroll'], 'Portal' => ['Portal'], 'Reports' => ['Reports']]);

it('shows yearly pay and tax treatment without an attendance basis on every payslip surface', function (string $surface) {
    config(['copems.company_name' => 'Fixture Company']);
    $employee = payslipDocumentEmployee();
    payslipDocumentActor($employee);
    $run = payslipDocumentRun(yearly: true);
    $payslip = new Payslip;
    $payslip->forceFill([
        'run_id' => $run->id, 'employee_id' => $employee->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        'thirteenth_month' => 12345.67, 'gross_pay' => 12345.67,
        'exempt_benefits' => 9000, 'taxable_benefits_excess' => 3345.67,
        'withholding_tax' => 345.67, 'total_deductions' => 345.67, 'net_pay' => 12000,
    ])->save();

    $html = payslipDocumentHtml($surface, $run, $payslip);
    $text = payslipDocumentText($html);

    expect($html)->toContain('bestworkflows-horizontal-view.png')
        ->and($text)->toContain('Fixture Company', '13th month pay', '12,345.67', '345.67', '12,000.00')
        ->not->toContain('Work days', 'Absences', 'Daily rate', 'Time and rate details');
    // Every surface keeps the posted exemption split, never a split of the
    // current rate; grouping separators only change how the amount is read.
    expect(str_replace(',', '', $text))->toContain('9000.00', '3345.67');
})->with(['Payroll' => ['Payroll'], 'Portal' => ['Portal'], 'Reports' => ['Reports']]);
