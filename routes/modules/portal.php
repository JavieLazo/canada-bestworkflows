<?php

declare(strict_types=1);

use App\Http\Controllers\InventoryAttachmentDownloadController;
use App\Http\Controllers\InventoryReceiptPrintController;
use App\Livewire\Inventory\PortalReceiptPage;
use App\Livewire\Portal\PortalPage;
use Illuminate\Support\Facades\Route;

/*
 * My Portal — /portal (config/modules.php: code PORTAL, path /portal).
 *
 * One page with the self-service punch station beside "My punches",
 * leave/official-business filing over the caller's own request table (with
 * the caller's own leave credits for the year above the form and a
 * read-only leave ledger), posted payslips and issued inventory.
 * The gate is the PORTAL module alone, exactly as
 * `requirePageModule("PORTAL")` gated the reference page; every read inside is
 * additionally narrowed to the session's own employee (see PortalPage), so
 * the route grant never widens what the page can show.
 *
 * `?tab=requests` / `?tab=payslips` deep-link into a tab — the notification
 * bell's hrefs land here.
 */
Route::middleware(['web', 'auth', 'module:PORTAL'])->group(static function (): void {
    Route::get('/portal', PortalPage::class)->name('portal.index');
    Route::get('/portal/inventory/receipts/{id}/print', InventoryReceiptPrintController::class)
        ->defaults('own', '1')->name('portal.inventory.receipts.print');
    Route::get('/portal/inventory/receipts/{id}', PortalReceiptPage::class)
        ->name('portal.inventory.receipts.show');
    Route::get('/portal/inventory/attachments/{id}', InventoryAttachmentDownloadController::class)
        ->defaults('own', '1')->name('portal.inventory.attachments.download');
});
