<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Payroll\Support\PayslipSheet;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Models\EmployeeProfile;
use App\Models\OrgNode;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Workforce\Contracts\EmployeeReportContext;
use App\Support\Access;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A payslip prints on its employee's month: every regular cut-off posted in
 * that month, a footing of their posted figures and nothing from anyone else,
 * read through the same scope as the payslip that was opened.
 */
function pmsEmployee(string $number, string $lastName, ?string $positionId = null): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'PMS-'.$number, 'employee_id_no' => 'PMS-NO-'.$number,
        'first_name' => 'Alex', 'last_name' => $lastName, 'position' => 'Analyst', 'position_id' => $positionId,
        'date_hired' => '2024-01-01', 'employment_status' => 'REGULAR', 'is_active' => true, 'monthly_rate' => 20_000,
    ])->save();

    return $employee;
}

/** @param list<string> $modules */
function pmsActor(?EmployeeProfile $employee, array $modules = [ModuleCode::PAYROLL, ModuleCode::PORTAL, ModuleCode::REPORTS], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Month sheet '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        (new RoleModuleGrant)->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    test()->actingAs(User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]));
    app()->forgetInstance(Access::class);

    return app(Access::class);
}

/** @param array<string, mixed> $extra */
function pmsCutoff(EmployeeProfile $employee, string $start, string $end, float $net, string $kind = PayrollRunKind::REGULAR, array $extra = []): Payslip
{
    $run = new PayrollRun;
    $run->forceFill([
        'code' => 'PMS-'.Str::random(10), 'name' => 'Month sheet run '.Str::random(10), 'period_start' => $start, 'period_end' => $end,
        'status' => PayrollRunStatus::FINALIZED, 'kind' => $kind,
    ])->save();
    $payslip = new Payslip;
    $payslip->forceFill([
        'run_id' => $run->id, 'employee_id' => $employee->id, 'period_start' => $start, 'period_end' => $end,
        'gross_pay' => $net + 100, 'total_deductions' => 100, 'net_pay' => $net, ...$extra,
    ])->save();

    return $payslip;
}

function pmsHtml(string $surface, Payslip $payslip, string $from = '2026-09-01', string $to = '2026-09-30'): string
{
    return match ($surface) {
        'Payroll' => Livewire::test(IndividualPayslip::class, ['id' => $payslip->run_id, 'employeeId' => $payslip->employee_id])->html(),
        'Portal' => Livewire::test(PortalPage::class)->set('tab', 'payslips')->call('viewPayslip', $payslip->id)->html(),
        'Reports' => Livewire::test(ReportsIndex::class)->call('selectReport', 'payslips')->set('from', $from)->set('to', $to)->call('generate')->assertHasNoErrors()->html(),
        default => throw new InvalidArgumentException('Unknown payslip surface.'),
    };
}

/** @return list<string> each printed sheet's text-bearing markup */
function pmsSheets(string $html): array
{
    preg_match_all('/<article[^>]*class="[^"]*hris-payslip[^"]*"[^>]*>.*?<\/article>/s', $html, $matches);

    return $matches[0];
}

