<?php

declare(strict_types=1);

use App\Livewire\Profile\ProfilePage;
use Illuminate\Support\Facades\Route;

/*
 * PROFILE — My Profile.
 *
 * One page behind the module gate, the way `requirePageModule("PROFILE")`
 * gated the segment (src/app/(protected)/profile/). Everyone holds PROFILE,
 * and the module is deliberately absent from the sidebar nav — the identity
 * block in the shell footer is the way in (config/modules.php).
 *
 * There is nothing else to route: the page reads the caller's own account and
 * employee record only, so there are no downloads, exports or detail pages.
 */
Route::middleware(['web', 'auth', 'module:PROFILE'])->group(static function (): void {
    Route::get('/profile', ProfilePage::class)->name('profile.index');
});
