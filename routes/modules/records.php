<?php

declare(strict_types=1);

use App\Livewire\Records\FormulasPage;
use App\Livewire\Records\HolidaysPage;
use App\Livewire\Records\ReferenceListPage;
use App\Livewire\Records\ShiftTypesPage;
use App\Livewire\Records\SitesPage;
use Illuminate\Support\Facades\Route;

/*
 * Records Management — the port of src/app/(protected)/records/.
 *
 * The page gate is RECORDS alone (records/layout.tsx:4
 * `requirePageModule("RECORDS")`). Writes are further gated INSIDE each
 * component: every mutation needs `all_data` AND RECORDS
 * (`requireAllDataModule("RECORDS")`, api/records/* and api/sites/*), which
 * route middleware cannot express — the screens are readable without
 * all_data.
 *
 * Records Management has no landing screen of its own — `/records` goes to
 * the first list (records/page.tsx), exactly the child order the module
 * registry's `groups` lays out.
 */
Route::middleware(['web', 'auth', 'module:RECORDS'])->group(static function (): void {
    Route::redirect('/records', '/records/positions')->name('records.index');

    Route::get('/records/sites', SitesPage::class)->name('records.sites');
    Route::get('/records/shifts', ShiftTypesPage::class)->name('records.shifts');
    Route::get('/records/holidays', HolidaysPage::class)->name('records.holidays');
    Route::get('/records/payroll-formulas', FormulasPage::class)->name('records.payroll-formulas');

    // The five plain reference lists share one screen, parameterised by slug —
    // the port of the five thin page.tsx wrappers around <ReferenceList>.
    Route::get('/records/{slug}', ReferenceListPage::class)
        ->whereIn('slug', array_keys(ReferenceListPage::LISTS))
        ->name('records.list');
});
