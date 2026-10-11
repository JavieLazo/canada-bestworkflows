<?php

declare(strict_types=1);

use App\Http\Controllers\TableExportController;
use App\Livewire\Audit\Index;
use Illuminate\Support\Facades\Route;

/*
 * AUDIT — the Audit Trail, a single read-only screen.
 *
 * The page gates on the AUDIT module alone, exactly as the protected shell
 * resolved /audit → AUDIT (module-access.ts / config/modules.php). The
 * DATA carries the stricter gate: `GET /api/audit` demanded
 * `requireAllDataModule("AUDIT")`, and the component's render() reproduces
 * that — an AUDIT-holding actor without the all-data scope reaches the page
 * and reads zero rows, which is what the reference showed them (the API
 * 403'd and the table rendered empty). `module.all:AUDIT` on the route
 * would instead redirect that actor to their landing module — which, for a
 * role whose first module IS AUDIT, is /audit again: a redirect loop. So
 * the module gate lives here and the all-data gate lives on the read.
 */
Route::middleware(['web', 'auth', 'module:AUDIT'])->group(static function (): void {
    Route::get('/audit', Index::class)->name('audit.index');
    // The trail's CSV (TableExportController) — the same all-data read, so
    // a module-only actor downloads the empty table they see.
    Route::get('/audit/export', TableExportController::class)
        ->defaults('table', 'audit')
        ->name('audit.export');
});
