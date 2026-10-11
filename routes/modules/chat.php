<?php

declare(strict_types=1);

use App\Http\Controllers\Chat\MediaController;
use App\Livewire\Chat\ChatPage;
use Illuminate\Support\Facades\Route;

/*
 * Chat: one-to-one and group conversations (config/modules.php: code CHAT,
 * path /chat).
 *
 * Gated on the module like every other screen, so a role can be denied chat
 * without being denied the Community feed, or the reverse. Inside it, being
 * in a conversation is the whole permission model (ChatScope), and the media
 * routes (photos, files, a group chat's photo) answer 404 to anyone who is
 * not.
 *
 * `?c=<id>` opens that conversation; notifications link there.
 */
Route::middleware(['web', 'auth', 'module:CHAT'])->group(static function (): void {
    Route::get('/chat', ChatPage::class)->name('chat.index');
    Route::get('/chat/photos/{photo}', [MediaController::class, 'photo'])->name('chat.photos');
    Route::get('/chat/files/{file}', [MediaController::class, 'file'])->name('chat.files');
    Route::get('/chat/conversations/{conversation}/photo', [MediaController::class, 'conversationPhoto'])->name('chat.conversations.photo');
});
