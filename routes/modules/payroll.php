<?php

declare(strict_types=1);

use App\Http\Controllers\TableExportController;
use App\Livewire\Payroll\Adjustments;
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
 * Every run opens at /payroll/{id}, a finalized 13th month payroll an
 * installation already holds included, and every payslip at
 * /payroll/{id}/payslip/{employeeId}.
 *
 * Payroll Settings is in Records Management's menu, at
 * /records/payroll-settings (routes/modules/records.php), still behind this
 * PAYROLL gate; /payroll/settings and everything under it only forward there.
 *
 * The literal paths are registered before `/payroll/{id}`, and {id}
 * additionally refuses the literals "new", "settings" and "adjustments", so
 * none can be captured as a run id. Ids are otherwise unconstrained: BaseModel
 * mints ULIDs but seeded demo rows carry friendly ids (run-1, emp-1).
 */
Route::middleware(['web', 'auth', 'module:PAYROLL'])->group(static function (): void {
    Route::get('/payroll', Index::class)->name('payroll.index');

    Route::get('/payroll/new', RunWorkspace::class)->name('payroll.new');

    // The run list's CSV (TableExportController). Literal, and registered
    // before the {id} routes below, which would otherwise read "export" as a
    // run id.
    Route::get('/payroll/export', TableExportController::class)
        ->defaults('table', 'payroll')
        ->name('payroll.export');

    // The adjustment ledger and its CSV: literal, before /payroll/{id}.
    Route::get('/payroll/adjustments', Adjustments::class)->name('payroll.adjustments');
    Route::get('/payroll/adjustments/export', TableExportController::class)
        ->defaults('table', 'payroll.adjustments')
        ->name('payroll.adjustments.export');

    // Payroll Settings' former addresses forward, so a bookmark keeps working.
    // Literal, and before /payroll/{id}.
    Route::redirect('/payroll/settings', '/records/payroll-settings');
    Route::redirect('/payroll/settings/{page}', '/records/payroll-settings/{page}')->where('page', '.*');

    Route::get('/payroll/{id}', RunWorkspace::class)
        ->where('id', '(?!new$)(?!settings$)(?!adjustments$)[A-Za-z0-9\-]+')
        ->name('payroll.show');

    Route::get('/payroll/{id}/payslip/{employeeId}', IndividualPayslip::class)
        ->where('id', '(?!new$)(?!settings$)(?!adjustments$)[A-Za-z0-9\-]+')
        ->where('employeeId', '[A-Za-z0-9\-]+')
        ->name('payroll.payslip');
});
