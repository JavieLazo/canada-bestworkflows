<?php

declare(strict_types=1);

use App\Http\Controllers\Mobile\EdtrKioskController;
use App\Http\Middleware\AuthenticateEdtrMobile;
use Illuminate\Support\Facades\Route;

/*
 * Biometric eDTR kiosk. The device holds the face and fingerprint templates.
 * These routes only sign in a System Admin, list staff to register and the
 * sites a device can stand at, and accept Time In / Time Out rows the device
 * captured — including offline.
 *
 * ONE API FOR EVERY BIOMETRIC DEVICE: a phone app, a tablet's web portal and
 * any later reader speak these same routes. They sit outside the `web`
 * group on purpose — a device has no browser session — so each request is
 * authorised by its own bearer secret and the account behind it is read
 * again every time.
 */
Route::prefix('mobile/edtr')->group(static function (): void {
    Route::post('login', [EdtrKioskController::class, 'login'])
        ->middleware('throttle:8,1')
        ->name('mobile.edtr.login');

    Route::middleware(AuthenticateEdtrMobile::class.':admin')->group(static function (): void {
        Route::post('logout', [EdtrKioskController::class, 'logout'])->name('mobile.edtr.logout');
        Route::get('employees', [EdtrKioskController::class, 'employees'])->name('mobile.edtr.employees');
        Route::get('sites', [EdtrKioskController::class, 'sites'])->name('mobile.edtr.sites');
    });

    Route::post('punches', [EdtrKioskController::class, 'punches'])
        ->middleware(AuthenticateEdtrMobile::class.':kiosk')
        ->name('mobile.edtr.punches');
});
