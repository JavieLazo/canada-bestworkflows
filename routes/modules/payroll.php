<?php

declare(strict_types=1);

use App\Livewire\Payroll\Index;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Payroll\RunWorkspace;
use Illuminate\Support\Facades\Route;

/*
 * Payroll — /payroll (config/modules.php: PAYROLL).
 *
 * Four screens, exactly the reference's route tree under (protected)/payroll:
 *
 *   /payroll                              the run list        (payroll-page.tsx)
 *   /payroll/new                          create a payroll    (new/page.tsx → RunWorkspace, run = null)
 *   /payroll/{id}                         one run's workspace ([id]/run-detail.tsx → RunWorkspace)
 *   /payroll/{id}/payslip/{employeeId}    a posted payslip    (individual-payslip.tsx)
 *
 * Route middleware is the FIRST of three layers — the reference's
 * requirePageModule("PAYROLL") layout. Every write action re-checks
 * `mayReadAllData(PAYROLL)` for itself, and the payslip reads go through
 * ScopesToActor on PAYROLL ALONE (api/payroll/payslips/route.ts:19-22).
 *
 * `/payroll/new` is registered before `/payroll/{id}` and {id} additionally
 * refuses the literal "new", so it can never be captured as a run id. Ids are
 * otherwise unconstrained: BaseModel mints ULIDs but seeded demo rows carry
 * friendly ids (run-1, emp-1).
 */
Route::middleware(['web', 'auth', 'module:PAYROLL'])->group(static function (): void {
    Route::get('/payroll', Index::class)->name('payroll.index');

    Route::get('/payroll/new', RunWorkspace::class)->name('payroll.new');

    Route::get('/payroll/{id}', RunWorkspace::class)
        ->where('id', '(?!new$)[A-Za-z0-9\-]+')
        ->name('payroll.show');

    Route::get('/payroll/{id}/payslip/{employeeId}', IndividualPayslip::class)
        ->where('id', '(?!new$)[A-Za-z0-9\-]+')
        ->where('employeeId', '[A-Za-z0-9\-]+')
        ->name('payroll.payslip');
});
