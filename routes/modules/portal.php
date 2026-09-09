<?php

declare(strict_types=1);

use App\Livewire\Portal\PortalPage;
use Illuminate\Support\Facades\Route;

/*
 * My Portal — /portal (config/modules.php: code PORTAL, path /portal).
 *
 * One page, three tabs: the self-service punch station beside "My punches",
 * leave/official-business filing over the caller's own request table, and the
 * caller's posted payslips. The gate is the PORTAL module alone, exactly as
 * `requirePageModule("PORTAL")` gated the reference page; every read inside is
 * additionally narrowed to the session's own employee (see PortalPage), so
 * the route grant never widens what the page can show.
 *
 * `?tab=requests` / `?tab=payslips` deep-link into a tab — the notification
 * bell's hrefs land here.
 */
Route::middleware(['web', 'auth', 'module:PORTAL'])->group(static function (): void {
    Route::get('/portal', PortalPage::class)->name('portal.index');
});
