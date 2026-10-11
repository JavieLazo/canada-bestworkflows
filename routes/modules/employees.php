<?php

declare(strict_types=1);

use App\Http\Controllers\Employees\DocumentDownloadController;
use App\Http\Controllers\TableExportController;
use App\Livewire\Employees\ChangeRequests;
use App\Livewire\Employees\History;
use App\Livewire\Employees\Index;
use App\Livewire\Employees\Organization;
use App\Livewire\Employees\Show;
use Illuminate\Support\Facades\Route;

/*
 * EMPLOYEES — Employee Records.
 *
 * Employee pages, their change history and the Organization view sit behind
 * the module gate, the way `requirePageModule("EMPLOYEES")` gated the whole
 * segment (src/app/(protected)/employees/layout.tsx).
 *
 * Document downloads require EMPLOYEES and all-data management access. The
 * owner query enforces the same rule as the document list and export.
 */
Route::middleware(['web', 'auth', 'module:EMPLOYEES'])->group(static function (): void {
    Route::get('/employees', Index::class)->name('employees.index');
    // Literal paths (history, organization, export) must precede the
    // employee-id wildcard.
    Route::get('/employees/change-requests', ChangeRequests::class)->name('employees.change-requests');
    Route::get('/employees/history', History::class)->name('employees.history');
    Route::get('/employees/history/export', TableExportController::class)
        ->defaults('table', 'employees.history')
        ->name('employees.history.export');
    Route::get('/employees/organization', Organization::class)->name('employees.organization');
    // The list's CSV (TableExportController; export only, no import),
    // registered before /employees/{employee} so the wildcard cannot
    // swallow "export".
    Route::get('/employees/export', TableExportController::class)
        ->defaults('table', 'employees')
        ->name('employees.export');
    Route::get('/employees/{employee}/inventory/export', TableExportController::class)
        ->defaults('table', 'employees.inventory')->defaults('own', '0')->name('employees.inventory.export');
    Route::get('/employees/{employee}', Show::class)->name('employees.show');
    // One record's Documents card as CSV — the list, never the files.
    Route::get('/employees/{employee}/documents/export', TableExportController::class)
        ->defaults('table', 'employees.documents')
        ->name('employees.documents.export');
});

Route::middleware(['web', 'auth', 'module:EMPLOYEES'])->group(static function (): void {
    Route::get('/employees/documents/{document}/download', DocumentDownloadController::class)
        ->name('employees.documents.download');
});
