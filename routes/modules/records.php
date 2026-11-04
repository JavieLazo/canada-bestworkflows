<?php

declare(strict_types=1);

use App\Http\Controllers\TableExportController;
use App\Livewire\Records\DepartmentsPage;
use App\Livewire\Records\HolidaysPage;
use App\Livewire\Records\LeaveCreditSettingsPage;
use App\Livewire\Records\PositionsPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Records\SitesPage;
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
 * Payroll Settings — Contribution & Tax Tables, Pay Basis & Rates, Payroll
 * Deductions, Incentives & Bonuses and Employee Pay Settings — live under
 * Payroll (routes/modules/payroll.php, /payroll/settings/…), behind
 * `module:PAYROLL`, and are written by all-data PAYROLL alone
 * (PayrollSettingsAccess). Their former Records addresses only redirect
 * there, so a bookmark keeps working, under the same gate: a Records
 * holder without Payroll is turned away here rather than at the target.
 */
Route::middleware(['web', 'auth', 'module:PAYROLL'])->group(static function (): void {
    Route::redirect('/records/contribution-tables', '/payroll/settings/contribution-tables');
    Route::redirect('/records/pay-rates', '/payroll/settings/pay-rates');
    Route::redirect('/records/payroll-deductions', '/payroll/settings/payroll-deductions');
    Route::redirect('/records/incentive-types', '/payroll/settings/incentive-types');
    Route::redirect('/records/employee-pay-settings', '/payroll/settings/employees');
    Route::redirect('/records/employee-pay-settings/{employee}', '/payroll/settings/employees/{employee}');
});

// Leave Credits: each leave type's credit rules, the minutes-to-days
// conversion tables and the policy presets. Whoever manages Leave Settings
// OR leave opens it (LeaveSettingsAccess), so its gate names both; a
// literal route, registered before the `{slug}` list below.
Route::middleware(['web', 'auth', 'module:RECORDS,LEAVES'])->group(static function (): void {
    Route::get('/records/leave-credits', LeaveCreditSettingsPage::class)->name('records.leave-credits');
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

    // Each table's CSV and import template (TableExportController); the table
    // key is a route default, never the URL's.
    foreach (['sites', 'shifts', 'holidays'] as $page) {
        Route::get("/records/{$page}/export", TableExportController::class)
            ->defaults('table', "records.{$page}")
            ->name("records.{$page}.export");
    }

    // The plain reference lists share one screen, parameterised by slug —
    // the port of the thin page.tsx wrappers around <ReferenceList>. Payroll
    // Deductions uses the same screen under Payroll → Payroll Settings, so
    // it is not a Records address.
    Route::get('/records/{slug}', ReferenceListPage::class)
        ->whereIn('slug', array_keys(ReferenceListPage::recordsLists()))
        ->name('records.list');

    // The CSV (and import template, ?template=1) of each list that carries
    // one: a literal route per slug, so the table key is a route default,
    // never the URL's. DOCUMENT AND EMPLOYMENT HAVE NO CSV, so they get no
    // export route here; neither do Position and Departments & Units.
    foreach (array_keys(array_intersect_key(ReferenceListPage::csvLists(), ReferenceListPage::recordsLists())) as $slug) {
        Route::get("/records/{$slug}/export", TableExportController::class)
            ->defaults('table', "records.{$slug}")
            ->name("records.{$slug}.export");
    }
});
