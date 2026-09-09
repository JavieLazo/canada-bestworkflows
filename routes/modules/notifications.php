<?php

declare(strict_types=1);

use App\Livewire\Notifications\Index;
use Illuminate\Support\Facades\Route;

/*
 * NOTIFICATIONS — the full inbox the bell's "Show all notifications" link
 * lands on.
 *
 * DELIBERATELY NO `module:` GATE, exactly as the reference: "No `layout.tsx`
 * here, deliberately. Every other route segment gates on a module grant, but
 * an inbox is not a module — every signed-in account has one, and the parent
 * (protected) layout already turns away anyone without a session.
 * `moduleForPath` returns null for this path, which that layout reads as 'no
 * module to check'" (src/app/(protected)/notifications/page.tsx:15-21).
 *
 * There is accordingly no NOTIFICATIONS code in ModuleCode / config/modules.php
 * — `Access::assertKnown()` would throw on one — and no role can hold a grant
 * for it. Adding a module gate here would lock every account out of its own
 * inbox, including the bell's link the shell already renders. The data is
 * scoped instead: every read and action in the component goes through the
 * session account's own rows and nothing else.
 */
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::get('/notifications', Index::class)->name('notifications.index');
});
