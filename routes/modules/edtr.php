<?php

declare(strict_types=1);

use App\Http\Controllers\TableExportController;
use App\Livewire\Edtr\EdtrPage;
use Illuminate\Support\Facades\Route;

/*
 * eDTR & Attendance — /edtr (config/modules.php: code EDTR, path /edtr).
 *
 * One page, two tabs: the punch station beside the live feed, and the
 * computed Daily Time Record. The gate is the EDTR module alone, exactly as
 * `requirePageModule("EDTR")` gated the reference page; the widened
 * behaviours inside (kiosk punching, remarks, corrections) re-check
 * `all_data` per action, never at the route.
 */
Route::middleware(['web', 'auth', 'module:EDTR'])->group(static function (): void {
    Route::get('/edtr', EdtrPage::class)->name('edtr.index');
    // The live feed's CSV (TableExportController). The Daily Time Record has
    // no export: its computed days are read on the tab, never downloaded.
    Route::get('/edtr/feed/export', TableExportController::class)
        ->defaults('table', 'edtr.feed')
        ->name('edtr.feed.export');
});
