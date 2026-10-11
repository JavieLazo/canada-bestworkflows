<?php

declare(strict_types=1);

use App\Http\Controllers\TableExportController;
use App\Livewire\Records\DepartmentsPage;
use App\Livewire\Records\HolidaysPage;
use App\Livewire\Records\IncentiveTypesPage;
use App\Livewire\Records\PayRulesPage;
use App\Livewire\Records\PositionsPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Records\SitesPage;
use App\Livewire\Records\StatutoryTablesPage;
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
 * Incentives & Bonuses is the one Records page payroll officers need, and
 * they hold no Records grant: RECORDS OR PAYROLL. Its writes need all-data
 * in either, checked in the Payroll module's commands. A literal path, so
 * the `{slug}` list below never sees it.
 */
Route::middleware(['web', 'auth', 'module:RECORDS,PAYROLL'])->group(static function (): void {
    Route::get('/records/incentive-types', IncentiveTypesPage::class)->name('records.incentive-types');
    // Its CSV and import template (TableExportController): the table key is a route default.
    Route::get('/records/incentive-types/export', TableExportController::class)
        ->defaults('table', 'records.incentive-types')
        ->name('records.incentive-types.export');
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
    Route::get('/records/contribution-tables', StatutoryTablesPage::class)->name('records.contribution-tables');
    Route::get('/records/pay-rates', PayRulesPage::class)->name('records.pay-rates');

    // Each table's CSV and import template (TableExportController); the table
    // key is a route default, never the URL's.
    foreach (['sites', 'shifts', 'holidays'] as $page) {
        Route::get("/records/{$page}/export", TableExportController::class)
            ->defaults('table', "records.{$page}")
            ->name("records.{$page}.export");
    }

    // The four plain reference lists share one screen, parameterised by slug —
    // the port of the thin page.tsx wrappers around <ReferenceList>.
    Route::get('/records/{slug}', ReferenceListPage::class)
        ->whereIn('slug', array_keys(ReferenceListPage::LISTS))
        ->name('records.list');

    // The CSV (and import template, ?template=1) of each list that carries
    // one: a literal route per slug, so the table key is a route default,
    // never the URL's. DOCUMENT AND EMPLOYMENT HAVE NO CSV, so they get no
    // export route here; neither do Position and Departments & Units.
    foreach (array_keys(ReferenceListPage::csvLists()) as $slug) {
        Route::get("/records/{$slug}/export", TableExportController::class)
            ->defaults('table', "records.{$slug}")
            ->name("records.{$slug}.export");
    }
});
