<?php

declare(strict_types=1);

use App\Http\Controllers\InventoryAttachmentDownloadController;
use App\Http\Controllers\InventoryReceiptPrintController;
use App\Http\Controllers\TableExportController;
use App\Livewire\Inventory;
use Illuminate\Support\Facades\Route;

/* Inventory uses existing role grants; commands and queries enforce management and row scope. */
Route::middleware(['web', 'auth', 'module:INVENTORY'])->group(static function (): void {
    foreach (['items', 'receipts', 'incidents', 'disposals', 'counts', 'reports'] as $table) {
        Route::get("/inventory/{$table}/export", TableExportController::class)
            ->defaults('table', "inventory.{$table}")->name("inventory.{$table}.export");
    }
    Route::get('/inventory', Inventory\ItemsPage::class)->name('inventory.items');
    Route::get('/inventory/items/{id}', Inventory\ItemPage::class)->name('inventory.items.show');
    Route::get('/inventory/receipts', Inventory\ReceiptsPage::class)->name('inventory.receipts');
    Route::get('/inventory/receipts/{id}/print', InventoryReceiptPrintController::class)->name('inventory.receipts.print');
    Route::get('/inventory/receipts/{id}', Inventory\ReceiptPage::class)->name('inventory.receipts.show');
    Route::get('/inventory/attachments/{id}', InventoryAttachmentDownloadController::class)->name('inventory.attachments.download');
    Route::get('/inventory/incidents', Inventory\IncidentsPage::class)->name('inventory.incidents');
    Route::get('/inventory/disposals', Inventory\DisposalsPage::class)->name('inventory.disposals');
    Route::get('/inventory/counts', Inventory\CountsPage::class)->name('inventory.counts');
    Route::get('/inventory/reports', Inventory\ReportsPage::class)->name('inventory.reports');
    Route::get('/inventory/catalogs', Inventory\CatalogsPage::class)->name('inventory.catalogs');
});
