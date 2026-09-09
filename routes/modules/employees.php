<?php

declare(strict_types=1);

use App\Http\Controllers\Employees\DocumentDownloadController;
use App\Livewire\Employees\Index;
use App\Livewire\Employees\Show;
use Illuminate\Support\Facades\Route;

/*
 * EMPLOYEES — Employee Records.
 *
 * The two pages sit behind the module gate, the way
 * `requirePageModule("EMPLOYEES")` gated the whole segment
 * (src/app/(protected)/employees/layout.tsx).
 *
 * The document download is gated by AUTH ONLY plus an in-controller ownership
 * check, mirroring `GET /api/employees/documents/[id]`: an employee may fetch
 * a file off their OWN 201 file (their profile screen links here) without
 * holding the Employees module; anyone else needs `all_data` + EMPLOYEES.
 */
Route::middleware(['web', 'auth', 'module:EMPLOYEES'])->group(static function (): void {
    Route::get('/employees', Index::class)->name('employees.index');
    Route::get('/employees/{employee}', Show::class)->name('employees.show');
});

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/employees/documents/{document}/download', DocumentDownloadController::class)
        ->name('employees.documents.download');
});
