<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Enums\PayrollRunKind;
use App\Livewire\Payroll\Support\PayslipSheet;
use App\Models\Payslip;
use App\Modules\Payroll\Application\Queries\PayrollRecords;
use App\Modules\Payroll\Application\Queries\PayslipRecords;
use App\Modules\Workforce\Contracts\EmployeeReportContext;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One employee's payslip out of a finalized payroll — the port of
 * payroll/[id]/payslip/[employeeId]/individual-payslip.tsx.
 *
 * Every figure on the page is a stored column of the posted payslip; nothing
 * is recomputed here. The payslip read is scoped through ScopesToActor on
 * PAYROLL ALONE — "all_data alone (a supervisor who sees the roster, say) is
 * not a license to read salaries" (api/payroll/payslips/route.ts:19-21).
 */
#[Layout('layouts.app')]
#[Title('Payslip')]
class IndividualPayslip extends Component
{
    /** Route params. Id-bearing → #[Locked]. */
    #[Locked]
    public string $runId = '';

    #[Locked]
    public string $employeeId = '';

    public function mount(string $id, string $employeeId): void
    {
        $this->runId = $id;
        $this->employeeId = $employeeId;
    }

    public function render(): View
    {
        $actor = app(Access::class);

        $run = app(PayrollRecords::class)->find($actor, $this->runId);

        // What the run posted for this employee — the scoped read is what
        // keeps a payslip private to Payroll and its owner.
        $payslip = app(PayslipRecords::class)->find($actor, $this->runId, $this->employeeId);

        $employee = app(OperationalDirectory::class)->find($actor, $this->employeeId);

        // A 13th month payslip has its own, shorter layout.
        $yearly = ($run->kind ?? PayrollRunKind::REGULAR) === PayrollRunKind::THIRTEENTH_MONTH;

        return view('livewire.payroll.individual-payslip', [
            'run' => $run,
            'payslip' => $payslip,
            'employee' => $employee,
            'yearly' => $yearly,
            // The month's posted cut-offs on one sheet, issued lines as they posted.
            'sheet' => $payslip === null ? null : PayslipSheet::issued(
                $actor, $payslip, $employee, $yearly,
                app(EmployeeReportContext::class)->payrollDepartments($actor, [$this->employeeId])[$this->employeeId] ?? '',
                $run->name ?? '',
            ),
        ]);
    }
}
