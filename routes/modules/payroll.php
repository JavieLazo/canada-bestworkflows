<?php

declare(strict_types=1);

use App\Domain\Enums\PayrollRunKind;
use App\Http\Controllers\TableExportController;
use App\Livewire\Payroll\Adjustments;
use App\Livewire\Payroll\Index;
use App\Livewire\Payroll\IndividualPayslip;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Payroll\SettingsOverview;
use App\Livewire\Payroll\ThirteenthMonthWorkspace;
use App\Livewire\Records\AdjustmentCategoriesPage;
use App\Livewire\Records\EmployeePaySettingsPage;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Livewire\Records\IncentiveTypesPage;
use App\Livewire\Records\PayRulesPage;
use App\Livewire\Records\PaySchedulesPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\SignatoriesPage;
use App\Livewire\Records\StatutoryTablesPage;
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
 * Payroll Settings is the third entry, /payroll/settings: an overview, then
 * Employee Pay Settings (/employees, /employees/{employee}), Contribution &
 * Tax Tables, Pay Basis & Rates, Payroll Deductions and Incentives &
 * Bonuses. Their writes check for themselves: one employee's settings,
 * rates and recurring lines need all-data PAYROLL, the company-wide ones
 * PayrollSettingsAccess (all-data PAYROLL or RECORDS).
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

    // Payroll Settings: an overview, then the five pages that shape every
    // payroll, sharing one view switch. Literal, and before /payroll/{id}.
    // The pages keep their Records component classes and route names; only
    // the addresses moved (the old ones redirect, routes/modules/records.php).
    Route::get('/payroll/settings', SettingsOverview::class)->name('payroll.settings');
    Route::get('/payroll/settings/employees', EmployeePaySettingsPage::class)->name('records.employee-pay-settings');
    Route::get('/payroll/settings/employees/{employee}', EmployeePaySettingsShow::class)->name('records.employee-pay-settings.show');
    Route::get('/payroll/settings/contribution-tables', StatutoryTablesPage::class)->name('records.contribution-tables');
    Route::get('/payroll/settings/signatories', SignatoriesPage::class)->name('payroll.settings.signatories');
    Route::get('/payroll/settings/pay-schedules', PaySchedulesPage::class)->name('payroll.settings.schedules');
    Route::get('/payroll/settings/adjustment-categories', AdjustmentCategoriesPage::class)->name('payroll.settings.adjustment-categories');
    Route::get('/payroll/settings/pay-schedules/export', TableExportController::class)
        ->defaults('table', 'records.pay-schedules')
        ->name('records.pay-schedules.export');
    Route::get('/payroll/settings/pay-rates', PayRulesPage::class)->name('records.pay-rates');
    Route::get('/payroll/settings/payroll-deductions', ReferenceListPage::class)
        ->defaults('slug', ReferenceListPage::PAYROLL_LIST)
        ->name('payroll.settings.deductions');
    Route::get('/payroll/settings/incentive-types', IncentiveTypesPage::class)->name('records.incentive-types');
    // Their CSVs and import templates (TableExportController): the table key is a route default.
    Route::get('/payroll/settings/payroll-deductions/export', TableExportController::class)
        ->defaults('table', 'records.'.ReferenceListPage::PAYROLL_LIST)
        ->name('records.'.ReferenceListPage::PAYROLL_LIST.'.export');
    Route::get('/payroll/settings/incentive-types/export', TableExportController::class)
        ->defaults('table', 'records.incentive-types')
        ->name('records.incentive-types.export');

    Route::get('/payroll/{id}', RunWorkspace::class)
        ->where('id', '(?!new$)(?!thirteenth-month$)(?!settings$)(?!adjustments$)[A-Za-z0-9\-]+')
        ->name('payroll.show');

    Route::get('/payroll/{id}/payslip/{employeeId}', IndividualPayslip::class)
        ->where('id', '(?!new$)(?!thirteenth-month$)(?!settings$)(?!adjustments$)[A-Za-z0-9\-]+')
        ->where('employeeId', '[A-Za-z0-9\-]+')
        ->name('payroll.payslip');
});