function pmsText(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/* |---| The month |---| */

it('prints the employee\'s posted cut-offs of the month together with their footing', function (string $surface) {
    $mine = pmsEmployee('1', 'Holder');
    $colleague = pmsEmployee('2', 'Colleague');
    pmsActor($mine);
    pmsCutoff($mine, '2026-09-01', '2026-09-15', 1111.11);
    $second = pmsCutoff($mine, '2026-09-16', '2026-09-30', 2222.22);
    pmsCutoff($mine, '2026-08-16', '2026-08-31', 7777.77);
    pmsCutoff($colleague, '2026-09-01', '2026-09-15', 8888.88);

    $sheets = array_values(array_filter(pmsSheets(pmsHtml($surface, $second)), static fn (string $sheet): bool => str_contains($sheet, 'PMS-NO-1')));
    expect($sheets)->toHaveCount(1);
    $text = pmsText($sheets[0]);

    expect(substr_count($sheets[0], 'data-payslip-slip'))->toBe(2)
        ->and($text)->toContain('For the month of SEPTEMBER 2026', 'SEPTEMBER 1–15, 2026', '1st half', 'SEPTEMBER 16–30, 2026', '2nd half', 'Total for the Month')
        // The footing: 1,211.11 + 2,322.22 earnings, 100 + 100 deductions, 1,111.11 + 2,222.22 net.
        ->toContain('3,533.33', '200.00', '3,333.33')
        ->not->toContain('7,777.77')
        ->not->toContain('8,888.88');
})->with(['Payroll' => ['Payroll'], 'Portal' => ['Portal'], 'Reports' => ['Reports']]);

it('prints only what has posted when the month is not finished', function (string $surface) {
    $mine = pmsEmployee('3', 'Halfway');
    pmsActor($mine);
    $first = pmsCutoff($mine, '2026-09-01', '2026-09-15', 1111.11);

    $sheets = pmsSheets(pmsHtml($surface, $first));
    expect($sheets)->toHaveCount(1)
        ->and(substr_count($sheets[0], 'data-payslip-slip'))->toBe(1)
        ->and(pmsText($sheets[0]))->toContain('SEPTEMBER 1–15, 2026', 'Total for the Month')->not->toContain('SEPTEMBER 16–30, 2026');
})->with(['Payroll' => ['Payroll'], 'Portal' => ['Portal'], 'Reports' => ['Reports']]);

it('keeps a sheet to the dates a report selected', function () {
    $mine = pmsEmployee('4', 'Selected');
    pmsActor($mine);
    pmsCutoff($mine, '2026-09-01', '2026-09-15', 1111.11);
    $second = pmsCutoff($mine, '2026-09-16', '2026-09-30', 2222.22);

    $sheets = pmsSheets(pmsHtml('Reports', $second, '2026-09-16', '2026-09-30'));
    expect($sheets)->toHaveCount(1)
        ->and(pmsText($sheets[0]))->toContain('2,222.22')->not->toContain('1,111.11');
});

it('gives a report one sheet per employee and month, and a 13th month slip its own', function () {
    $mine = pmsEmployee('5', 'Reported');
    $colleague = pmsEmployee('6', 'Beside');
    pmsActor($mine);
    $first = pmsCutoff($mine, '2026-09-01', '2026-09-15', 1000);
    pmsCutoff($mine, '2026-09-16', '2026-09-30', 1000);
    pmsCutoff($mine, '2026-10-01', '2026-10-15', 1000);
    pmsCutoff($colleague, '2026-09-01', '2026-09-15', 1000);
    pmsCutoff($mine, '2026-01-01', '2026-12-31', 5000, PayrollRunKind::THIRTEENTH_MONTH, ['thirteenth_month' => 5100]);

    // Sept and Oct for one, Sept for the other, and the year's own slip.
    expect(pmsSheets(pmsHtml('Reports', $first, '2026-01-01', '2026-12-31')))->toHaveCount(4);
});

it('leaves a 13th month payslip out of the month it falls in', function () {
    $mine = pmsEmployee('7', 'Yearly');
    pmsActor($mine);
    $regular = pmsCutoff($mine, '2026-12-01', '2026-12-15', 1111.11);
    pmsCutoff($mine, '2026-12-16', '2026-12-31', 5555.55, PayrollRunKind::THIRTEENTH_MONTH, ['thirteenth_month' => 5655.55]);

    expect(pmsText(pmsSheets(pmsHtml('Payroll', $regular))[0]))->toContain('1,111.11')->not->toContain('5,555.55');
});

/* |---| Who sees it |---| */

it('reads the month through the same scope as the payslip', function () {
    $mine = pmsEmployee('8', 'Scoped');
    $colleague = pmsEmployee('9', 'Foreign');
    pmsCutoff($mine, '2026-09-01', '2026-09-15', 1000);
    $foreign = pmsCutoff($colleague, '2026-09-01', '2026-09-15', 2000);

    $manager = pmsActor(null);
    $view = app(PayslipRecords::class)->find($manager, (string) $foreign->run_id, $colleague->id);
    assert($view !== null);

    expect(app(PayslipRecords::class)->month($manager, $view))->toHaveCount(1);
    // A linked employee without all-data Payroll, and an unlinked account, read none of it.
    expect(app(PayslipRecords::class)->month(pmsActor($mine, [ModuleCode::PAYROLL, ModuleCode::PORTAL], false), $view))->toHaveCount(0)
        ->and(app(PayslipRecords::class)->month(pmsActor(null, [ModuleCode::PAYROLL], false), $view))->toHaveCount(0);
});

it('shows the department to a reader of the payslip and refuses anyone else', function () {
    $department = new OrgNode;
    $department->forceFill(['code' => 'PMS-D', 'kind' => 'DEPARTMENT', 'name' => 'Finance Department'])->save();
    $position = new Position;
    $position->forceFill(['code' => 'PMS-P', 'name' => 'Analyst', 'org_node_id' => $department->id])->save();
    $mine = pmsEmployee('10', 'Placed', $position->id);
    $colleague = pmsEmployee('11', 'Other', $position->id);
    $payslip = pmsCutoff($mine, '2026-09-01', '2026-09-15', 1000);

    pmsActor($mine);
    foreach (['Payroll', 'Portal', 'Reports'] as $surface) {
        expect(pmsText(pmsSheets(pmsHtml($surface, $payslip))[0]))->toContain('Finance Department');
    }

    $own = pmsActor($mine, [ModuleCode::PAYROLL], false);
    expect(app(EmployeeReportContext::class)->payrollDepartments($own, [$mine->id, $colleague->id]))->toBe([$mine->id => 'Finance Department']);

    $outsider = pmsActor($mine, [ModuleCode::PORTAL]);
    expect(fn () => app(EmployeeReportContext::class)->payrollDepartments($outsider, [$mine->id]))->toThrow(HttpException::class);
});

/* |---| The lines |---| */

it('files issued deductions under the group their name belongs to', function () {
    $mine = pmsEmployee('12', 'Grouped');
    $access = pmsActor($mine);
    $payslip = pmsCutoff($mine, '2026-09-01', '2026-09-15', 1000);
    $names = [
        'Provident Fund Loan', 'Accounts Receivable – Employee', 'A/R – SSS Loan', 'Pag-IBIG II', 'Landbank SLF', 'Pag-IBIG MPL', 'Employee Sample Fee',
    ];
    foreach ($names as $index => $name) {
        (new PayslipDeduction)->forceFill(['payslip_id' => $payslip->id, 'position' => $index, 'deduction_code' => 'PMS-'.$index, 'name' => $name, 'amount' => 10])->save();
    }

    $view = app(PayslipRecords::class)->find($access, (string) $payslip->run_id, $mine->id);
    assert($view !== null);
    $sheet = PayslipSheet::issued($access, $view, $mine, false, '');
    $groups = [];
    foreach ($sheet['slips'][0]['deductionGroups'] as $group) {
        $groups[$group['title']] = array_column($group['lines'], 'label');
    }

    expect($groups['Tax & Premiums'])->toContain('SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax')
        ->and($groups['Provident Fund & Receivables'])->toBe(['Provident Fund Loan', 'Accounts Receivable – Employee'])
        ->and($groups['Loans'])->toBe(['A/R – SSS Loan', 'Pag-IBIG II', 'Landbank SLF', 'Pag-IBIG MPL'])
        // "mpl" inside a longer word is not a loan.
        ->and($groups['Other Deductions'])->toContain('Employee Sample Fee');
});

it('prints a line with no amount as a dash and always keeps it', function (string $surface) {
    $mine = pmsEmployee('13', 'Zero');
    pmsActor($mine);
    $payslip = pmsCutoff($mine, '2026-09-01', '2026-09-15', 1000);
    $html = pmsHtml($surface, $payslip);

    expect($html)->toContain('>–</td>')
        ->not->toContain('Hide zero lines')
        ->not->toContain('hideZero');
})->with(['Payroll' => ['Payroll'], 'Portal' => ['Portal'], 'Reports' => ['Reports']]);

/* |---| On paper |---| */

it('keeps the sheet white in every theme', function (string $surface) {
    $mine = pmsEmployee('15', 'Paper');
    pmsActor($mine);
    $payslip = pmsCutoff($mine, '2026-09-01', '2026-09-15', 1000);
    $css = str_replace("\r\n", "\n", (string) file_get_contents(resource_path('css/payslip.css')));
    preg_match('/^\.hris-payslip \{(.*?)^\}/ms', $css, $sheet);

    expect(pmsSheets(pmsHtml($surface, $payslip))[0])->toContain('hris-always-light')
        // The aliases were resolved at :root, so a dark root would reach the sheet through them.
        ->and($sheet[1] ?? '')->toContain('--color-card: var(--card)', '--color-foreground: var(--foreground)', '--color-primary: var(--primary)');
})->with(['Payroll' => ['Payroll'], 'Portal' => ['Portal'], 'Reports' => ['Reports']]);

it('prints the sheet alone on an unmarked page', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(resource_path('css/payslip.css')));
    preg_match('/@page payslip \{(.*?)\}/s', $css, $page);

    // With no page margin the browser has nowhere to print its date, title and address.
    expect($page[1] ?? '')->toContain('size: A4 portrait', 'margin: 0')
        // Everything but the payslip's own branch is removed, at any depth.
        ->and($css)->toContain('*:not(:has(.payslip-print)):not(.payslip-print):not(.payslip-print *)')
        // A short sheet leaves paper below it, which a dark theme must not colour.
        ->toContain("html:has(.payslip-print:not(dialog:not([open]) *)) body {\n        background: oklch(1 0 0) !important;");
});

