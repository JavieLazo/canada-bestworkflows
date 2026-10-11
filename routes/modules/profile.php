<?php

declare(strict_types=1);

use App\Http\Controllers\Employees\RecordChangeEvidenceController;
use App\Livewire\Employees\ChangeRequests;
use App\Livewire\Profile\ProfilePage;
use Illuminate\Support\Facades\Route;

/* My Profile composes the linked record, own request history and account security. */
Route::middleware(['web', 'auth', 'module:PROFILE'])->group(static function (): void {
    Route::get('/profile', ProfilePage::class)->name('profile.index');
});

Route::middleware(['web', 'auth', 'module:PROFILE,EMPLOYEES'])->group(static function (): void {
    Route::get('/my-record/requests', ChangeRequests::class)->defaults('own', true)->name('profile.requests');
    Route::get('/record-changes/{request}/evidence/{which}', RecordChangeEvidenceController::class)
        ->where('which', 'before|after')
        ->name('record-changes.evidence');
});
