<?php

declare(strict_types=1);

use App\Http\Controllers\TableExportController;
use App\Livewire\Leaves\Index;
use Illuminate\Support\Facades\Route;

/*
 * Leave Management — /leaves (config/modules.php: LEAVES).
 *
 * One page: requests, annual Leave Credits, and the manual Leave Ledger
 * (`?tab=credits` and `?tab=ledger` deep-link to their tabs).
 * Filing and withdrawing requests
 * belong to My Portal (PORTAL), not here, so this file registers exactly the
 * page the module's sidebar entry points at.
 *
 * Route middleware is the FIRST of three layers: the review action and the
 * credit commands (SaveLeaveCredits and RecordLeaveCredit) each re-check
 * `mayReadAllData(LEAVES)` for themselves, and every read goes through
 * `ScopesToActor::toEmployee` — see the Access docblock.
 */
Route::middleware(['web', 'auth', 'module:LEAVES'])->group(static function (): void {
    Route::get('/leaves', Index::class)->name('leaves.index');
    // Each tab's CSV (TableExportController): the requests, and the credits
    // (export only, no import).
    Route::get('/leaves/export', TableExportController::class)
        ->defaults('table', 'leaves')
        ->name('leaves.export');
    Route::get('/leaves/credits/export', TableExportController::class)
        ->defaults('table', 'leaves.credits')
        ->name('leaves.credits.export');
    Route::get('/leaves/ledger/export', TableExportController::class)
        ->defaults('table', 'leaves.ledger')
        ->name('leaves.ledger.export');
});
