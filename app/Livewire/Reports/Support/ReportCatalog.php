<?php

declare(strict_types=1);

namespace App\Livewire\Reports\Support;

use App\Domain\Enums\ModuleCode;
use App\Modules\Payroll\Application\Queries\PayrollReports;
use App\Modules\Timekeeping\Application\Queries\TimekeepingReports;
use App\Modules\Workforce\Application\Queries\EmployeeReport;
use App\Platform\Audit\AuditReport;
use App\Platform\Reports\ReportCriteria;
use App\Platform\Reports\ReportDocument;
use App\Support\Access;

/** Presentation composes owner queries; the Reports grant never widens the source permissions. */
final class ReportCatalog
{
    /** The reports whose period is a payroll's: they open on the latest posted payroll. */
    public const array PAYROLL = ['payroll-register', 'payroll-summary', 'payslips', 'government-contributions', 'loan-summary'];

    /**
     * `lead` is the one plain line under a report's name: what the reader is looking at.
     *
     * @return array<string, array{title: string, lead: string, description: string, module: string, source: string, category: string, icon: string, period: string}>
     */
    public static function all(): array
    {
        return [
            'payroll-register' => ['title' => 'Payroll Register', 'lead' => 'What each employee was paid.', 'description' => 'Posted earnings, deductions and net pay by employee.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll', 'icon' => 'table-cells', 'period' => 'Posted payroll period end'],
            'payslips' => ['title' => 'Payslips', 'lead' => 'Each employee\'s payslip, ready to print.', 'description' => 'Individual statements with stored earnings and deductions.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll', 'icon' => 'document-text', 'period' => 'Posted payroll period end'],
            'employee-list' => ['title' => 'Employee List', 'lead' => 'Your employees, as their records stand today.', 'description' => 'Explore workforce composition, demographics, development, service and record quality, or build a detailed employee list.', 'module' => ModuleCode::EMPLOYEES, 'source' => 'Employee Records', 'category' => 'Workforce', 'icon' => 'users', 'period' => 'Current employee records'],
            'leave-reports' => ['title' => 'Leave Requests', 'lead' => 'Leave and business travel in the period.', 'description' => 'Requests overlapping the period and their approval status.', 'module' => ModuleCode::LEAVES, 'source' => 'Leave Management', 'category' => 'Leave', 'icon' => 'calendar-days', 'period' => 'Leave dates overlapping the period'],
            'attendance-reports' => ['title' => 'Attendance', 'lead' => 'Who was late, absent or on overtime.', 'description' => 'Analyze daily trends, group rates and exceptions, compare employees, or review individual daily sheets.', 'module' => ModuleCode::EDTR, 'source' => 'eDTR & Attendance', 'category' => 'Time & attendance', 'icon' => 'clock', 'period' => 'Attendance dates · up to 31 days'],
            'government-contributions' => ['title' => 'Government Contributions', 'lead' => 'What was deducted for SSS, PhilHealth, Pag-IBIG and tax, and what the company adds.', 'description' => 'Recorded SSS, PhilHealth, Pag-IBIG and withholding tax.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll', 'icon' => 'building-library', 'period' => 'Posted payroll period end'],
            'payroll-summary' => ['title' => 'Payroll Summary', 'lead' => 'What each payroll paid in total.', 'description' => 'Gross pay, deductions and net pay summarized by run.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll', 'icon' => 'chart-bar', 'period' => 'Posted payroll period end'],
            'loan-summary' => ['title' => 'Loan Summary', 'lead' => 'Loan repayments deducted from pay. Shows deductions, not balances.', 'description' => 'Recorded repayments for the deduction types you select.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll', 'icon' => 'banknotes', 'period' => 'Posted payroll period end'],
            'audit-logs' => ['title' => 'Audit Logs', 'lead' => 'Who did what, and when.', 'description' => 'Activity history within the selected Manila calendar dates.', 'module' => ModuleCode::AUDIT, 'source' => 'Audit Trail with organization-wide access', 'category' => 'Governance', 'icon' => 'shield-check', 'period' => 'Activity dates · Manila time'],
        ];
    }

