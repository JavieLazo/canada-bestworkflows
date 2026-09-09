<?php

declare(strict_types=1);

use App\Livewire\Users\Index;
use Illuminate\Support\Facades\Route;

/*
 * USERS — User Management.
 *
 * The page sits behind the MODULE gate only, the way
 * `requirePageModule("USERS")` gated the whole segment
 * (src/app/(protected)/users/layout.tsx). The all_data half of the story is
 * per WRITE, not per page: every mutating action in App\Livewire\Users\Index
 * re-checks Access::mayReadAllData('USERS') for itself, mirroring the
 * `requireAllDataModule("USERS")` call at the top of every /api/accounts and
 * /api/roles write handler.
 *
 * Grant edits made on this screen take effect on the NEXT request: the
 * container's Access is scoped() and re-reads roles + role_module_grants per
 * request, so there is no cache for this module to invalidate.
 */
Route::middleware(['web', 'auth', 'module:USERS'])->group(static function (): void {
    Route::get('/users', Index::class)->name('users.index');
});
