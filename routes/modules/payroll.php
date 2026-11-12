<?php

declare(strict_types=1);

use App\Domain\Enums\PayrollRunKind;
use App\Http\Controllers\TableExportController;
use App\Livewire\Payroll\Adjustments;
use App\Livewire\Payroll\Index;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Payroll\ThirteenthMonthWorkspace;
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
 * The sidebar splits Payroll in two: "Basic Payroll" is /payroll, and
 * "13th Month Payroll" is its own tree —
 *
 *   /payroll/thirteenth-month             the 13th month runs  (Index, kind THIRTEENTH_MONTH)
 *   /payroll/thirteenth-month/new         create one           (ThirteenthMonthWorkspace)
 *   /payroll/thirteenth-month/{id}        open one             (ThirteenthMonthWorkspace)
 *
 * A 13th month payslip opens at /payroll/thirteenth-month/{id}/payslip/{employeeId}
 * (the same IndividualPayslip, so the sidebar lights the yearly entry), and
 * still at /payroll/{id}/payslip/{employeeId}.
 *
 * Payroll Settings is in Records Management's menu, at
 * /records/payroll-settings (routes/modules/records.php), still behind this
 * PAYROLL gate; /payroll/settings and everything under it only forward there.
 *
 * The literal paths are registered before `/payroll/{id}`, and {id}
 * additionally refuses the literals "new", "thirteenth-month" and
 * "settings", so none can be captured as a run id. Ids are otherwise unconstrained: BaseModel
 * mints ULIDs but seeded demo rows carry friendly ids (run-1, emp-1). Each
 * workspace redirects a run of the other kind to its own screen.
 */
Route::middleware(['web', 'auth', 'module:PAYROLL'])->group(static function (): void {
    Route::get('/payroll', Index::class)->name('payroll.index');

    Route::get('/payroll/new', RunWorkspace::class)->name('payroll.new');

    // Each run list's CSV (TableExportController). Literal, and registered
    // before the {id} routes below, which would otherwise read "export" as a
    // run id; the kind is a route default, as the lists' own is.
    Route::get('/payroll/export', TableExportController::class)
        ->defaults('table', 'payroll')
        ->defaults('kind', PayrollRunKind::REGULAR)
        ->name('payroll.export');
    Route::get('/payroll/thirteenth-month/export', TableExportController::class)
        ->defaults('table', 'payroll')
        ->defaults('kind', PayrollRunKind::THIRTEENTH_MONTH)
        ->name('payroll.thirteenth.export');

    // The adjustment ledger and its CSV: literal, before /payroll/{id}.
    Route::get('/payroll/adjustments', Adjustments::class)->name('payroll.adjustments');
    Route::get('/payroll/adjustments/export', TableExportController::class)
        ->defaults('table', 'payroll.adjustments')
        ->name('payroll.adjustments.export');

    Route::get('/payroll/thirteenth-month', Index::class)
        ->defaults('kind', PayrollRunKind::THIRTEENTH_MONTH)
        ->name('payroll.thirteenth.index');

    Route::get('/payroll/thirteenth-month/new', ThirteenthMonthWorkspace::class)->name('payroll.thirteenth.new');

    Route::get('/payroll/thirteenth-month/{id}', ThirteenthMonthWorkspace::class)
        ->where('id', '(?!new$)[A-Za-z0-9\-]+')
        ->name('payroll.thirteenth.show');

    Route::get('/payroll/thirteenth-month/{id}/payslip/{employeeId}', IndividualPayslip::class)
        ->where('id', '(?!new$)[A-Za-z0-9\-]+')
        ->where('employeeId', '[A-Za-z0-9\-]+')
        ->name('payroll.thirteenth.payslip');

    // Payroll Settings' former addresses forward, so a bookmark keeps working.
    // Literal, and before /payroll/{id}.
    Route::redirect('/payroll/settings', '/records/payroll-settings');
    Route::redirect('/payroll/settings/{page}', '/records/payroll-settings/{page}')->where('page', '.*');

    Route::get('/payroll/{id}', RunWorkspace::class)
        ->where('id', '(?!new$)(?!thirteenth-month$)(?!settings$)(?!adjustments$)[A-Za-z0-9\-]+')
        ->name('payroll.show');

    Route::get('/payroll/{id}/payslip/{employeeId}', IndividualPayslip::class)
        ->where('id', '(?!new$)(?!thirteenth-month$)(?!settings$)(?!adjustments$)[A-Za-z0-9\-]+')
        ->where('employeeId', '[A-Za-z0-9\-]+')
        ->name('payroll.payslip');
});