    /**
     * The Reports home: every report by name, in three columns of headed groups.
     * A view of a report (Workforce Analysis) is listed beside the report it belongs to.
     *
     * @return list<list<array{heading: string, links: list<array{type: string, view: string, label: string}>}>>
     */
    public static function home(): array
    {
        $link = static fn (string $type, string $view = '', ?string $label = null): array => [
            'type' => $type, 'view' => $view, 'label' => $label ?? self::all()[$type]['title'],
        ];

        return [
            [['heading' => 'Payroll', 'links' => [
                $link('payroll-register'), $link('payroll-summary'), $link('payslips'),
                $link('government-contributions'), $link('loan-summary'),
            ]]],
            [
                ['heading' => 'Time & attendance', 'links' => [$link('attendance-reports')]],
                ['heading' => 'Leave', 'links' => [$link('leave-reports')]],
            ],
            [
                ['heading' => 'Workforce', 'links' => [$link('employee-list'), $link('employee-list', 'analysis', 'Workforce Analysis')]],
                ['heading' => 'Governance', 'links' => [$link('audit-logs')]],
            ],
        ];
    }

    /**
     * The row of links beside a report's name: its sibling reports, or its own views.
     *
     * @return list<array{type: string, view: string, label: string, current: bool}>
     */
    public static function switch(string $type, string $view): array
    {
        $links = match (true) {
            $type === 'attendance-reports' => [
                ['attendance-reports', 'summary', 'By employee'], ['attendance-reports', 'details', 'Daily sheets'],
                ['attendance-reports', 'analysis', 'Analysis'],
            ],
            $type === 'employee-list' => [['employee-list', 'summary', 'Employee List'], ['employee-list', 'analysis', 'Workforce Analysis']],
            in_array($type, self::PAYROLL, true) => [
                ['payroll-register', '', 'Register'], ['payroll-summary', '', 'Summary'], ['payslips', '', 'Payslips'],
                ['government-contributions', '', 'Government Contributions'], ['loan-summary', '', 'Loan Summary'],
            ],
            default => [],
        };

        return array_map(static fn (array $link): array => [
            'type' => $link[0], 'view' => $link[1], 'label' => $link[2],
            'current' => $link[0] === $type && ($link[1] === '' || $link[1] === $view),
        ], $links);
    }

    /** The name a printed sheet carries: a view with a name of its own prints under it. */
    public static function printTitle(string $type, string $view): string
    {
        return match (true) {
            $type === 'attendance-reports' && $view === 'details' => 'Daily Time Record',
            $type === 'attendance-reports' && $view === 'analysis' => 'Attendance Analysis',
            $type === 'employee-list' && $view === 'analysis' => 'Workforce Analysis',
            default => self::all()[$type]['title'] ?? 'Report',
        };
    }

    public static function allows(Access $actor, string $type): bool
    {
        $report = self::all()[$type] ?? null;

        return $actor->hasModule(ModuleCode::REPORTS) && $report !== null
            && ($type === 'audit-logs' ? $actor->mayReadAllData(ModuleCode::AUDIT) : $actor->hasModule($report['module']));
    }

    /** @return list<string> Source queries retain the final authorization and row scope. */
    public static function employeeScope(string $type): array
    {
        return match ($type) {
            'employee-list' => [ModuleCode::EMPLOYEES, ModuleCode::PAYROLL],
            'leave-reports' => [ModuleCode::LEAVES],
            'attendance-reports' => [ModuleCode::EDTR],
            default => [ModuleCode::PAYROLL],
        };
    }

    public static function generate(Access $actor, ReportCriteria $criteria): ReportDocument
    {
        abort_unless(self::allows($actor, $criteria->type), 403);

        return match ($criteria->type) {
            'employee-list' => app(EmployeeReport::class)->generate($actor, $criteria),
            'leave-reports', 'attendance-reports' => app(TimekeepingReports::class)->generate($actor, $criteria),
            'audit-logs' => app(AuditReport::class)->generate($actor, $criteria),
            default => app(PayrollReports::class)->generate($actor, $criteria),
        };
    }
}
