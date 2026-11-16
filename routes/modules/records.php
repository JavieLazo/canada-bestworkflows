<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Http\Controllers\TableExportController;
use App\Livewire\Records\AdjustmentCategoriesPage;
use App\Livewire\Records\DepartmentsPage;
use App\Livewire\Records\EmployeePaySettingsPage;
use App\Livewire\Records\EmployeePaySettingsShow;
use App\Livewire\Records\HolidaysPage;
use App\Livewire\Records\IncentiveTypesPage;
use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Livewire\Records\PayRulesPage;
use App\Livewire\Records\PaySchedulesPage;
use App\Livewire\Records\PositionsPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Records\SignatoriesPage;
use App\Livewire\Records\SitesPage;
use App\Livewire\Records\StatutoryTablesPage;
use App\Support\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/*
 * Records Management — the port of src/app/(protected)/records/.
 *
 * The page gate is RECORDS alone (records/layout.tsx:4
 * `requirePageModule("RECORDS")`). Writes are further gated INSIDE each
 * component or the command it calls (the Organization commands behind
 * Position and Departments & Units): every mutation needs `all_data` AND
 * RECORDS (`requireAllDataModule("RECORDS")`, api/records/* and
 * api/sites/*), which route middleware cannot express — the screens are
 * readable without all_data.
 *
 * Records Management has no landing screen of its own — `/records` goes to
 * the first list (records/page.tsx), exactly the child order the module
 * registry's `groups` lays out.
 */
/*
 * Payroll Settings — Employee Pay Settings, Contribution & Tax Tables, Pay
 * Basis & Rates, Payroll Deductions, Incentives & Bonuses and Signatories,
 * sharing one view switch — sit in this module's menu but stay PAYROLL's:
 * gated `module:PAYROLL`, NOT RECORDS, and written by all-data PAYROLL alone
 * (PayrollSettingsAccess). Holding Records opens none of them. The pages keep
 * their component classes and route names. Their earlier /records/… addresses
 * redirect under the same gate, so a Records holder without Payroll is turned
 * away here rather than at the target. The menu's own address has no page: it
 * leads to Employees, or to the tables for a holder who cannot read employee
 * pay.
 */
Route::middleware(['web', 'auth', 'module:PAYROLL'])->group(static function (): void {
    Route::get('/records/payroll-settings', static fn (Access $access): RedirectResponse => redirect()->route(
        $access->mayReadAllData(ModuleCode::PAYROLL) ? 'records.employee-pay-settings' : 'records.contribution-tables',
    ))->name('payroll.settings');
    Route::get('/records/payroll-settings/employees', EmployeePaySettingsPage::class)->name('records.employee-pay-settings');
    Route::get('/records/payroll-settings/employees/{employee}', EmployeePaySettingsShow::class)->name('records.employee-pay-settings.show');
    Route::get('/records/payroll-settings/contribution-tables', StatutoryTablesPage::class)->name('records.contribution-tables');
    Route::get('/records/payroll-settings/signatories', SignatoriesPage::class)->name('payroll.settings.signatories');
    Route::get('/records/payroll-settings/pay-schedules', PaySchedulesPage::class)->name('payroll.settings.schedules');
    Route::get('/records/payroll-settings/adjustment-categories', AdjustmentCategoriesPage::class)->name('payroll.settings.adjustment-categories');
    Route::get('/records/payroll-settings/pay-schedules/export', TableExportController::class)
        ->defaults('table', 'records.pay-schedules')
        ->name('records.pay-schedules.export');
    Route::get('/records/payroll-settings/pay-rates', PayRulesPage::class)->name('records.pay-rates');
    Route::get('/records/payroll-settings/payroll-deductions', ReferenceListPage::class)
        ->defaults('slug', ReferenceListPage::PAYROLL_LIST)
        ->name('payroll.settings.deductions');
    Route::get('/records/payroll-settings/incentive-types', IncentiveTypesPage::class)->name('records.incentive-types');
    // Their CSVs and import templates (TableExportController): the table key is a route default.
    Route::get('/records/payroll-settings/payroll-deductions/export', TableExportController::class)
        ->defaults('table', 'records.'.ReferenceListPage::PAYROLL_LIST)
        ->name('records.'.ReferenceListPage::PAYROLL_LIST.'.export');
    Route::get('/records/payroll-settings/incentive-types/export', TableExportController::class)
        ->defaults('table', 'records.incentive-types')
        ->name('records.incentive-types.export');

    Route::redirect('/records/contribution-tables', '/records/payroll-settings/contribution-tables');
    Route::redirect('/records/pay-rates', '/records/payroll-settings/pay-rates');
    Route::redirect('/records/payroll-deductions', '/records/payroll-settings/payroll-deductions');
    Route::redirect('/records/incentive-types', '/records/payroll-settings/incentive-types');
    Route::redirect('/records/employee-pay-settings', '/records/payroll-settings/employees');
    Route::redirect('/records/employee-pay-settings/{employee}', '/records/payroll-settings/employees/{employee}');
});