it('adds a tax refund to the net pay line so the sheet still foots', function (string $surface) {
    $mine = pmsEmployee('14', 'Refunded');
    pmsActor($mine);
    $payslip = pmsCutoff($mine, '2026-12-16', '2026-12-31', 1500.00, extra: ['gross_pay' => 1100, 'total_deductions' => 100, 'tax_refund' => 500]);

    expect(pmsText(pmsSheets(pmsHtml($surface, $payslip, '2026-12-16', '2026-12-31'))[0]))->toContain('Add: Tax Refund500.00');
})->with(['Payroll' => ['Payroll'], 'Portal' => ['Portal'], 'Reports' => ['Reports']]);

it('prints no Notes block, only the summary', function (string $surface) {
    $mine = pmsEmployee('16', 'Noteless');
    pmsActor($mine);
    $payslip = pmsCutoff($mine, '2026-09-01', '2026-09-15', 1000);

    expect(pmsSheets(pmsHtml($surface, $payslip))[0])->toContain('data-month-summary')
        ->not->toContain('hris-payslip-note')
        ->not->toContain('Report any discrepancy')
        ->not->toContain('Net Pay = Total Earnings');
})->with(['Payroll' => ['Payroll'], 'Portal' => ['Portal'], 'Reports' => ['Reports']]);
