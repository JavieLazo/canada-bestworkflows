<?php

/*
 * The starter kit's account settings: auth-only, no module gate, no
 * AuditService. Profile (login email), Security (password, 2FA, passkeys)
 * and Appearance. Provisioning and deleting accounts is Users\Index's job and
 * self-service credentials are Profile\ProfilePage's — read the
 * auth-and-credentials guide before adding a write here. There is no
 * delete-account modal: the starter kit's one skips the checks in
 * Users\Index::deleteAccount.
 */

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
