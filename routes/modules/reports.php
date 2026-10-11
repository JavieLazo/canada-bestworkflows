<?php

declare(strict_types=1);

use App\Http\Controllers\TableExportController;
use App\Livewire\Reports\Index;
use Illuminate\Support\Facades\Route;

/** REPORTS grants navigation; each owner query also enforces its source grant and row scope. */
Route::middleware(['web', 'auth', 'module:REPORTS'])->group(static function (): void {
    Route::get('/reports', Index::class)->name('reports.index');
    Route::get('/reports/export', TableExportController::class)->defaults('table', 'reports')->name('reports.export');
});