// Leave Settings: ONE page for the leave types — adding one with its rule
// and settings — and, on a tab of its own, the fixed minutes-to-days
// conversion table. Whoever manages Records OR leave opens it
// (LeaveSettingsAccess), so its gate names both; a literal route,
// registered before the `{slug}` list below. The list page the leave types
// once had at /records/leave-types leads here. THE LEAVE TYPES HAVE NO CSV.
Route::middleware(['web', 'auth', 'module:RECORDS,LEAVES'])->group(static function (): void {
    Route::get('/records/leave-credits', LeaveCreditSettingsPage::class)->name('records.leave-credits');
    Route::redirect('/records/leave-types', '/records/leave-credits');
});

Route::middleware(['web', 'auth', 'module:RECORDS'])->group(static function (): void {
    Route::redirect('/records', '/records/positions')->name('records.index');

    // Position and Departments & Units have pages of their own: literal
    // routes, so the `{slug}` list below never sees them. /records/positions
    // is still where /records lands.
    Route::get('/records/positions', PositionsPage::class)->name('records.positions');
    Route::get('/records/departments', DepartmentsPage::class)->name('records.departments');

    Route::get('/records/sites', SitesPage::class)->name('records.sites');
    Route::get('/records/shifts', ShiftTypesPage::class)->name('records.shifts');
    Route::get('/records/holidays', HolidaysPage::class)->name('records.holidays');

    // The holiday calendar's CSV and import template (TableExportController);
    // the table key is a route default, never the URL's. SHIFTING SETTINGS
    // AND SITE SETTINGS HAVE NO CSV, so /records/shifts and /records/sites
    // get no export route.
    Route::get('/records/holidays/export', TableExportController::class)
        ->defaults('table', 'records.holidays')
        ->name('records.holidays.export');

    // The plain reference lists share one screen, parameterised by slug —
    // the port of the thin page.tsx wrappers around <ReferenceList>. Payroll
    // Deductions uses the same screen under Payroll Settings, behind the
    // PAYROLL gate above, so this RECORDS-gated route must not serve it.
    Route::get('/records/{slug}', ReferenceListPage::class)
        ->whereIn('slug', array_keys(ReferenceListPage::recordsLists()))
        ->name('records.list');

    // The CSV (and import template, ?template=1) of each list that carries
    // one: a literal route per slug, so the table key is a route default,
    // never the URL's. DOCUMENT, EMPLOYMENT AND THE LEAVE TYPES HAVE NO CSV,
    // so they get no export route here; neither do Position and Departments
    // & Units. Payroll Deductions' route is under Payroll Settings.
    foreach (array_keys(array_intersect_key(ReferenceListPage::csvLists(), ReferenceListPage::recordsLists())) as $slug) {
        Route::get("/records/{$slug}/export", TableExportController::class)
            ->defaults('table', "records.{$slug}")
            ->name("records.{$slug}.export");
    }
});
