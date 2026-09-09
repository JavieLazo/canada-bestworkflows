<?php

declare(strict_types=1);

namespace App\Livewire\Payroll;

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\Payslip;
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

        $run = PayrollRun::query()->whereKey($this->runId)->first();

        // What the run posted for this employee — the scoped read is what
        // keeps a payslip private to Payroll and its owner.
        $payslip = Payslip::query()
            ->toEmployee($actor, 'employee_id', ModuleCode::PAYROLL)
            ->where('run_id', $this->runId)
            ->where('employee_id', $this->employeeId)
            ->first();

        $employee = EmployeeProfile::query()->whereKey($this->employeeId)->first();

        return view('livewire.payroll.individual-payslip', [
            'run' => $run,
            'payslip' => $payslip,
            'employee' => $employee,
        ]);
    }
}
