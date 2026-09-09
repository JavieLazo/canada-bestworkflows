<?php

declare(strict_types=1);

use App\Livewire\Leaves\Index;
use Illuminate\Support\Facades\Route;

/*
 * Leave Management — /leaves (config/modules.php: LEAVES).
 *
 * One screen: the request table with its review dialog. Filing and
 * withdrawing requests belong to My Portal (PORTAL), not here, so this file
 * registers exactly the page the module's sidebar entry points at.
 *
 * Route middleware is the FIRST of three layers: the review action re-checks
 * `mayReadAllData(LEAVES)` for itself, and every read goes through
 * `ScopesToActor::toEmployee` — see the Access docblock.
 */
Route::middleware(['web', 'auth', 'module:LEAVES'])->group(static function (): void {
    Route::get('/leaves', Index::class)->name('leaves.index');
});
