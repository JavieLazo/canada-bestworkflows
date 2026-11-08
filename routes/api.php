<?php

declare(strict_types=1);

use App\Http\Controllers\Mobile\EdtrKioskController;
use App\Http\Middleware\AuthenticateEdtrMobile;
use Illuminate\Support\Facades\Route;

/*
 * Biometric eDTR kiosk. The phone holds the face and fingerprint templates.
 * These routes only sign in a System Admin, list staff to register, and
 * accept Time In / Time Out rows the device captured — including offline.
 */
Route::prefix('mobile/edtr')->group(static function (): void {
    Route::post('login', [EdtrKioskController::class, 'login'])
        ->middleware('throttle:8,1')
        ->name('mobile.edtr.login');

    Route::middleware(AuthenticateEdtrMobile::class.':admin')->group(static function (): void {
        Route::post('logout', [EdtrKioskController::class, 'logout'])->name('mobile.edtr.logout');
        Route::get('employees', [EdtrKioskController::class, 'employees'])->name('mobile.edtr.employees');
    });

    Route::post('punches', [EdtrKioskController::class, 'punches'])
        ->middleware(AuthenticateEdtrMobile::class.':kiosk')
        ->name('mobile.edtr.punches');
});
