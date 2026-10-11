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
    /** @return array<string, array{title: string, description: string, module: string, source: string, category: string, icon: string, period: string}> */
    public static function all(): array
    {
        return [
            'payroll-register' => ['title' => 'Payroll Register', 'description' => 'Posted earnings, deductions and net pay by employee.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll & finance', 'icon' => 'table-cells', 'period' => 'Posted payroll period end'],
            'payslips' => ['title' => 'Payslips', 'description' => 'Individual statements with stored earnings and deductions.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll & finance', 'icon' => 'document-text', 'period' => 'Posted payroll period end'],
            'employee-list' => ['title' => 'Employee List & Workforce Analysis', 'description' => 'Explore workforce composition, demographics, development, service and record quality, or build a detailed employee list.', 'module' => ModuleCode::EMPLOYEES, 'source' => 'Employee Records', 'category' => 'Workforce', 'icon' => 'users', 'period' => 'Current employee records'],
            'leave-reports' => ['title' => 'Leave Reports', 'description' => 'Requests overlapping the period and their approval status.', 'module' => ModuleCode::LEAVES, 'source' => 'Leave Management', 'category' => 'Time & leave', 'icon' => 'calendar-days', 'period' => 'Leave dates overlapping the period'],
            'attendance-reports' => ['title' => 'Attendance Reports', 'description' => 'Analyze daily trends, group rates and exceptions, compare employees, or review individual daily sheets.', 'module' => ModuleCode::EDTR, 'source' => 'eDTR & Attendance', 'category' => 'Time & leave', 'icon' => 'clock', 'period' => 'Attendance dates · up to 31 days'],
            'government-contributions' => ['title' => 'Government Contribution Reports', 'description' => 'Recorded SSS, PhilHealth, Pag-IBIG and withholding tax.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll & finance', 'icon' => 'building-library', 'period' => 'Posted payroll period end'],
            'payroll-summary' => ['title' => 'Payroll Summary', 'description' => 'Gross pay, deductions and net pay summarized by run.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll & finance', 'icon' => 'chart-bar', 'period' => 'Posted payroll period end'],
            'loan-summary' => ['title' => 'Loan Summary', 'description' => 'Recorded repayments for the deduction types you select.', 'module' => ModuleCode::PAYROLL, 'source' => 'Payroll', 'category' => 'Payroll & finance', 'icon' => 'banknotes', 'period' => 'Posted payroll period end'],
            'audit-logs' => ['title' => 'Audit Logs', 'description' => 'Activity history within the selected Manila calendar dates.', 'module' => ModuleCode::AUDIT, 'source' => 'Audit Trail with organization-wide access', 'category' => 'Governance', 'icon' => 'shield-check', 'period' => 'Activity dates · Manila time'],
        ];
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
