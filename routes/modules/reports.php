<?php

declare(strict_types=1);

use App\Http\Controllers\TableExportController;
use App\Livewire\Reports\Home;
use App\Livewire\Reports\Index;
use Illuminate\Support\Facades\Route;

/** REPORTS grants navigation; each owner query also enforces its source grant and row scope.
 *  The literal export route sits before the report wildcard, which would otherwise take it. */
Route::middleware(['web', 'auth', 'module:REPORTS'])->group(static function (): void {
    Route::get('/reports', Home::class)->name('reports.index');
    Route::get('/reports/export', TableExportController::class)->defaults('table', 'reports')->name('reports.export');
    Route::get('/reports/{report}', Index::class)->name('reports.show');
});
