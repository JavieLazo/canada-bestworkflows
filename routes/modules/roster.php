<?php

declare(strict_types=1);

use App\Livewire\Roster\EmployeeSchedulePage;
use App\Livewire\Roster\RosterPage;
use Illuminate\Support\Facades\Route;

/*
 * Shift Roster — the week table, and each employee's month.
 *
 * The page gate is ROSTER alone (roster/layout.tsx:4
 * `requirePageModule("ROSTER")`). Writes are further gated INSIDE the
 * components and the commands: changing a schedule needs `all_data` AND
 * ROSTER (api/shifts/[employeeId]/route.ts:49
 * `requireAllDataModule("ROSTER")`), which route middleware cannot express —
 * the screens themselves are readable without all_data, they just show only
 * your own row. An employee outside the actor's directory is a 404.
 *
 * The roster has no CSV routes: no export, no import and no Scheduled
 * changes file.
 */
Route::middleware(['web', 'auth', 'module:ROSTER'])->group(static function (): void {
    Route::get('/roster', RosterPage::class)->name('roster.index');
    Route::get('/roster/{employee}', EmployeeSchedulePage::class)->name('roster.employee');
});
