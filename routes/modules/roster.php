<?php

declare(strict_types=1);

use App\Livewire\Roster\RosterPage;
use Illuminate\Support\Facades\Route;

/*
 * Shift Roster — the port of src/app/(protected)/roster/.
 *
 * The page gate is ROSTER alone (roster/layout.tsx:4
 * `requirePageModule("ROSTER")`). Writes are further gated INSIDE the
 * component: assigning a pattern needs `all_data` AND ROSTER
 * (api/shifts/[employeeId]/route.ts:49 `requireAllDataModule("ROSTER")`),
 * which route middleware cannot express — the screen itself is readable
 * without all_data, it just shows only your own row.
 */
Route::middleware(['web', 'auth', 'module:ROSTER'])->group(static function (): void {
    Route::get('/roster', RosterPage::class)->name('roster.index');
});
